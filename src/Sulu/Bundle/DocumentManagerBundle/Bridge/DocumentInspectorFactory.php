<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\DocumentManagerBundle\Bridge;

use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\DocumentManager\DocumentInspectorFactoryInterface;
use Sulu\Component\DocumentManager\DocumentManagerContext;
use Sulu\Component\DocumentManager\MetadataFactoryInterface;
use Sulu\Component\DocumentManager\NamespaceRegistry;
use Sulu\Component\DocumentManager\PathSegmentRegistry;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

class DocumentInspectorFactory implements DocumentInspectorFactoryInterface
{
    /**
     * @var DocumentManagerContext
     */
    private $context;

    private $namespaceRegistry;
    private $metadataFactory;
    private $structureFactory;
    private $encoder;
    private $webspaceManager;
    private $pathSegmentRegistry;

    public function __construct(
        PathSegmentRegistry $pathSegmentRegistry,
        NamespaceRegistry $namespaceRegistry,
        MetadataFactoryInterface $metadataFactory,
        StructureMetadataFactoryInterface $structureFactory,
        PropertyEncoder $encoder,
        WebspaceManagerInterface $webspaceManager
    ) {
        $this->pathSegmentRegistry = $pathSegmentRegistry;
        $this->namespaceRegistry = $namespaceRegistry;
        $this->metadataFactory = $metadataFactory;
        $this->structureFactory = $structureFactory;
        $this->encoder = $encoder;
        $this->webspaceManager = $webspaceManager;
        $this->pathSegmentRegistry = $pathSegmentRegistry;
    }

    public function attachContext(DocumentManagerContext $context)
    {
        $this->context = $context;
    }

    public function getInspector(DocumentManagerContext $context)
    {
        return new DocumentInspector(
            $context->getRegistry(),
            $this->pathSegmentRegistry,
            $this->namespaceRegistry,
            $context->getProxyFactory(),
            $this->metadataFactory,
            $this->structureFactory,
            $this->encoder,
            $this->webspaceManager
        );
    }
}
