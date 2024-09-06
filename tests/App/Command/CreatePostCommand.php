<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\App\Command;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CreatePostCommand extends Command
{
    public function __construct(private readonly DoctrineProvider $doctrineProvider)
    {
        parent::__construct();
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
            ->setCreatedAt(new \DateTimeImmutable('2020-01-17 22:17:34'))
        ;

        $entityManager = $this->doctrineProvider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $entityManager->persist($post);
        $entityManager->flush();

        return self::SUCCESS;
    }
}
