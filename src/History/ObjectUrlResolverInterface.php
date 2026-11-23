<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\AuditorBundle\History\Part\ObjectReference;

/**
 * Gives the URL of an object named by a phrase. The bundle's resolver leads to the history of the object in the
 * viewer, for readers who may view the audits of its class; decorate it to send some classes to pages of the
 * application.
 */
interface ObjectUrlResolverInterface
{
    /**
     * @return null|ObjectUrl the URL, or null to keep the reference as plain text
     */
    public function resolve(ObjectReference $reference): ?ObjectUrl;
}
