<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Document\Subscriber;

use Sulu\Bundle\ContentBundle\Document\HomeDocument;
use Sulu\Bundle\ContentBundle\Document\RouteDocument;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Component\Content\Document\Behavior\ResourceSegmentBehavior;
use Sulu\Component\Content\Document\Behavior\RouteBehavior;
use Sulu\Component\Content\Document\Behavior\WebspaceBehavior;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event\HydrateEvent;
use Sulu\Component\DocumentManager\Event\PersistEvent;
use Sulu\Component\DocumentManager\Event\MetadataLoadEvent;
use Sulu\Component\DocumentManager\Events;
use Sulu\Component\PHPCR\SessionManager\SessionManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Behavior for route (sulu:path) documents.
 */
class RouteSubscriber implements EventSubscriberInterface
{
    const DOCUMENT_TARGET_FIELD = 'history';

    public static function getSubscribedEvents()
    {
        return [
            Events::METADATA_LOAD => 'handleMetadataLoad',
        ];
    }

    public function handleMetadataLoad(MetadataLoadEvent $event)
    {
        $metadata = $event->getMetadata();

        if (false === $metadata->getReflectionClass()->isSubclassOf(RouteBehavior::class)) {
            return;
        }

        $metadata->addFieldMapping(
            'history',
            [
                'encoding' => 'system',
                'property' => self::DOCUMENT_TARGET_FIELD,
                'type' => 'string',
            ]
        );
    }
}
