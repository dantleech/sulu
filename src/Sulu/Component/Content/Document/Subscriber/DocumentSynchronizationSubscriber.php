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

use Sulu\Component\Content\Document\Behavior\SynchronizeBehavior;
use Sulu\Component\Content\Document\SynchronizationManager;
use Sulu\Component\DocumentManager\Behavior\Mapping\LocaleBehavior;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event\FlushEvent;
use Sulu\Component\DocumentManager\Event\MetadataLoadEvent;
use Sulu\Component\DocumentManager\Event\PersistEvent;
use Sulu\Component\DocumentManager\Event\RemoveEvent;
use Sulu\Component\DocumentManager\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Sulu\Component\DocumentManager\Event\MoveEvent;
use Sulu\Component\DocumentManager\Event\AbstractManagerEvent;
use Sulu\Component\Content\Document\Syncronization\Mapping;
use Sulu\Component\DocumentManager\DocumentManagerContext;
use Sulu\Component\DocumentManager\Event\AbstractDocumentManagerContextEvent;

class DocumentSynchronizationSubscriber implements EventSubscriberInterface
{
    /**
     * @var SynchronizationManager
     */
    private $syncManager;

    /**
     * @var DocumentManagerInterface
     */
    private $sourceContext;

    /**
     * @var object[]
     */
    private $persistQueue = [];

    /**
     * @var object[]
     */
    private $removeQueue = [];

    /**
     * @var Mapping
     */
    private $mapping;

    /**
     * NOTE: We pass the source manager here because we need to ensure that we
     *       only process documents FROM the source manager. If we could assign
     *       event subscribers to specific document managers this would not
     *       be necessary.
     */
    public function __construct(DocumentManagerContext $sourceContext, SynchronizationManager $syncManager, Mapping $mapping)
    {
        $this->sourceContext = $sourceContext;
        $this->syncManager = $syncManager;
        $this->mapping = $mapping;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            Events::FLUSH => 'handleFlush',
            Events::REMOVE => 'handleRemove',
            Events::METADATA_LOAD => 'handleMetadataLoad',
            Events::MOVE => 'handleMove',

            // persist needs to be before the content mapper subscriber
            // because we need to stop propagation early on the publish
            Events::PERSIST => ['handlePersist', 10],
        ];
    }

    public function handleMetadataLoad(MetadataLoadEvent $event)
    {
        $metadata = $event->getMetadata();

        if (false === $metadata->getReflectionClass()->isSubclassOf(SynchronizeBehavior::class)) {
            return;
        }

        $encoding = $metadata->getReflectionClass()->isSubclassOf(LocaleBehavior::class) ? 'system_localized' : 'system';

        $metadata->addFieldMapping('synchronizedManagers', [
            'encoding' => $encoding,
            'property' => SynchronizeBehavior::SYNCED_FIELD,
            'type' => 'string',
        ]);
    }

    /**
     * Synchronize new documents with the target document manager.
     *
     * @param PersistEvent
     */
    public function handlePersist(PersistEvent $event)
    {
        $context = $event->getContext();

        // do not do anything if the default and target managers are the same.
        if ($this->sourceContext === $this->syncManager->getTargetContext()) {
            return;
        }

        $this->assertEmittingContext($context);

        $document = $event->getDocument();

        // only sync documents implementing the sync behavior
        if (!$document instanceof SynchronizeBehavior) {
            return;
        }

        if (false === $this->mapping->hasAutoSyncPolicy($document, [ 'update', 'create' ])) {
            return;
        }

        // only sync new documents automatically
        if (false === $event->getNode()->isNew()) {
            // document is now "dirty" and no longer synchronized with any managers.
            $this->clearSynchronizedManagers($event, $document);

            return;
        }

        $inspector = $this->sourceContext->getInspector();
        $locale = $inspector->getLocale($document);
        $this->persistQueue[] = [
            'document' => $document,
            'locale' => $locale,
        ];
    }

    public function handleRemove(RemoveEvent $removeEvent)
    {
        $context = $removeEvent->getContext();

        $this->assertEmittingContext($context);

        $document = $removeEvent->getDocument();

        // only sync documents implementing the sync behavior
        if (!$document instanceof SynchronizeBehavior) {
            return;
        }

        if (false === $this->mapping->hasAutoSyncPolicy($document, [ 'delete' ])) {
            return;
        }

        // TODO: Do not queue docuemnts that are already queued for removal.
        $this->removeQueue[] = $document;
    }

    public function handleMove(MoveEvent $event)
    {
        $document = $event->getDocument();

        if (!$document instanceof SynchronizeBehavior) {
            return;
        }

        if (false === $this->mapping->hasAutoSyncPolicy($document, [ 'move' ])) {
            // remove synchronization status of document, it will be moved upon
            // the next synchronization.
            $this->clearSynchronizedManagers($event, $document);
            return;
        }

        $this->clearSynchronizedManagers($event, $document);
        $this->syncManager->push($document, [ 'flush' => true ]);
    }

    public function handleFlush(FlushEvent $event)
    {
        if (empty($this->persistQueue) && empty($this->removeQueue)) {
            return;
        }

        if ($this->sourceContext === $this->syncManager->getTargetContext()) {
            return;
        }

        $context = $event->getContext();
        $this->assertEmittingContext($context);

        $targetContext = $this->syncManager->getTargetContext();
        $defaultFlush = false;

        // process the persistQueue, FIFO (first in, first out)
        // array_shift will return and remove the first element of
        // the persistQueue for each iteration.
        while ($entry = array_shift($this->persistQueue)) {
            $defaultFlush = true;
            $document = $entry['document'];
            $locale = $entry['locale'];

            // we need to load the document in the locale it was persisted in.
            // note that this should not create any significant overhead as all
            // the data is already in-memory.
            $inspector = $this->sourceContext->getInspector();

            if ($inspector->getLocale($document) !== $locale) {
                $this->sourceContext->find($inspector->getUUid($document), $locale);
            }

            // synchronize the document, cascading the synchronization to any
            // configured relations.
            $this->syncManager->push($document, [ 'cascade' => true ]);
        }
        while ($entry = array_shift($this->removeQueue)) {
            // NOTE: this will not work when the document is not registeredro
            $this->syncManager->remove($entry);
        }

        // flush both managers. the target manager will then commit
        // the synchronized documents and the source manager will update
        // the "synchronized document managers" field of original documents.
        $targetContext->getManager()->flush();

        // only flush the source manager when objects have been synchronized (
        // not removed).
        if ($defaultFlush) {
            $this->sourceContext->flush();
        }
    }

    private function assertEmittingContext(DocumentManagerContext $context)
    {
        // do nothing, see same condition in handlePersist.
        if ($context === $this->sourceContext) {
            return;
        }

        throw new \RuntimeException(
            'The document syncronization subscriber must only be registered to the source document manager'
        );
    }

    private function clearSynchronizedManagers(AbstractDocumentManagerContextEvent $event, $document)
    {
        // node is now "dirty" and no longer synchronized with any managers.
        $metadata = $event->getContext()->getMetadataFactory()->getMetadataForClass(get_class($document));
        $metadata->setFieldValue($document, 'synchronizedManagers', []);
    }
}
