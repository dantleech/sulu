<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Document\Syncronization;

use PHPCR\Util\PathHelper;
use Sulu\Component\Content\Document\Behavior\SynchronizeBehavior;
use Sulu\Component\DocumentManager\Behavior\Mapping\ParentBehavior;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\DocumentManagerContext;
use Sulu\Bundle\ContentBundle\Document\RouteDocument;
use PHPCR\PropertyType;
use PHPCR\NodeInterface;

/**
 * Class responsible for registering a document from the source document manager
 * with the target document manager including any documents dependent on
 * the document to be synchronized.
 */
class DocumentRegistrator
{
    /**
     * Register the incoming SDM document with any existing PHPCR node in the
     * TDM.
     *
     * If the TDM already has the incoming PHPCR node then we need to register
     * the existing PHPCR node from the TDM PHPCR session with the incoming SDM
     * document (otherwise the system will attempt to create a new document and
     * fail).
     *
     * We also ensure that any immediately related documents are also registered.
     *
     * @param SynchronizeBehavior $document
     */
    public function registerDocumentWithTDM(
        SynchronizeBehavior $document, 
        DocumentManagerContext $sourceContext, 
        DocumentManagerContext $targetContext
    )
    {
        $this->registerSingleDocumentWithTDM($document, $sourceContext, $targetContext);

        $metadata = $sourceContext->getMetadataFactory()->getMetadataForClass(get_class($document));
        // iterate over the field mappings for the document, if they resolve to
        // an object then try and register it with the TDM.
        foreach (array_keys($metadata->getFieldMappings()) as $field) {
            $propertyValue = $metadata->getFieldValue($document, $field);

            if (false === is_object($propertyValue)) {
                continue;
            }

            // if the source document manager does not have this object then it is
            // not a candidate for being persisted (e.g. it might be a \DateTime
            // object).
            if (false === $sourceContext->getRegistry()->hasDocument($propertyValue)) {
                continue;
            }

            $this->registerSingleDocumentWithTDM($propertyValue, $sourceContext, $targetContext, true);
        }

        // TODO: Workaround for the fact that "parent" is not in the metadata,
        // see: https://github.com/sulu-io/sulu-document-manager/issues/67
        if ($document instanceof ParentBehavior) {
            if ($parent = $document->getParent()) {
                $this->registerSingleDocumentWithTDM($parent, $sourceContext, $targetContext, true);
            }
        }
    }

    private function registerSingleDocumentWithTDM(
        $document, 
        DocumentManagerContext $sourceContext,
        DocumentManagerContext $targetContext,
        $create = false
    )
    {
        $sdmInspector = $sourceContext->getInspector();
        $tdmRegistry = $targetContext->getRegistry();
        $node = null;

        // if the TDM registry already has the document, then
        // there is nothing to do - the document manager will
        // handle the rest.
        if (true === $tdmRegistry->hasDocument($document)) {
            return;
        }

        // see if we can resolve the corresponding node in the TDM.
        // if we cannot then we either return and let the document
        // manager create the new node, or, if $create is true, create
        // the missing node (this happens when registering a document
        // which is a relation to the incoming SDM document).
        if (false === $uuid = $this->resolveTDMUUID($document, $sourceContext, $targetContext)) {
            if (false === $create) {
                return;
            }

            $node = $targetContext->getNodeManager()->createPath(
                $sdmInspector->getPath($document), $sdmInspector->getUuid($document)
            );
        }

        // register the SDM document against the TDM PHPCR node.
        $node = $node ?: $targetContext->getNodeManager()->find($uuid);
        $locale = $sdmInspector->getLocale($document);
        $tdmRegistry->registerDocument(
            $document,
            $node,
            $locale
        );
    }

    /**
     * If possible, resolve the UUID of the node in the TDM corresponding to
     * the SDM node.
     *
     * If the UUID does not exist, we check to see if the path exsits.
     * if neither the path or UUID exist, then the TDM should create a new
     * document and we ensure that the PARENT path exists and if it doesn't
     * we syncronize the ancestor nodes from the SDM.
     *
     * We return FALSE in the case that a new document should be created.
     *
     * In the case the UUID does not exist we assume that in a valid system
     * that path will also NOT EXIST. If the path does exist, then it means that
     * the corresponding PHPCR nodes were created independently of each
     * other and bypassed the syncrhonization system and we throw an exception.
     *
     * @throws \RuntimeException If the UUID could not be resolved and it would be
     *                           invalid to implicitly allow the node to be created.
     */
    private function resolveTDMUUID($object, $sourceContext, $targetContext)
    {
        $tdmNodeManager = $targetContext->getNodeManager();
        $sdmInspector = $sourceContext->getInspector();
        $uuid = $sdmInspector->getUUid($object);

        if (true === $tdmNodeManager->has($uuid)) {
            return $uuid;
        }

        $path = $sdmInspector->getPath($object);

        if (false === $tdmNodeManager->has($path)) {

            // if the parent path also does not exist in the TDM then we need
            // to create the parent path using the same UUIDs that are used in
            // the SDM.
            $parentPath = PathHelper::getParentPath($path);
            if (false === $tdmNodeManager->has($parentPath)) {
                $this->syncTDMPath($parentPath, $sourceContext, $targetContext);

                return false;
            }

            return false;
        }

        throw new \RuntimeException(sprintf(
            'Publish document manager already has a node at path "%s" but ' .
            'incoming UUID `%s` does not match existing UUID: "%s".',
            $path, $uuid, $tdmNodeManager->find($path)->getIdentifier()
        ));
    }

    /**
     * Sync the given path from the SDM to the TDM, preserving
     * the UUIDs.
     *
     * @param string $path
     */
    private function syncTDMPath($path, $sourceContext, $targetContext)
    {
        $sdmNodeManager = $sourceContext->getNodeManager();
        $tdmNodeManager = $targetContext->getNodeManager();
        $segments = explode('/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            $stack[] = $segment;

            if ($segment == '') {
                continue;
            }

            $path = implode('/', $stack) ?: '/';
            $sdmNode = $sdmNodeManager->find($path);
            $tdmNodeManager->createPath($path, $sdmNode->getIdentifier());
        }

        // jackalope, at time of writing, will not register the UUID against
        // the node when the UUID is set on a property, meaning that we cannot
        // reference it by UUID until the session is flushed.
        //
        // upstream fix: https://github.com/jackalope/jackalope/pull/307
        $tdmNodeManager->save();
    }
}
