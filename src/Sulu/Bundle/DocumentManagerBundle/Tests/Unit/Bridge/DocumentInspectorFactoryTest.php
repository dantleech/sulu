<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\DocumentManagerBundle\Tests\Unit\Bridge;

use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspectorFactory;
use Sulu\Bundle\DocumentManagerBundle\Bridge\PropertyEncoder;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\DocumentRegistry;
use Sulu\Component\DocumentManager\MetadataFactoryInterface;
use Sulu\Component\DocumentManager\NamespaceRegistry;
use Sulu\Component\DocumentManager\PathSegmentRegistry;
use Sulu\Component\DocumentManager\ProxyFactory;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

class DocumentInspectorFactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var DocumentRegistry
     */
    private $documentRegistry;

    /**
     * @var PathSegmentRegistry
     */
    private $pathRegistry;

    /**
     * @var NamespaceRegistry
     */
    private $namespaceRegistry;

    /**
     * @var MetadataFactoryInterface
     */
    private $metadataFactory;

    /**
     * @var StructureMetadataFactory
     */
    private $structureFactory;

    /**
     * @var ProxyFactory
     */
    private $proxyFactory;

    /**
     * @var PropertyEncoder
     */
    private $encoder;

    /**
     * @var WebspaceManager
     */
    private $webspaceManager;

    /**
     * @var DocumentInspectorFactory
     */
    private $factory;

    public function setUp()
    {
        $this->documentRegistry = $this->prophesize(DocumentRegistry::class);
        $this->pathRegistry = $this->prophesize(PathSegmentRegistry::class);
        $this->namespaceRegistry = $this->prophesize(NamespaceRegistry::class);
        $this->metadataFactory = $this->prophesize(MetadataFactoryInterface::class);
        $this->structureFactory = $this->prophesize(StructureMetadataFactoryInterface::class);
        $this->proxyFactory = $this->prophesize(ProxyFactory::class);
        $this->encoder = $this->prophesize(PropertyEncoder::class);
        $this->webspaceManager = $this->prophesize(WebspaceManagerInterface::class);

        $this->manager1 = $this->prophesize(DocumentManagerInterface::class);
        $this->manager1->getProxyFactory()->willReturn($this->proxyFactory->reveal());
        $this->manager1->getRegistry()->willReturn($this->documentRegistry->reveal());

        $this->manager2 = $this->prophesize(DocumentManagerInterface::class);
        $this->manager2->getProxyFactory()->willReturn($this->proxyFactory->reveal());
        $this->manager2->getRegistry()->willReturn($this->documentRegistry->reveal());

        $this->factory = new DocumentInspectorFactory(
            $this->pathRegistry->reveal(),
            $this->namespaceRegistry->reveal(),
            $this->metadataFactory->reveal(),
            $this->structureFactory->reveal(),
            $this->encoder->reveal(),
            $this->webspaceManager->reveal()
        );
    }

    /**
     * It should return a document inspector.
     * It should always return the same instance.
     */
    public function testGetInspector()
    {
        $inspector = $this->factory->getInspector($this->manager1->reveal());

        $this->assertInstanceOf(
            DocumentInspector::class,
            $inspector
        );

        $this->assertSame(
            $inspector,
            $this->factory->getInspector($this->manager1->reveal())
        );
    }

    /**
     * It should return different instances for different document manager1s.
     */
    public function testGetInspectorDifferentInstance()
    {
        $inspector1 = $this->factory->getInspector($this->manager1->reveal());
        $inspector2 = $this->factory->getInspector($this->manager2->reveal());

        $this->assertNotSame(
            $inspector1,
            $inspector2
        );
    }
}
