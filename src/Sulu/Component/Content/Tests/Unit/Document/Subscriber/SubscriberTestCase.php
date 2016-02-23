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
use Sulu\Component\DocumentManager\DocumentAccessor;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event\FlushEvent;
use Sulu\Component\DocumentManager\Event\HydrateEvent;
use Sulu\Component\DocumentManager\Event\PersistEvent;
use Sulu\Component\DocumentManager\PropertyEncoder;
use Sulu\Component\DocumentManager\DocumentManagerContext;

class SubscriberTestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * @var PersistEvent
     */
    protected $persistEvent;

    /**
     * @var HydrateEvent
     */
    protected $hydrateEvent;

    /**
     * @var FlushEvent
     */
    protected $flushEvent;

    /**
     * @var \stdClass
     */
    protected $notImplementing;

    /**
     * @var PropertyEncoder
     */
    protected $encoder;

    /**
     * @var NodeInterface
     */
    protected $node;

    /**
     * @var DocumentAccessor
     */
    protected $accessor;

    /**
     * @var NodeInterface
     */
    protected $parentNode;

    protected $manager;

    public function setUp()
    {
        $this->persistEvent = $this->prophesize(PersistEvent::class);
        $this->hydrateEvent = $this->prophesize(HydrateEvent::class);
        $this->flushEvent = $this->prophesize(FlushEvent::class);
        $this->notImplementing = new \stdClass();
        $this->encoder = $this->prophesize(PropertyEncoder::class);
        $this->node = $this->prophesize(NodeInterface::class);
        $this->parentNode = $this->prophesize(NodeInterface::class);
        $this->accessor = $this->prophesize(DocumentAccessor::class);
        $this->persistEvent->getNode()->willReturn($this->node);
        $this->persistEvent->getAccessor()->willReturn($this->accessor);
        $this->hydrateEvent->getAccessor()->willReturn($this->accessor);
        $this->manager = $this->prophesize(DocumentManagerInterface::class);
        $this->context = $this->prophesize(DocumentManagerContext::class);

        $this->context->getManager()->willReturn($this->manager->reveal());
        $this->hydrateEvent->getManager()->willReturn($this->manager->reveal());
        $this->persistEvent->getManager()->willReturn($this->manager->reveal());
        $this->flushEvent->getManager()->willReturn($this->manager->reveal());
        $this->hydrateEvent->getContext()->willReturn($this->context->reveal());
        $this->persistEvent->getContext()->willReturn($this->context->reveal());
        $this->flushEvent->getContext()->willReturn($this->context->reveal());
    }
}
