<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Tests\Unit\Document\Synchronization;

use PHPCR\NodeInterface;
use Prophecy\Argument;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Component\Content\Document\Behavior\SynchronizeBehavior;
use Sulu\Component\Content\Document\Syncronization\DocumentRegistrator;
use Sulu\Component\DocumentManager\Behavior\Mapping\ParentBehavior;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\DocumentRegistry;
use Sulu\Component\DocumentManager\Metadata;
use Sulu\Component\DocumentManager\MetadataFactoryInterface;
use Sulu\Component\DocumentManager\NodeManager;

/**
 * Abbreviations:.
 *
 * - TDM: Publish document manager.
 * - SDM: Default document manager.
 */
class DocumentRegistratorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var DocumentManagerInterface
     */
    private $tdm;

    /**
     * @var DocumentManagerInterface
     */
    private $sdm;

    /**
     * @var DocumentRegistrator
     */
    private $registrator;

    /**
     * @var DocumentInspector
     */
    private $sdmInspector;

    /**
     * @var DocumentRegistry
     */
    private $sdmRegistry;

    /**
     * @var DocumentRegistry
     */
    private $tdmRegistry;

    /**
     * @var NodeManager
     */
    private $tdmNodeManager;

    /**
     * @var NodeManager
     */
    private $sdmNodeManager;

    /**
     * @var NodeInterface
     */
    private $sdmNode;

    /**
     * @var NodeInterface
     */
    private $sdmNode1;

    /**
     * @var NodeInterface
     */
    private $tdmNode;

    /**
     * @var SynchronizeBehavior
     */
    private $document;

    /**
     * @var SynchronizeBehavior
     */
    private $document1;

    /**
     * @var Metadata
     */
    private $metadata;

    /**
     * @var MetadataFactory
     */
    private $metadataFactory;

    public function setUp()
    {
        $this->tdm = $this->prophesize(DocumentManagerInterface::class);
        $this->sdm = $this->prophesize(DocumentManagerInterface::class);

        $this->registrator = new DocumentRegistrator(
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );

        $this->sdmInspector = $this->prophesize(DocumentInspector::class);
        $this->tdmInspector = $this->prophesize(DocumentInspector::class);
        $this->sdmRegistry = $this->prophesize(DocumentRegistry::class);
        $this->tdmRegistry = $this->prophesize(DocumentRegistry::class);
        $this->tdmNodeManager = $this->prophesize(NodeManager::class);
        $this->sdmNodeManager = $this->prophesize(NodeManager::class);
        $this->sdmNode = $this->prophesize(NodeInterface::class);
        $this->sdmNode1 = $this->prophesize(NodeInterface::class);
        $this->sdmNode2 = $this->prophesize(NodeInterface::class);
        $this->tdmNode = $this->prophesize(NodeInterface::class);
        $this->document = $this->prophesize(SynchronizeBehavior::class);
        $this->document1 = $this->prophesize(SynchronizeBehavior::class);
        $this->metadata = $this->prophesize(Metadata::class);
        $this->metadataFactory = $this->prophesize(MetadataFactoryInterface::class);

        $this->sdm->getMetadataFactory()->willReturn($this->metadataFactory->reveal());
        $this->sdm->getNodeManager()->willReturn($this->sdmNodeManager->reveal());
        $this->sdm->getRegistry()->willReturn($this->sdmRegistry->reveal());
        $this->sdm->getInspector()->willReturn($this->sdmInspector->reveal());
        $this->tdm->getRegistry()->willReturn($this->tdmRegistry->reveal());
        $this->tdm->getNodeManager()->willReturn($this->tdmNodeManager->reveal());
    }

    /**
     * If an equivilent document does not exist in the TDM then it should return.
     */
    public function testRegisterNewDocumentNotExisting()
    {
        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))
            ->willReturn($this->metadata->reveal());
        $this->metadata->getFieldMappings()->willReturn([]);
        $this->tdmRegistry->hasDocument($this->document->reveal())->willReturn(false);
        $this->sdmInspector->getUuid($this->document->reveal())->willReturn('1234');
        $this->sdmInspector->getPath($this->document->reveal())->willReturn('/path/to');
        $this->tdmNodeManager->has('1234')->willReturn(false);
        $this->tdmNodeManager->has('/path/to')->willReturn(false);
        $this->tdmNodeManager->has('/path')->willReturn(true);

        $this->sdmInspector->getLocale($this->document->reveal())->willReturn('fr');
        $this->tdmRegistry->registerDocument(Argument::cetera())->shouldNotBeCalled();
        $this->tdmNodeManager->find('1234')->willReturn($this->tdmNode->reveal());

        $this->registrator->registerDocumentWithTDM(
            $this->document->reveal(),
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );
    }

    /**
     * It should register a document with an existing node in the TDM.
     */
    public function testRegisterNewDocumentUuidExists()
    {
        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))
            ->willReturn($this->metadata->reveal());
        $this->metadata->getFieldMappings()->willReturn([]);
        $this->tdmRegistry->hasDocument($this->document->reveal())->willReturn(false);
        $this->sdmInspector->getUuid($this->document->reveal())->willReturn('1234');
        $this->sdmInspector->getPath($this->document->reveal())->willReturn('/path/to');
        $this->tdmNodeManager->has('1234')->willReturn(true);
        $this->tdmNodeManager->find('1234')->willReturn($this->tdmNode->reveal());

        $this->sdmInspector->getLocale($this->document->reveal())->willReturn('fr');
        $this->tdmRegistry->registerDocument(
            $this->document->reveal(),
            $this->tdmNode->reveal(),
            'fr'
        )->shouldBeCalled();

        $this->registrator->registerDocumentWithTDM(
            $this->document->reveal(),
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );
    }

    /**
     * If the TDM already has the document then it should return early.
     */
    public function testRegisterNewDocumentNotHasReturnEarly()
    {
        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))
            ->willReturn($this->metadata->reveal());
        $this->metadata->getFieldMappings()->willReturn([]);
        $this->tdmRegistry->hasDocument($this->document->reveal())->willReturn(true);

        $this->tdmRegistry->registerDocument(Argument::cetera())->shouldNotBeCalled();
        $this->registrator->registerDocumentWithTDM(
            $this->document->reveal(),
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );
    }

    /**
     * If the UUID and the path, and parent path do not exist in the TDM
     * then it should recursively create the ancestor nodes from the SDM
     * (retaining the same UUIDs).
     */
    public function testNoneOfUuidPathOrParentPathExist()
    {
        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))
            ->willReturn($this->metadata->reveal());
        $this->metadata->getFieldMappings()->willReturn([]);
        $this->tdmRegistry->hasDocument($this->document->reveal())->willReturn(false);

        $this->sdmInspector->getUuid($this->document->reveal())->willReturn('1234');
        $this->sdmInspector->getPath($this->document->reveal())->willReturn('/path/to/this');

        $this->tdmNodeManager->has('1234')->willReturn(false);
        $this->tdmNodeManager->has('/path/to/this')->willReturn(false);
        $this->tdmNodeManager->has('/path/to')->willReturn(false);

        $this->sdmNodeManager->find('/path')->willReturn($this->sdmNode1->reveal());
        $this->sdmNodeManager->find('/path/to')->willReturn($this->sdmNode2->reveal());
        $this->sdmNode1->getIdentifier()->willReturn(1);
        $this->sdmNode2->getIdentifier()->willReturn(2);

        $this->tdmNodeManager->createPath('/path', 1)->shouldBeCalled();
        $this->tdmNodeManager->createPath('/path/to', 2)->shouldBeCalled();
        $this->tdmNodeManager->save()->shouldBeCalled(); // save() hack, see comment in code.
        $this->tdmRegistry->registerDocument(Argument::cetera())->shouldNotBeCalled();

        $this->registrator->registerDocumentWithTDM(
            $this->document->reveal(),
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );
    }

    /**
     * If the UUID does not exist and the path DOES exist, then it should
     * throw an exception.
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Publish document manager already has a node
     */
    public function testUuidNotExistPathDoesExist()
    {
        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))
            ->willReturn($this->metadata->reveal());
        $this->metadata->getFieldMappings()->willReturn([]);
        $this->tdmRegistry->hasDocument($this->document->reveal())->willReturn(false);

        $this->sdmInspector->getUuid($this->document->reveal())->willReturn('1234');
        $this->sdmInspector->getPath($this->document->reveal())->willReturn('/path/to/this');

        $this->tdmNodeManager->has('1234')->willReturn(false);
        $this->tdmNodeManager->has('/path/to/this')->willReturn(true);
        $this->tdmNodeManager->find('/path/to/this')->willReturn($this->tdmNode->reveal());
        $this->tdmNode->getIdentifier()->willReturn('1234');

        $this->registrator->registerDocumentWithTDM(
            $this->document->reveal(),
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );
    }

    /**
     * It should update (managed) objects that are mapped to the document.
     * It should skip non-managed objects (e.g. \DateTime).
     * It should skip non-object properties.
     */
    public function testRegisterAssociatedDocuments()
    {
        // we only want to test the associations, so just say that the TDM
        // already has our primary document.
        $this->tdmRegistry->hasDocument($this->document->reveal())->willReturn(true);

        $this->metadataFactory->getMetadataForClass(get_class($this->document->reveal()))
            ->willReturn($this->metadata->reveal());
        $this->metadata->getFieldMappings()->willReturn([
            'one' => 1,
            'two' => 2,
            'three' => 3,
        ]);

        $this->metadata->getFieldValue($this->document->reveal(), 'one')
            ->willReturn('i-am-not-an-object');
        $this->metadata->getFieldValue($this->document->reveal(), 'two')
            ->willReturn($dateTime = new \DateTime());
        $this->metadata->getFieldValue($this->document->reveal(), 'three')
            ->willReturn($this->document1->reveal());

        $this->sdmRegistry->hasDocument($dateTime)->willReturn(false);
        $this->sdmRegistry->hasDocument($this->document1->reveal())->willReturn(true);

        // if we check with the TDM registry that document1 exists, then we are
        // already good.
        $this->tdmRegistry->hasDocument($this->document1->reveal())
            ->shouldBeCalled()
            ->willReturn(true);
        $this->registrator->registerDocumentWithTDM(
            $this->document->reveal(),
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );
    }

    /**
     * It should register the parent document if the document is implementing
     * the ParentBehavior.
     */
    public function testRegisterParentBehavior()
    {
        $document = $this->prophesize(SynchronizeBehavior::class)
            ->willImplement(ParentBehavior::class);

        // we only want to test the associations, so just say that the TDM
        // already has our primary document.

        $this->metadataFactory->getMetadataForClass(get_class($document->reveal()))
            ->willReturn($this->metadata->reveal());
        $this->metadata->getFieldMappings()->willReturn([]);

        $document->getParent()->willReturn($this->document1->reveal());

        $this->tdmRegistry->hasDocument($document->reveal())
            ->willReturn(true);
        $this->tdmRegistry->hasDocument($this->document1->reveal())
            ->willReturn(true);

        $this->registrator->registerDocumentWithTDM(
            $document->reveal(),
            $this->sdm->reveal(),
            $this->tdm->reveal()
        );
    }
}
