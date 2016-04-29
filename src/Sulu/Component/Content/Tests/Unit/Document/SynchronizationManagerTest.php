<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Tests\Unit\Document;

use PHPCR\NodeInterface;
use Prophecy\Argument;
use Sulu\Bundle\ContentBundle\Document\RouteDocument;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentManagerRegistry;
use Sulu\Bundle\DocumentManagerBundle\Bridge\PropertyEncoder;
use Sulu\Component\Content\Document\Behavior\ResourceSegmentBehavior;
use Sulu\Component\Content\Document\Behavior\SynchronizeBehavior;
use Sulu\Component\Content\Document\SynchronizationManager;
use Sulu\Component\Content\Document\Syncronization\DocumentRegistrator;
use Sulu\Component\DocumentManager\Behavior\Mapping\LocaleBehavior;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\NodeManager;
use Sulu\Bundle\ContentBundle\Document\PageDocument;
use Sulu\Component\Content\Document\Syncronization\Mapping;
use Sulu\Component\DocumentManager\DocumentManagerContext;


/**
 * Abbreviations:.
 *
 * - TDM: Push document manager.
 * - SDM: Default document manager.
 */
class SynchronizationManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ManagerRegistry
     */
    private $managerRegistry;

    /**
     * @var PropertyEncoder
     */
    private $propertyEncoder;

    /**
     * @var DocumentManagerInterface
     */
    private $sdm;

    /**
     * @var DocumentRegistrator
     */
    private $registrator;

    /**
     * @var DocumentManagerInterface
     */
    private $tdm;

    /**
     * @var DocumentInspector
     */
    private $sdmInspector;

    /**
     * @var NodeInterface
     */
    private $sdmNode1;

    public function setUp()
    {
        $this->managerRegistry = $this->prophesize(DocumentManagerRegistry::class);
        $this->propertyEncoder = $this->prophesize(PropertyEncoder::class);
        $this->registrator = $this->prophesize(DocumentRegistrator::class);
        $this->sdm = $this->prophesize(DocumentManagerInterface::class);
        $this->tdm = $this->prophesize(DocumentManagerInterface::class);
        $this->sdmContext = $this->prophesize(DocumentManagerContext::class);
        $this->tdmContext = $this->prophesize(DocumentManagerContext::class);
        $this->route1 = $this->prophesize(RouteDocument::class)
            ->willImplement(SynchronizeBehavior::class);
        $this->sdmInspector = $this->prophesize(DocumentInspector::class);
        $this->sdmNode1 = $this->prophesize(NodeInterface::class);
        $this->sdmNode2 = $this->prophesize(NodeInterface::class);
        $this->mapping = $this->prophesize(Mapping::class);
        $this->nodeManager = $this->prophesize(NodeManager::class);

        $this->sdmContext->getInspector()->willReturn($this->sdmInspector->reveal());
        $this->sdmContext->getName()->willReturn('sdm');
        $this->sdmContext->getManager()->willReturn($this->sdm->reveal());
        $this->tdmContext->getName()->willReturn('tdm');
        $this->tdmContext->getManager()->willReturn($this->tdm->reveal());

        $this->syncManager = new SynchronizationManager(
            $this->managerRegistry->reveal(),
            $this->propertyEncoder->reveal(),
            'live',
            $this->mapping->reveal(),
            $this->registrator->reveal()
        );
    }

    /**
     * It should synchronize a document to the target document manager.
     * It should register the fact that the document is synchronized with the TDM.
     * It should NOT localize the PHPCR property for a non-localized document.
     */
    public function testPush()
    {
        $document = new TestDocument([]);

        $this->managerRegistry->getContext()->willReturn($this->sdmContext->reveal());
        $this->managerRegistry->getContext('live')->willReturn($this->tdmContext->reveal());

        $this->sdmInspector->getUuid($document)->willReturn('1234');
        $this->sdmInspector->getOriginalLocale($document)->willReturn('de');
        $this->sdmInspector->getLocale($document)->willReturn('fr');
        $this->sdmInspector->getPath($document)->willReturn('/path/1');
        $this->sdmInspector->getNode($document)->willReturn($this->sdmNode1->reveal());

        $this->propertyEncoder->systemName(SynchronizeBehavior::SYNCED_FIELD)->shouldBeCalled();
        $this->registrator->registerDocumentWithTDM(
            $document,
            $this->sdmContext->reveal(),
            $this->tdm->reveal()
        )->shouldBeCalled();
        $this->tdm->persist(
            $document,
            'fr',
            [
                'safe' => true,
                'path' => '/path/1',
            ]
        )->shouldBeCalled();

        $this->syncManager->push($document);
    }


    /**
     * It should throw an exception if target manager and source manager are
     * the same.
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Target and source managers are the same instance.
     */
    public function testSynchronizeFullPushAndDefaultManagersAreSame()
    {
        $this->managerRegistry->getContext()->willReturn($this->sdmContext->reveal());
        $this->managerRegistry->getContext('live')->willReturn($this->sdmContext->reveal());

        $this->tdm->persist(Argument::cetera())->shouldNotBeCalled();

        $this->syncManager->push(new TestDocument([]));
    }

    /**
     * It should cascade configured referrers for the document and synchronize them.
     */
    public function testSynchronizeRoutes()
    {
        $document = new TestDocument();

        $this->mapping->getCascadeReferrers($document)->willReturn([
            RouteDocument::class
        ]);
        $this->mapping->getCascadeReferrers($this->route1->reveal())->willReturn([
        ]);

        $this->managerRegistry->getContext()->willReturn($this->sdmContext->reveal());
        $this->managerRegistry->getContext('live')->willReturn($this->tdm->reveal());

        // return one route and one stdClass (the stdClass should be filtered)
        $this->sdmInspector->getReferrers($document)->willReturn([
            $this->route1->reveal(),
            new \stdClass(),
        ]);
        $this->sdmInspector->getReferrers($this->route1->reveal())->willReturn([]);

        // neither document nor route are currently synchronized
        $this->route1->getSynchronizedManagers()->willReturn([]);

        $this->sdmInspector->getLocale($document)->willReturn('fr');
        $this->sdmInspector->getPath($document)->willReturn('/');
        $this->sdmInspector->getNode($document)->willReturn($this->sdmNode1->reveal());

        $this->sdmInspector->getLocale($this->route1->reveal())->willReturn('fr');
        $this->sdmInspector->getPath($this->route1->reveal())->willReturn('/path/1');
        $this->sdmInspector->getNode($this->route1->reveal())->willReturn($this->sdmNode2->reveal());
        $this->sdmInspector->getUuid($document)->willReturn('1234');

        $this->tdm->getNodeManager()->willReturn($this->nodeManager->reveal());
        $this->nodeManager->has('1234')->willReturn(false);

        // persist should be called once for both the document and the route object
        $this->tdm->persist($this->route1->reveal(), 'fr', [ 'safe' => true, 'path' => '/path/1' ])
            ->shouldBeCalled();
        $this->tdm->persist($document, 'fr', [ 'safe' => true, 'path' => '/' ])
            ->shouldBeCalled();

        $this->registrator->registerDocumentWithTDM(
            $document,
            $this->sdmContext->reveal(),
            $this->tdmContext->reveal()
        )->shouldBeCalled();
        $this->registrator->registerDocumentWithTDM(
            $this->route1->reveal(),
            $this->sdmContext->reveal(),
            $this->tdmContext->reveal()
        )->shouldBeCalled();

        $this->tdm->flush()->shouldNotBeCalled();
        $this->sdm->flush()->shouldNotBeCalled();

        $this->syncManager->push($document, [ 'cascade' => true ]);
    }

    /**
     * It should localize the PHPCR property if the document is localized.
     */
    public function testLocalizedPhpcrSyncedProperty()
    {
        $document = new LocalizedTestDocument([], 'fr');
        $this->managerRegistry->getContext()->willReturn($this->sdmContext->reveal());
        $this->managerRegistry->getContext('live')->willReturn($this->tdm->reveal());

        $this->sdmInspector->getLocale($document)->willReturn('fr');
        $this->sdmInspector->getPath($document)->willReturn('/path/1');
        $this->sdmInspector->getNode($document)->willReturn($this->sdmNode1->reveal());

        $this->tdm->persist(Argument::cetera())->shouldBeCalled();
        $this->propertyEncoder->localizedSystemName(
            SynchronizeBehavior::SYNCED_FIELD,
            'fr'
        )->willReturn('foobar');
        $this->sdmNode1->setProperty('foobar', ['live'])->shouldBeCalled();

        $this->syncManager->push($document);
    }

    /**
     * It should not synchronize if force is false and the document believes that it is
     * already synchronized.
     */
    public function testDocumentBelievesItIsSynchronizedNoForce()
    {
        $document = new TestDocument(['live']);
        $this->managerRegistry->getContext()->willReturn($this->sdmContext->reveal());
        $this->managerRegistry->getContext('live')->willReturn($this->tdm->reveal());

        $this->tdm->persist(Argument::cetera())->shouldNotBeCalled();

        $this->syncManager->push($document);
    }

    /**
     * It should remove documents from the TDM.
     */
    public function testDocumentRemove()
    {
        $document = new TestDocument();
        $this->managerRegistry->getContext()->willReturn($this->sdmContext->reveal());
        $this->managerRegistry->getContext('live')->willReturn($this->tdm->reveal());

        $this->registrator->registerDocumentWithTDM(
            $document,
            $this->sdmContext->reveal(),
            $this->tdmContext->reveal()
        )->shouldBeCalled();
        $this->tdm->remove($document)->shouldBeCalled();
        $this->tdm->flush()->shouldBeCalled();

        $this->syncManager->remove($document, ['flush' => true]);
    }
}

/**
 * Remove this: see https://github.com/dantleech/sulu/pull/2
 */
class TestDocument implements SynchronizeBehavior
{
    private $synchronizedManagers;

    public function __construct(array $synchronizedManagers = [])
    {
        $this->synchronizedManagers = $synchronizedManagers;
    }

    public function getSynchronizedManagers()
    {
        return $this->synchronizedManagers;
    }
}

/**
 * Remove this: see https://github.com/dantleech/sulu/pull/2
 */
class LocalizedTestDocument extends TestDocument implements LocaleBehavior
{
    private $synchronizedManagers;
    private $locale;

    public function __construct(array $synchronizedManagers = [], $locale)
    {
        $this->synchronizedManagers = $synchronizedManagers;
        $this->locale = $locale;
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function setLocale($locale)
    {
    }
}
