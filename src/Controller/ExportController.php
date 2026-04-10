<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Controller;

use DH\Auditor\Attribute\Security;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\AuditorBundle\Helper\UrlHelper;
use DH\AuditorBundle\Tests\Controller\ExportControllerTest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @see ExportControllerTest
 */
#[AsController]
final readonly class ExportController
{
    private const array VALID_FORMATS = ['ndjson', 'json', 'csv'];

    #[Route(path: '/audit/export', name: 'dh_auditor_export', methods: ['GET'])]
    public function exportAction(Request $request, Reader $reader): StreamedResponse
    {
        $format = $request->query->getString('format', 'ndjson');

        if (!\in_array($format, self::VALID_FORMATS, true)) {
            throw new BadRequestHttpException(\sprintf("Invalid format '%s'. Allowed formats: %s.", $format, implode(', ', self::VALID_FORMATS)));
        }

        $entityParam = $request->query->getString('entity', '');
        $entity = '' !== $entityParam ? UrlHelper::paramToNamespace($entityParam) : null;

        $fromParam = $request->query->getString('from', '');
        $toParam = $request->query->getString('to', '');

        $from = '' !== $fromParam ? $this->parseDate($fromParam, 'from') : null;
        $to = '' !== $toParam ? $this->parseDate($toParam, 'to') : null;

        $id = $request->query->getString('id', '');
        $blameId = $request->query->getString('blame_id', '');
        $anonymize = $request->query->getBoolean('anonymize', false);

        $entities = $this->resolveEntities($entity, $reader);

        $contentType = match ($format) {
            'ndjson' => 'application/x-ndjson',
            'json' => 'application/json',
            'csv' => 'text/csv; charset=UTF-8',
        };

        $filenameBase = null !== $entity
            ? str_replace('\\', '_', $entity)
            : 'all';

        $extension = $format;
        $filename = \sprintf('audit-export-%s-%s.%s', $filenameBase, new \DateTimeImmutable()->format('Ymd-His'), $extension);

        $timezone = new \DateTimeZone($reader->getProvider()->getAuditor()->getConfiguration()->timezone);

        return new StreamedResponse(
            function () use ($reader, $entities, $id, $blameId, $from, $to, $format, $anonymize, $timezone): void {
                $first = true;
                $headerWritten = false;

                if ('json' === $format) {
                    echo '[';
                }

                foreach ($entities as $entityFqcn) {
                    try {
                        $query = $reader->createQuery($entityFqcn, ['page_size' => null]);
                    } catch (InvalidArgumentException) {
                        continue;
                    }

                    if ('' !== $id) {
                        $query->addFilter(new SimpleFilter(Query::OBJECT_ID, $id));
                    }

                    if ('' !== $blameId) {
                        $query->addFilter(new SimpleFilter(Query::USER_ID, $blameId));
                    }

                    if ($from instanceof \DateTimeImmutable || $to instanceof \DateTimeImmutable) {
                        $query->addFilter(new DateRangeFilter(Query::CREATED_AT, $from, $to));
                    }

                    foreach ($query->iterate() as $row) {
                        \assert(\is_string($row['created_at']));
                        $row['created_at'] = new \DateTimeImmutable($row['created_at'], $timezone);

                        if ($anonymize) {
                            $row['blame_id'] = null;
                            $row['blame'] = null;
                            $row['blame_user'] = null;
                            $row['blame_user_fqdn'] = null;
                            $row['blame_user_firewall'] = null;
                            $row['ip'] = null;
                        }

                        $data = Entry::fromArray($row)->toArray();

                        match ($format) {
                            'ndjson' => $this->echoNdjson($data),
                            'json' => $this->echoJson($data, $first),
                            'csv' => $this->echoCsv($data, $headerWritten),
                        };

                        if (ob_get_level() > 0) {
                            ob_flush();
                        }

                        flush();
                    }
                }

                if ('json' === $format) {
                    echo ']';
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => \sprintf('attachment; filename="%s"', $filename),
                'X-Accel-Buffering' => 'no',
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function resolveEntities(?string $entity, Reader $reader): array
    {
        $roleChecker = $reader->getProvider()->getAuditor()->getConfiguration()->getRoleChecker();

        if (null !== $entity) {
            // Check access before validating existence to avoid leaking entity names to unauthorized callers.
            if (null !== $roleChecker && !(bool) $roleChecker($entity, Security::VIEW_SCOPE)) {
                throw new AccessDeniedHttpException(\sprintf("Access denied to audit log for '%s'.", $entity));
            }

            // Validate the entity is known and auditable by attempting createQuery().
            try {
                $reader->createQuery($entity, ['page_size' => 1]);
            } catch (InvalidArgumentException) {
                throw new NotFoundHttpException(\sprintf("Entity '%s' is not auditable.", $entity));
            }

            return [$entity];
        }

        $schemaManager = new SchemaManager($reader->getProvider());

        /** @var array<string, array<string, string>> $repository */
        $repository = $schemaManager->collectAuditableEntities();

        $entities = [];

        foreach ($repository as $classes) {
            foreach ($classes as $fqcn => $table) {
                if (null === $roleChecker || (bool) $roleChecker($fqcn, Security::VIEW_SCOPE)) {
                    $entities[] = $fqcn;
                }
            }
        }

        return $entities;
    }

    private function parseDate(string $value, string $param): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(\sprintf("Invalid date for '%s': %s.", $param, $value));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function echoNdjson(array $data): void
    {
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param array<string, mixed> $data
     */
    private function echoJson(array $data, bool &$first): void
    {
        if (!$first) {
            echo ',';
        }

        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $first = false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function echoCsv(array $data, bool &$headerWritten): void
    {
        if (!$headerWritten) {
            echo $this->toCsvLine(array_keys($data));
            $headerWritten = true;
        }

        echo $this->toCsvLine($this->flattenForCsv($data));
    }

    /**
     * @param list<null|bool|float|int|string> $fields
     */
    private function toCsvLine(array $fields): string
    {
        $stream = fopen('php://temp', 'r+');

        if (!\is_resource($stream)) {
            throw new \RuntimeException('Failed to open temporary stream for CSV generation.');
        }

        fputcsv($stream, $fields, escape: '\\');
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        if (false === $line) {
            throw new \RuntimeException('Failed to read from temporary stream.');
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<null|bool|float|int|string>
     */
    private function flattenForCsv(array $data): array
    {
        return array_values(array_map(
            static fn (mixed $value): bool|float|int|string|null => \is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : (\is_scalar($value) || null === $value ? $value : null),
            $data
        ));
    }
}
