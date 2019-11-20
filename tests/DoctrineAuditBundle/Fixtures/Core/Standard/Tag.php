<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="tag")
 */
class Tag
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    protected $title;

    /**
     * @ORM\ManyToMany(targetEntity="Post", mappedBy="tags", cascade={"persist", "remove"})
     */
    protected $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
    }

    public function __sleep()
    {
        return ['id', 'title'];
    }

    /**
     * Set the value of id.
     *
     * @param int $id
     *
     * @return Tag
     */
    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the value of id.
     *
     * @return int
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set the value of title.
     *
     * @param string $title
     *
     * @return Tag
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get the value of title.
     *
     * @return string
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * Add Post entity to collection.
     *
     * @param \DH\DoctrineAuditBundle\Tests\Fixtures\Core\Basic\Blog\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post $post
     *
     * @return Tag
     */
    public function addPost(Post $post): self
    {
        $this->posts[] = $post;

        return $this;
    }

    /**
     * Remove Post entity from collection.
     *
     * @param \DH\DoctrineAuditBundle\Tests\Fixtures\Core\Basic\Blog\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post $post
     *
     * @return Tag
     */
    public function removePost(Post $post): self
    {
        $this->posts->removeElement($post);

        return $this;
    }

    /**
     * Get Post entity collection.
     *
     * @return Collection
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }
}
