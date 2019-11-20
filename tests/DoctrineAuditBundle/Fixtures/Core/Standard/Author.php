<?php

namespace DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="author")
 */
class Author
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
    protected $fullname;

    /**
     * @ORM\Column(type="string", length=255)
     */
    protected $email;

    /**
     * @ORM\OneToMany(targetEntity="Post", mappedBy="author", cascade={"persist", "remove"})
     * @ORM\JoinColumn(name="id", referencedColumnName="author_id", nullable=false)
     */
    protected $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
    }

    public function __sleep()
    {
        return ['id', 'fullname', 'email'];
    }

    /**
     * Set the value of id.
     *
     * @param int $id
     *
     * @return Author
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
     * Set the value of fullname.
     *
     * @param string $fullname
     *
     * @return Author
     */
    public function setFullname(string $fullname): self
    {
        $this->fullname = $fullname;

        return $this;
    }

    /**
     * Get the value of fullname.
     *
     * @return string
     */
    public function getFullname(): ?string
    {
        return $this->fullname;
    }

    /**
     * Set the value of email.
     *
     * @param string $email
     *
     * @return Author
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Get the value of email.
     *
     * @return string
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Add Post entity to collection (one to many).
     *
     * @param Post $post
     *
     * @return Author
     */
    public function addPost(Post $post): self
    {
        $this->posts[] = $post;

        return $this;
    }

    /**
     * Remove Post entity from collection (one to many).
     *
     * @param Post $post
     *
     * @return Author
     */
    public function removePost(Post $post): self
    {
        $this->posts->removeElement($post);
        $post->setAuthor(null);

        return $this;
    }

    /**
     * Get Post entity collection (one to many).
     *
     * @return Collection
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }
}
