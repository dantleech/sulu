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

use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Component\Content\Document\Behavior\SynchronizeBehavior;
use Sulu\Component\Content\Document\Subscriber\DocumentSynchronizationSubscriber;
use Sulu\Component\Content\Document\SynchronizationManager;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event\RemoveEvent;
use Sulu\Component\DocumentManager\Metadata;
use Sulu\Component\DocumentManager\MetadataFactoryInterface;
use Sulu\Component\DocumentManager\Event\MoveEvent;
use Sulu\Component\Content\Document\Syncronization\Mapping;
use Sulu\Component\DocumentManager\DocumentManagerContext;

class DocumentSynchronizationSubscriberTest extends SubscriberTestCase
{
    /**
     * @var SynchronizationManager
     */
    private $syncManager;

    /**
     * @var DocumentManagerInterface
     */
    private $publishManager;

    /**
     * @var DocumentSynchronizationSubscriber
     */
    private $subscriber;

    /**
     * @var SynchronizeBehavior
     */
    private $document;

    /**
     * @var DocumentInspector
     */
    private $inspector;

    /**
     * @var RemoveEvent
     */
    private $removeEvent;

    /**
     * @var MetadataFactoryInterface
     */
    private $metadataFactory;

    /**
     * @var Metadata
     */
    private $metadata;


    public function setUp()
    {
        parent::setUp();
        $this->syncManager = $this->prophesize(SynchronizationManager::class);
        $this->publishManager = $this->prophesize(DocumentManagerInterface::class);
        $this->publishContext = $this->prophesize(DocumentManagerContext::class);
        $this->mapping = $this->prophesize(Mapping::class);

        $this->subscriber = new DocumentSynchronizationSubscriber(
            $this->context->reveal(),
            $this->syncManager->reveal(),
            $this->mapping->reveal()
        );
        $this->publishContext->getManager()->willReturn($this->publishManager->reveal());
        $this->document = $this->prophesize(SynchronizeBehavior::class);
        $this->inspector = $this->prophesize(DocumentInspector::class);
        $this->syncManager->getTargetContext()->willReturn($this->publishContext->reveal());
        $this->removeEvent = $this->prophesize(RemoveEvent::class);
        $this->removeEvent->getDocument()->willReturn($this->document->reveal());
        $this->removeEvent->getContext()->willReturn($this->context->reveal());
        $this->metadataFactory = $this->prophesize(MetadataFactoryInterface::class);
        $this->metadata = $this->prophesize(Metadata::class);
        $this->moveEvent = $this->prophesize(MoveEvent::class);
        $this->context->getInspector()->willReturn($this->inspector->reveal());
        $this->context->getMetadataFactory()->willReturn(
            $this->metadataFactory->reveal()
        );
    }

    /**
     * It should do nothing if the source manager is not registered to the source document manager.
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessage The document syncronization subscriber must only be registered to the source document manager
     */
    public function testDoNothingNotDefaultManager()
    {
        $subscriber = new DocumentSynchronizationSubscriber(
            $this->prophesize(DocumentManagerContext::class)->reveal(),
            $this->syncManager->reveal(),
            $this->mapping->reveal()
        );
        $this->publishManager->flush()->shouldNotBeCalled();

        $subscriber->handlePersist($this->persistEvent->reveal());
        $subscriber->handleFlush($this->flushEvent->reveal());
    }

    /**
     * It should do nothing if the document is not a synchronize document.
     */
    public function testDoNothingNotWorkflowStage()
    {
        $this->persistEvent->getDocument()->willReturn(new \stdClass());
        $this->publishManager->flush()->shouldNotBeCalled();

        $this->subscriber->handlePersist($this->persistEvent->reveal());
        $this->subscriber->handleFlush($this->flushEvent->reveal());
    }

    /**
     * It should synchronize a document with a new PHPCR node.
     */
    public function testSyncNew()
    {
        $locale = 'at';

        $this->persistEvent->getNode()->willReturn($this->node->reveal());
        $this->persistEvent->getDocument()->willReturn($this->document->reveal());
        $this->node->isNew()->willReturn(true);

        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))->willReturn(
            $this->metadata->reveal()
        );
        $this->metadata->setFieldValue($this->document->reveal(), 'synchronizedManagers', [])->shouldBeCalled();

        $this->manager->getInspector()->willReturn($this->inspector->reveal());
        $this->inspector->getLocale($this->document->reveal())->willReturn($locale);

        $this->manager->flush()->shouldBeCalled();
        $this->publishManager->flush()->shouldBeCalled();
        $this->syncManager->push($this->document->reveal(), [ 'cascade' => true ])->shouldBeCalled();

        $this->subscriber->handlePersist($this->persistEvent->reveal());
        $this->subscriber->handleFlush($this->flushEvent->reveal());
    }

    /**
     * It should load the document in the persisted locale if it is currently in a different one.
     */
    public function testSyncNewDifferentLocale()
    {
        $locale = 'at';

        $this->persistEvent->getNode()->willReturn($this->node->reveal());
        $this->persistEvent->getDocument()->willReturn($this->document->reveal());
        $this->node->isNew()->willReturn(true);

        $this->manager->getInspector()->willReturn($this->inspector->reveal());
        $this->inspector->getLocale($this->document->reveal())->willReturn($locale, 'fr');
        $this->inspector->getUuid($this->document->reveal())->willReturn('1234');
        $this->manager->find('1234', $locale)->shouldBeCalled();

        $this->manager->flush()->shouldBeCalled();
        $this->publishManager->flush()->shouldBeCalled();
        $this->syncManager->push($this->document->reveal(), [ 'cascade' => true ])->shouldBeCalled();

        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))->willReturn(
            $this->metadata->reveal()
        );
        $this->metadata->setFieldValue($this->document->reveal(), 'synchronizedManagers', [])->shouldBeCalled();

        $this->subscriber->handlePersist($this->persistEvent->reveal());
        $this->subscriber->handleFlush($this->flushEvent->reveal());
    }

    /**
     * It should NOT persist a document with a not-new PHPCR node.
     * It should add the name of the TDM to the synced property of the document.
     */
    public function testSyncOld()
    {
        $this->persistEvent->getNode()->willReturn($this->node->reveal());
        $this->persistEvent->getDocument()->willReturn($this->document->reveal());
        $this->node->isNew()->willReturn(false);

        $this->context->getMetadataFactory()->willReturn($this->metadataFactory->reveal());
        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))->willReturn($this->metadata->reveal());
        $this->metadata->setFieldValue($this->document->reveal(), 'synced', 'live');

        $this->manager->flush()->shouldNotBeCalled();

        $this->subscriber->handlePersist($this->persistEvent->reveal());
        $this->subscriber->handleFlush($this->flushEvent->reveal());
    }

    /**
     * It should remove documents from the TDM that are removed in the TDM.
     */
    public function testRemove()
    {
        $this->mapping->hasAutoSyncPolicy($this->document->reveal(), ['delete'])->willReturn(true);
        $this->syncManager->remove($this->document->reveal())->shouldBeCalled();

        $this->subscriber->handleRemove($this->removeEvent->reveal());
        $this->subscriber->handleFlush($this->flushEvent->reveal());
    }

    /**
     * It should clear the documents synchrnoized managers field when moved.
     */
    public function testMove()
    {
        $this->context->getMetadataFactory()->willReturn($this->metadataFactory->reveal());
        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))->willReturn($this->metadata->reveal());
        $this->metadata->setFieldValue($this->document->reveal(), 'synced', 'live');

        $this->subscriber->handleMove($this->moveEvent->reveal());
    }
}
