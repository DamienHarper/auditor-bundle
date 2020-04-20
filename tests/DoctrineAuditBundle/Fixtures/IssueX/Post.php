<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\IssueX;

use DateTime;
use DH\DoctrineAuditBundle\Annotation as Audit;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity
 * @ORM\Table(name="fixture_post", indexes={@ORM\Index(name="fk_fixture_1_idx", columns={"author_id"})})
 * @Gedmo\SoftDeleteable(fieldName="deleted_at", timeAware=false)
 * @Audit\Auditable()
 */
class Post
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
     * @ORM\Column(type="text")
     */
    protected $body;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    protected $created_at;

    /**
     * @ORM\Column(type="datetime", nullable=true, options={"default": NULL})
     */
    protected $deleted_at;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $author_id;

    /**
     * @ORM\OneToMany(targetEntity="Comment", mappedBy="post", cascade={"persist", "remove"})
     * @ORM\JoinColumn(name="id", referencedColumnName="post_id", nullable=true)
     */
    protected $comments;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
    }

    public function __toString()
    {
        return $this->title;
    }

    public function __sleep()
    {
        return ['id', 'title', 'body', 'created_at', 'author_id'];
    }

    /**
     * Set the value of id.
     *
     * @param int $id
     *
     * @return Post
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
     * @return Post
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
     * Set the value of body.
     *
     * @param string $body
     *
     * @return Post
     */
    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Get the value of body.
     *
     * @return string
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Set the value of created_at.
     *
     * @param ?DateTime $created_at
     *
     * @return Post
     */
    public function setCreatedAt(?DateTime $created_at): self
    {
        $this->created_at = $created_at;

        return $this;
    }

    /**
     * Get the value of created_at.
     *
     * @return ?DateTime
     */
    public function getCreatedAt(): ?DateTime
    {
        return $this->created_at;
    }

    /**
     * Set the value of deleted_at.
     *
     * @param ?DateTime $deleted_at
     *
     * @return Post
     */
    public function setDeletedAt(?DateTime $deleted_at): self
    {
        $this->deleted_at = $deleted_at;

        return $this;
    }

    /**
     * Get the value of deleted_at.
     *
     * @return ?DateTime
     */
    public function getDeletedAt(): ?DateTime
    {
        return $this->deleted_at;
    }

    /**
     * Set the value of author_id.
     *
     * @param int $author_id
     *
     * @return Post
     */
    public function setAuthorId(int $author_id): self
    {
        $this->author_id = $author_id;

        return $this;
    }

    /**
     * Get the value of author_id.
     *
     * @return int
     */
    public function getAuthorId(): ?int
    {
        return $this->author_id;
    }

    /**
     * Add Comment entity to collection (one to many).
     *
     * @param \DH\DoctrineAuditBundle\Tests\Fixtures\Core\Basic\Blog\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment $comment
     *
     * @return Post
     */
    public function addComment(Comment $comment): self
    {
        $this->comments[] = $comment;

        return $this;
    }

    /**
     * Remove Comment entity from collection (one to many).
     *
     * @param \DH\DoctrineAuditBundle\Tests\Fixtures\Core\Basic\Blog\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment $comment
     *
     * @return Post
     */
    public function removeComment(Comment $comment): self
    {
        $this->comments->removeElement($comment);
        $comment->setPost(null);

        return $this;
    }

    /**
     * Get Comment entity collection (one to many).
     *
     * @return Collection
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }
}
