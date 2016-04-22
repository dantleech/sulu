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

/**
 * The synchronization manager handles the synchronization of documents
 * from the DEFAULT document manger to the PUBLISH document manager.
 *
 * NOTE: In the future multiple target document managers may be supported.
 */
class SynchronizationManager
{
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
    private $targetManagerName;

    /**
     * @var DocumentRegistrator
     */
    private $registrator;

    /**
     * @var array
     */
    private $mapping;

    public function __construct(
        DocumentManagerRegistry $registry,
        PropertyEncoder $encoder,
        $targetManagerName,
        Mapping $mapping,
        $registrator = null
    ) {
        $this->registry = $registry;
        $this->targetManagerName = $targetManagerName;
        $this->encoder = $encoder;
        $this->mapping = $mapping;
        $this->registrator = $registrator ?: new DocumentRegistrator(
            $registry->getManager(),
            $registry->getManager($this->targetManagerName)
        );
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
    public function getTargetDocumentManager()
    {
        return $this->registry->getManager($this->targetManagerName);
    }

    public function getDefaultDocumentManager()
    {
        return $this->registry->getManager();
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
        $sourceManager = $this->registry->getManager();
        $targetManager = $this->registry->getManager($this->targetManagerName);

        $this->synchronize($document, $sourceManager, $targetManager, $options);
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
        $defaultManager = $this->getDefaultDocumentManager();
        $targetManager = $this->getTargetDocumentManager();

        $locale = $defaultManager->getInspector()->getLocale($document);
        $uuid = $defaultManager->getInspector()->getUuid($document);

        // load the document in the target state
        $targetManager->find($uuid, $locale);

        $this->synchronize($document, $targetManager, $defaultManager, $options);
    }

    private function synchronize(SynchronizeBehavior $document, $sourceManager, $targetManager, array $options = [])
    {
        $options = array_merge([
            'force' => false,
            'cascade' => false,
            'flush' => false,
        ], $options);

        $this->assertDifferentManagerInstances($sourceManager, $targetManager);

        if (false === $options['force'] && $this->isDocumentSynchronized($document)) {
            return;
        }

        $inspector = $sourceManager->getInspector();
        $locale = $inspector->getLocale($document);
        $path = $inspector->getPath($document);

        // register the SDM document and its immediate relations with the TDM
        // PHPCR node.
        $this->registrator->registerDocumentWithTDM($document, $sourceManager, $targetManager);

        // save the document with the "publish" document manager.
        $targetManager->persist(
            $document,
            $locale,
            [
                'safe' => true,
                'path' => $path,
            ]
        );
        // the document is now synchronized with the publish workspace...

        if ($options['cascade']) {
            $this->cascadeRelations($document, $sourceManager, $targetManager, $options);
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
        $synced[] = $this->targetManagerName;

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
            $sourceManager->flush();
            $targetManager->flush();
        }
    }

    public function remove($document, array $options = [])
    {
        $sourceManager = $this->getDefaultDocumentManager();
        $targetManager = $this->getTargetDocumentManager();

        return $this->doRemove($document, $sourceManager, $targetManager, $options);
    }

    private function doRemove($document, $sourceManager, $targetManager, array $options)
    {
        $options = array_merge([
            'flush' => false,
        ], $options);

        // Flush the target manager.
        //
        // TODO: This should not be necessary, but without it jackalope seems
        //       to have some state problems, possibly related to:
        //       https://github.com/jackalope/jackalope/pull/309
        $targetManager->flush();

        $this->registrator->registerDocumentWithTDM($document, $sourceManager, $targetManager);
        $targetManager->remove($document);

        if ($options['flush']) {
            $targetManager->flush();
        }
    }

    private function cascadeRelations($document, $sourceManager, $targetManager, array $options)
    {
        $cascadeFqns = $this->mapping->getCascadeReferrers($document);

        if (empty($cascadeFqns)) {
            return;
        }

        $referrers = $sourceManager->getInspector()->getReferrers($document);
        $sourceReferrerOoids = [];
        foreach ($referrers as $referrer) {
            $sourceReferrerOoids[] = spl_object_hash($referrer);
            foreach ($cascadeFqns as $cascadeFqn) {
                // if the referrer does not an instance of the mapped cascade
                // class, continue.
                if (false === $this->isInstanceOf($cascadeFqn, $referrer)) {
                    continue;
                }

                $options['flush'] = false;
                $this->synchronize($referrer, $sourceManager, $targetManager, $options);
            }
        }

        $uuid = $sourceManager->getInspector()->getUuid($document);

        if (false === $targetManager->getNodeManager()->has($uuid)) {
            return;
        }

        $referrers = $targetManager->getInspector()->getReferrers($document);

        foreach ($referrers as $referrer) {
            if (in_array(spl_object_hash($referrer), $sourceReferrerOoids)) {
                continue;
            }

            $this->doRemove($referrer, $sourceManager, $targetManager, []);
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
        return in_array($this->targetManagerName, $synced);
    }

    private function assertDifferentManagerInstances(DocumentManagerInterface $manager1, DocumentManagerInterface $manager2)
    {
        if ($manager1=== $manager2) {
            throw new \RuntimeException(
                'Target and source managers are the same instance. You must ' .
                'either configure different instances or ' .  'disable document ' .
                'synchronization.'
            );
        }
    }
}
