<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\App\Command;

use DateTimeImmutable;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Kernel;

class CreatePostCommand extends Command
{
    private DoctrineProvider $doctrineProvider;

    public function __construct(DoctrineProvider $doctrineProvider)
    {
        parent::__construct();
        $this->doctrineProvider = $doctrineProvider;
    }

    protected function configure(): void
    {
        $this->setName('app:post:create');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->write('Creating a new post');

        $post = new Post();
        $post
            ->setTitle('Blameable post')
            ->setBody('yet another post')
            ->setCreatedAt(new DateTimeImmutable('2020-01-17 22:17:34'))
        ;

        $this->doctrineProvider->getAuditingServiceForEntity(Post::class)->getEntityManager()->persist($post);
        $this->doctrineProvider->getAuditingServiceForEntity(Post::class)->getEntityManager()->flush();

        // Symfony 4 compatibility
        if (Kernel::MAJOR_VERSION >= 5) {
            return self::SUCCESS;
        }

        return 0;
    }
}
