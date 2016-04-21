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
 * NOTE: In the future multiple publish document managers may be supported.
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
    private $publishManagerName;

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
        $publishManagerName,
        Mapping $mapping,
        $registrator = null
    ) {
        $this->registry = $registry;
        $this->publishManagerName = $publishManagerName;
        $this->encoder = $encoder;
        $this->mapping = $mapping;
        $this->registrator = $registrator ?: new DocumentRegistrator(
            $registry->getManager(),
            $registry->getManager($this->publishManagerName)
        );
    }

    /**
     * Return the publish document manager (PDM).
     *
     * This should be the only class that is aware of the PDM name. By having
     * this method we can be sure that whatever the PDM is, the PDM is always
     * the PDM.
     *
     * NOTE: This is used only by the synchronization subscriber in order
     *       to "flush" the PDM.
     *
     * @return DocumentManagerInterface
     */
    public function getPublishDocumentManager()
    {
        return $this->registry->getManager($this->publishManagerName);
    }

    /**
     * Synchronize a single document to the publish document manager in the
     * documents currently registered locale.
     *
     * FLUSH will not be called and no associated documents will be
     * synchronized.
     *
     * Options:
     *
     * - `force`  : Force the synchronization, do not skip if the system thinks
     *              the target document is already synchronized.
     * - `cascade`: Cascade the synchronization to any configured relations.
     * - `flush`  : Flush both document managers after synchronization (as the calling
     *              code may not have access to them).
     *
     * TODO: Add an explicit "locale" option?
     *
     * @param SynchronizeBehavior $document
     * @param bool $force
     */
    public function synchronize(SynchronizeBehavior $document, array $options = [])
    {
        $options = array_merge([
            'force' => false,
            'cascade' => false,
            'flush' => false,
        ], $options);

        $defaultManager = $this->registry->getManager();
        $publishManager = $this->getPublishDocumentManager();

        $this->assertDifferentManagerInstances($defaultManager, $publishManager);

        if ($options['force'] || $this->isDocumentSynchronized($document)) {
            return;
        }

        $inspector = $defaultManager->getInspector();
        $locale = $inspector->getLocale($document);
        $path = $inspector->getPath($document);

        // register the DDM document and its immediate relations with the PDM
        // PHPCR node.
        $this->registrator->registerDocumentWithPDM($document);

        // save the document with the "publish" document manager.
        $publishManager->persist(
            $document,
            $locale,
            [
                'path' => $path,
            ]
        );
        // the document is now synchronized with the publish workspace...

        if ($options['cascade']) {
            $this->cascadeRelations($document, $options);
        }

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
        $synced[] = $this->publishManagerName;

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
            $defaultManager->flush();
            $publishManager->flush();
        }
    }

    public function remove($document, array $options = [])
    {
        $options = array_merge([
            'flush' => false,
        ], $options);

        $publishManager = $this->getPublishDocumentManager();
        $this->registrator->registerDocumentWithPDM($document);
        $publishManager->remove($document);

        if ($options['flush']) {
            $publishManager->flush();
        }
    }

    private function cascadeRelations($document, array $options)
    {
        $cascadeFqns = $this->mapping->getCascadeReferrers($document);

        if (empty($cascadeFqns)) {
            return;
        }

        $referrers = $this->registry->getManager()->getInspector()->getReferrers($document);
        foreach ($referrers as $referrer) {
            foreach ($cascadeFqns as $cascadeFqn) {
                // if the referrer does not an instance of the mapped cascade
                // class, continue.
                if (false === $this->isInstanceOf($cascadeFqn, $referrer)) {
                    continue;
                }

                $options['flush'] = false;
                $this->synchronize($referrer, $options);
            }
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
        // synced with the publish document manager.
        return in_array($this->publishManagerName, $synced);
    }

    private function assertDifferentManagerInstances(DocumentManagerInterface $manager1, DocumentManagerInterface $manager2)
    {
        // if the publish manager and default manager are the same, then there is nothing to do here.
        // NOTE: Should we throw an exception here? as we will introduce the ability to completely disable
        //       this feature.
        if ($manager1=== $manager2) {
            throw new \RuntimeException(
                'Published and default managers are the same instance. You must ' .
                'either configure different instances or ' .  'disable document ' .
                'synchronization.'
            );
        }
    }
}
