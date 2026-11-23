<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\Tests\History\ViewerObjectUrlResolverTest;
use DH\AuditorBundle\Twig\ViewableEntityExtension;
use DH\AuditorBundle\Viewer\AuditViewerUrlGenerator;

/**
 * Leads a reference to the history of the object in the viewer, visible to readers granted the "view" role of its
 * class (to every reader without role checker). No URL when the viewer is disabled or the class is not audited.
 *
 * @see ViewerObjectUrlResolverTest
 */
final readonly class ViewerObjectUrlResolver implements ObjectUrlResolverInterface
{
    public function __construct(
        private AuditViewerUrlGenerator $urlGenerator,
        private ViewableEntityExtension $viewable,
    ) {}

    public function resolve(ObjectReference $reference): ?ObjectUrl
    {
        // the class of an audit entry may no longer exist; the URL generator also needs a class-string
        if (!class_exists($reference->class)) {
            return null;
        }

        $url = $this->urlGenerator->generateEntityUrl($reference->class, $reference->id);

        return null === $url ? null : new ObjectUrl($url, $this->viewable->isViewable($reference->class));
    }
}
