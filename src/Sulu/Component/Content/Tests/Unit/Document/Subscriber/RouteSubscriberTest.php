<?php
/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Tests\Unit\Document\Subscriber;

use PHPCR\NodeInterface;
use Prophecy\Argument;
use Sulu\Bundle\ContentBundle\Document\HomeDocument;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Component\Content\Document\Behavior\ResourceSegmentBehavior;
use Sulu\Component\Content\Document\Behavior\RouteBehavior;
use Sulu\Component\Content\Document\Behavior\WebspaceBehavior;
use Sulu\Component\Content\Document\Subscriber\RouteSubscriber;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event\HydrateEvent;
use Sulu\Component\DocumentManager\Event\PersistEvent;
use Sulu\Component\DocumentManager\Event\RemoveEvent;
use Sulu\Component\PHPCR\SessionManager\SessionManagerInterface;
use Sulu\Component\DocumentManager\Event\MetadataLoadEvent;
use Sulu\Component\DocumentManager\Metadata;

class RouteSubscriberTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->event = $this->prophesize(MetadataLoadEvent::class);
        $this->reflection = $this->prophesize(\ReflectionClass::class);
        $this->metadata = $this->prophesize(Metadata::class);

        $this->subscriber = new RouteSubscriber();
    }

    /**
     * It should return early if the document is not implementing route behavior.
     */
    public function testNotImplementing()
    {
        $this->event->getMetadata()->willReturn($this->metadata->reveal());
        $this->metadata->getReflectionClass()->willReturn($this->reflection->reveal());
        $this->reflection->isSubclassOf(RouteBehavior::class)->willReturn(false);
        $this->metadata->addFieldMapping(Argument::cetera())->shouldNotBeCalled();
        $this->subscriber->handleMetadataLoad($this->event->reveal());
    }

    /**
     * It should map the route fields.
     */
    public function testMap()
    {
        $this->event->getMetadata()->willReturn($this->metadata->reveal());
        $this->metadata->getReflectionClass()->willReturn($this->reflection->reveal());
        $this->reflection->isSubclassOf(RouteBehavior::class)->willReturn(true);

        $this->metadata->addFieldMapping(
            'history',
            [
                'encoding' => 'system',
                'property' => RouteSubscriber::DOCUMENT_TARGET_FIELD,
                'type' => 'string',
            ]
        )->shouldBeCalled();
        $this->subscriber->handleMetadataLoad($this->event->reveal());
    }
}
