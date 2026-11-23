<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\Auditor\Model\Entry;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\AuditorBundle\History\HistoryPhraser;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Builders shared by the history tests: audit entries, a translator loaded with the bundle phrases, Doctrine metadata.
 */
trait HistoryFixtures
{
    /**
     * @param array<string, string> $messages application messages of the "dh_auditor_history" domain (ICU)
     * @param array<string, string> $fields   application messages of the "dh_auditor_fields" domain (ICU)
     */
    private static function translator(string $locale = 'en', array $messages = [], array $fields = []): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addLoader('array', new ArrayLoader());
        foreach (['en', 'fr'] as $bundleLocale) {
            $translator->addResource('yaml', \dirname(__DIR__, 3).'/src/translations/dh_auditor_history+intl-icu.'.$bundleLocale.'.yaml', $bundleLocale, HistoryPhraser::DOMAIN.'+intl-icu');
        }

        $translator->addResource('array', $messages, $locale, HistoryPhraser::DOMAIN.'+intl-icu');
        $translator->addResource('array', $fields, $locale, 'dh_auditor_fields+intl-icu');

        return $translator;
    }

    /**
     * A registry knowing the metadata of Post (the fields are made up, only their mapping is read): status (enum, translatable), kind (enum), featured
     * (boolean), publishedAt (datetime_immutable), deadline (date_immutable), body (text), title (string).
     */
    private function registry(): ManagerRegistry
    {
        $metadata = new ClassMetadata(Post::class);
        $metadata->mapField(['fieldName' => 'title', 'type' => 'string']);
        $metadata->mapField(['fieldName' => 'body', 'type' => 'text']);
        $metadata->mapField(['fieldName' => 'status', 'type' => 'string', 'enumType' => PostStatus::class]);
        $metadata->mapField(['fieldName' => 'kind', 'type' => 'string', 'enumType' => PostKind::class]);
        $metadata->mapField(['fieldName' => 'featured', 'type' => 'boolean']);
        $metadata->mapField(['fieldName' => 'publishedAt', 'type' => 'datetime_immutable']);
        $metadata->mapField(['fieldName' => 'deadline', 'type' => 'date_immutable']);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturn($metadata);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static fn (string $class): ?EntityManagerInterface => Post::class === $class ? $manager : null);

        return $registry;
    }

    /**
     * An audit entry in the schema v2 format.
     *
     * @param array<string, mixed> $changes field => ['old' => …, 'new' => …] (update, insert, remove), or the
     *                                      source/target blocks of an association
     * @param array<string, mixed> $blame
     */
    private static function entry(
        string $type,
        string $entity,
        string $objectId,
        array $changes = [],
        ?string $label = null,
        string $transactionId = '01JTX',
        array $blame = ['username' => 'alice', 'user_firewall' => 'main'],
        int|string|null $userId = 1,
        ?string $rootPath = null,
        int $id = 1,
        bool $snapshot = false,
        ?array $target = null,
        ?string $field = null,
    ): Entry {
        $source = ['id' => $objectId, 'class' => $entity, 'label' => $label, 'table' => 'post'];
        if (null !== $field) {
            $source['field'] = $field;
        }

        $diffs = null === $target
            ? ['source' => $source, 'changes' => $changes]
            : ['source' => $source, 'target' => $target, 'is_owning_side' => true, 'join_table' => 'post__tag'];
        if ($snapshot) {
            $diffs['snapshot'] = true;
        }

        $deep = array_keys(array_filter($changes, static fn (mixed $diff): bool => \is_array($diff) && !\array_key_exists('old', $diff) && !\array_key_exists('new', $diff)));
        if (null === $target && [] !== $deep) {
            $diffs['deep_diffs'] = $deep;
        }

        return Entry::fromArray([
            'id' => $id,
            'schema_version' => 2,
            'type' => $type,
            'object_id' => $objectId,
            'transaction_id' => $transactionId,
            'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
            'blame_id' => $userId,
            'blame' => json_encode($blame, JSON_THROW_ON_ERROR),
            'created_at' => new \DateTimeImmutable('2026-11-22 18:41:00'),
            'entity' => $entity,
            'root_path' => $rootPath,
        ]);
    }

    /**
     * Association summary of a collection element, as written in the target block of an association entry.
     *
     * @return array<string, mixed>
     */
    private static function target(string $class, int|string $id, ?string $label, string $field = 'posts'): array
    {
        return ['id' => $id, 'class' => $class, 'label' => $label, 'table' => 'tag', 'field' => $field];
    }
}
