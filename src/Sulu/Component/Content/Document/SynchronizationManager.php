<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Document;

use Sulu\Bundle\ContentBundle\Document\RouteDocument;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentManagerRegistry;
use Sulu\Bundle\DocumentManagerBundle\Bridge\PropertyEncoder;
use Sulu\Component\Content\Document\Behavior\ResourceSegmentBehavior;
use Sulu\Component\Content\Document\Behavior\SynchronizeBehavior;
use Sulu\Component\Content\Document\Syncronization\DocumentRegistrator;
use Sulu\Component\DocumentManager\Behavior\Mapping\LocaleBehavior;
use Sulu\Component\Content\Document\Syncronization\Mapping;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\DocumentManagerContext;
use Psr\Log\LoggerInterface;
use PHPCR\Util\PathHelper;

/**
 * The synchronization manager handles the synchronization of documents
 * from the DEFAULT document manger to the PUBLISH document manager.
 *
 * NOTE: In the future multiple target document managers may be supported.
 */
class SynchronizationManager
{
    const PASSIVE_MANAGER_NAME = '_passive_default_manager';

    /**
     * @var DocumentManagerRegistryInterface
     */
    private $registry;

    /**
     * @var PropertyEncoder
     */
    private $encoder;

    /**
     * @var string
     */
    private $targetContextName;

    /**
     * @var DocumentRegistrator
     */
    private $registrator;

    /**
     * @var array
     */
    private $mapping;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        DocumentManagerRegistry $registry,
        PropertyEncoder $encoder,
        $targetContextName,
        Mapping $mapping,
        $registrator = null,
        LoggerInterface $logger = null
    ) {
        $this->registry = $registry;
        $this->targetContextName = $targetContextName;
        $this->encoder = $encoder;
        $this->mapping = $mapping;
        $this->registrator = $registrator ?: new DocumentRegistrator(
            $registry->getContext(),
            $registry->getContext($this->targetContextName)
        );
        $this->logger = $logger;
    }

    /**
     * Return the target document manager (TDM).
     *
     * This should be the only class that is aware of the TDM name. By having
     * this method we can be sure that whatever the TDM is, the TDM is always
     * the TDM.
     *
     * NOTE: This is used only by the synchronization subscriber in order
     *       to "flush" the TDM.
     *
     * @return DocumentManagerInterface
     */
    public function getTargetContext()
    {
        return $this->registry->getContext($this->targetContextName);
    }

    /**
     * Push a single document to the target document manager in the
     * documents currently registered locale.
     *
     * By default "flush" will not be called and no associated documents will be
     * cascaded.
     *
     * Options:
     *
     * - `force`  : Force the synchronization, do not skip if the system thinks
     *              the target document is already synchronized.
     * - `cascade`: Cascade the synchronization to any configured relations.
     * - `flush`  : Flush both document managers after synchronization (as the calling
     *              code may not have access to them).
     *
     * @param SynchronizeBehavior $document
     * @param array $options
     */
    public function push(SynchronizeBehavior $document, array $options = [])
    {
        $sourceContext = $this->registry->getContext();
        $targetContext = $this->registry->getContext($this->targetContextName);

        $this->synchronize($document, $sourceContext, $targetContext, $options);
    }

    /**
     * Pull a single document from the target document manager in the
     * documents currently registered locale.
     *
     * By default "flush" will not be called and no associated documents will be
     * cascaded.
     *
     * Options:
     *
     * - `force`  : Force the synchronization, do not skip if the system thinks
     *              the target document is already synchronized.
     * - `cascade`: Cascade the synchronization to any configured relations.
     * - `flush`  : Flush both document managers after synchronization (as the calling
     *              code may not have access to them).
     *
     * @param SynchronizeBehavior $document
     * @param array $options
     */
    public function pull(SynchronizeBehavior $document, array $options = [])
    {
        $sourceContext = $this->registry->getContext(self::PASSIVE_MANAGER_NAME);
        $targetContext = $this->registry->getContext($this->targetContextName);

        $locale = $sourceContext->getInspector()->getLocale($document);
        $uuid = $sourceContext->getInspector()->getUuid($document);

        // we need to register the document instance in the target registry
        // TODO: Test this.
        $this->registrator->registerDocumentWithTDM($document, $sourceContext, $targetContext);

        // load the document in the target state
        $targetContext->getManager()->find($uuid, $locale);

        $this->synchronize($document, $targetContext, $sourceContext, $options);
    }

    private function synchronize(SynchronizeBehavior $document, $sourceContext, $targetContext, array $options = [])
    {
        $options = array_merge([
            'force' => false,
            'cascade' => false,
            'flush' => false,
        ], $options);

        $this->log(sprintf(
            'Syncing "%s" (%s: %s) from "%s" to "%s"',
            get_class($document),
            $sourceContext->getInspector()->getLocale($document),
            $sourceContext->getInspector()->getPath($document),
            $sourceContext->getName(),
            $targetContext->getName()
        ));

        $this->assertDifferentContextInstances($sourceContext, $targetContext);

        if (false === $options['force'] && $this->isDocumentSynchronized($document)) {
            return;
        }

        $inspector = $sourceContext->getInspector();
        $locale = $inspector->getLocale($document);
        $path = $inspector->getPath($document);

        // register the SDM document and its immediate relations with the TDM
        // PHPCR node.
        $this->registrator->registerDocumentWithTDM($document, $sourceContext, $targetContext);

        // save the document with the "publish" document manager.
        $targetContext->getManager()->persist(
            $document,
            $locale,
            [
                'path' => $path,
            ]
        );
        // the document is now synchronized with the publish workspace...

        if ($options['cascade']) {
            $this->cascadeRelations($document, $sourceContext, $targetContext, $options);
        }

        // TODO: This only applies on PULL
        // add the document manager name to the list of synchronized
        // document managers directly on the PHPCR node.
        //
        // we store an array instead of a boolean because we are supporting the
        // possiblity that there MAY one day be more than one synchronization
        // target.
        //
        // TODO: We should should set this value on the document and re-persist
        //       it rather than leak localization behavior here, however this is
        //       currently a heavy operation due to the content system and lack of a
        //       UOW.
        $synced[] = $this->targetContextName;

        // only store unique values: if the sync was forced, then the document
        // may already have the target manager name in its list of synched
        // managers.
        $synced = array_unique($synced);
        $node = $inspector->getNode($document);

        if ($document instanceof LocaleBehavior) {
            $node->setProperty(
                $this->encoder->localizedSystemName(
                    SynchronizeBehavior::SYNCED_FIELD,
                    $inspector->getLocale($document)
                ),
                $synced
            );
        } else {
            $node->setProperty(
                $this->encoder->systemName(
                    SynchronizeBehavior::SYNCED_FIELD
                ),
                $synced
            );
        }

        // TODO: use the metadata to set this field? 
        //       yes: there is a method to do this ($metadata->setFieldValue)
        $reflection = new \ReflectionClass(get_class($document));
        $property = $reflection->getProperty(
            'synchronizedManagers'
        );
        $property->setAccessible(true);
        $property->setValue($document, $synced);

        if ($options['flush']) {
            $sourceContext->getManager()->flush();
            $targetContext->getManager()->flush();
        }
    }

    public function remove($document, array $options = [])
    {
        $sourceContext = $this->registry->getContext();
        $targetContext = $this->getTargetContext();

        return $this->doRemove($document, $sourceContext, $targetContext, $options);
    }

    /**
     * Remove the given document from the TARGET document manager.
     */
    private function doRemove($document, $sourceContext, $targetContext, array $options)
    {
        $options = array_merge([
            'flush' => false,
        ], $options);

        $targetInspector = $targetContext->getInspector();
        $this->log(sprintf(
            'Removing "%s" (%s: %s) from target "%s" (source: "%s")',
            get_class($document),
            $targetInspector->getLocale($document),
            $targetInspector->getPath($document),
            $targetContext->getName(),
            $sourceContext->getName()
        ));

        // Flush the target manager.
        //
        // TODO: This should not be necessary, but without it jackalope seems
        //       to have some state problems, possibly related to:
        //       https://github.com/jackalope/jackalope/pull/309
        $targetContext->getManager()->flush();

        $this->registrator->registerDocumentWithTDM($document, $sourceContext, $targetContext);

        $children = $targetInspector->getChildren($document);

        // in the case that the node has children, we just convert the node
        // into a generic node instead of deleting it.
        if ($children->count()) {
            $this->replaceNodeWithGeneric($document, $targetContext);
            return;
        }

        $targetContext->getManager()->remove($document);

        if ($options['flush']) {
            $targetContext->getManager()->flush();
        }
    }

    private function replaceNodeWithGeneric($document, DocumentManagerContext $context)
    {
        $inspector = $context->getInspector();
        $nodeManager = $context->getNodeManager();
        $path = $inspector->getPath($document);
        $uuid = $inspector->getUuid($document);
        $node = $inspector->getNode($document);

        $tmpName = '_tmp_' . uniqid() . PathHelper::getNodeName($path);
        $tmpPath = PathHelper::getParentPath($path) . '/' . $tmpName;
        $tempNode = $nodeManager->createPath($tmpPath);

        foreach ($node->getNodes() as $child) {
            $nodeManager->move($child->getIdentifier(), $tmpPath, $child->getName());
        }

        $nodeManager->remove($uuid);
        $genericNode = $nodeManager->createPath($path, $uuid);

        foreach ($tempNode->getNodes() as $node) {
            $nodeManager->move($node->getIdentifier(), $genericNode->getIdentifier(), $node->getName());
        }
    }

    private function cascadeRelations($document, $sourceContext, $targetContext, array $options)
    {
        $cascadeFqns = $this->mapping->getCascadeReferrers($document);

        if (empty($cascadeFqns)) {
            return;
        }

        $referrers = $sourceContext->getInspector()->getReferrers($document);
        $sourceReferrerUuids = [];
        foreach ($referrers as $referrer) {
            $sourceReferrerUuids[] = $sourceContext->getInspector()->getUuid($referrer);
            foreach ($cascadeFqns as $cascadeFqn) {
                // if the referrer does not an instance of the mapped cascade
                // class, continue.
                if (false === $this->isInstanceOf($cascadeFqn, $referrer)) {
                    continue;
                }

                $this->log(sprintf(
                    'Cascading %s for %s',
                    get_class($referrer),
                    get_class($document)
                ));

                $options['flush'] = false;
                $this->synchronize($referrer, $sourceContext, $targetContext, $options);
            }
        }

        $uuid = $sourceContext->getInspector()->getUuid($document);

        if (false === $targetContext->getNodeManager()->has($uuid)) {
            return;
        }

        // if there are any *additional* referrers, remove them or reset turn
        // them into "unknown" documents
        $referrers = $targetContext->getInspector()->getNode($document)->getReferences();
        foreach ($referrers as $referrer) {
            $referrer = $referrer->getParent();
            if (in_array($referrer->getIdentifier(), $sourceReferrerUuids)) {
                continue;
            }

            // PROBLEM: referrer has the target document from the source context.
            $referrer = $targetContext->getManager()->find(
                $referrer->getIdentifier(), 
                'de', 
                [ 'rehydrate' => true ]
            );

            $this->doRemove($referrer, $sourceContext, $targetContext, []);
        }
    }

    private function isInstanceOf($classFqn, $document)
    {
        if (get_class($document) === $classFqn) {
            return true;
        }

        $reflection = new \ReflectionClass(get_class($document));

        return $reflection->isSubclassOf($classFqn);
    }

    private function isDocumentSynchronized($document)
    {
        // get the list of managers with which the document is already
        // synchronized (the value may be NULL as we have no control over what
        // the user does with this mapped value).
        $synced = $document->getSynchronizedManagers() ?: [];

        // unless forced, we will not process documents which are already
        // synced with the target document manager.
        return in_array($this->targetContextName, $synced);
    }

    private function assertDifferentContextInstances(DocumentManagerContext $context1, DocumentManagerContext $context2)
    {
        if ($context1=== $context2) {
            throw new \RuntimeException(
                'Target and source managers are the same instance. You must ' .
                'either configure different instances or ' .  'disable document ' .
                'synchronization.'
            );
        }
    }

    private function log($message)
    {
        if (null === $this->logger) {
            return;
        }

        $this->logger->info($message);
    }
}
