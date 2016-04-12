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
use Sulu\Bundle\ContentBundle\Document\PageDocument;

/**
 * Abbreviations:.
 *
 * - PDM: Publish document manager.
 * - DDM: Default document manager.
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
    private $ddm;

    /**
     * @var DocumentRegistrator
     */
    private $registrator;

    /**
     * @var DocumentManagerInterface
     */
    private $pdm;

    /**
     * @var DocumentInspector
     */
    private $ddmInspector;

    /**
     * @var NodeInterface
     */
    private $ddmNode1;

    public function setUp()
    {
        $this->managerRegistry = $this->prophesize(DocumentManagerRegistry::class);
        $this->propertyEncoder = $this->prophesize(PropertyEncoder::class);
        $this->registrator = $this->prophesize(DocumentRegistrator::class);
        $this->ddm = $this->prophesize(DocumentManagerInterface::class);
        $this->pdm = $this->prophesize(DocumentManagerInterface::class);
        $this->route1 = $this->prophesize(RouteDocument::class)
            ->willImplement(SynchronizeBehavior::class);
        $this->ddmInspector = $this->prophesize(DocumentInspector::class);
        $this->ddmNode1 = $this->prophesize(NodeInterface::class);
        $this->ddmNode2 = $this->prophesize(NodeInterface::class);

        $this->ddm->getInspector()->willReturn($this->ddmInspector->reveal());
    }

    /**
     * It should synchronize a document to the publish document manager.
     * It should register the fact that the document is synchronized with the PDM.
     * It should NOT localize the PHPCR property for a non-localized document.
     */
    public function testPublish()
    {
        $document = new TestDocument([]);

        $this->managerRegistry->getManager()->willReturn($this->ddm->reveal());
        $this->managerRegistry->getManager('live')->willReturn($this->pdm->reveal());

        $this->ddmInspector->getLocale($document)->willReturn('fr');
        $this->ddmInspector->getPath($document)->willReturn('/path/1');
        $this->ddmInspector->getNode($document)->willReturn($this->ddmNode1->reveal());

        $this->propertyEncoder->systemName(SynchronizeBehavior::SYNCED_FIELD)->shouldBeCalled();
        $this->pdm->persist(
            $document,
            'fr',
            [
                'path' => '/path/1',
            ]
        )->shouldBeCalled();

        $this->createSyncManager()->synchronize($document);
    }


    /**
     * It should return early if publish manager and default manager are
     * the same.
     */
    public function testSynchronizeFullPublishAndDefaultManagersAreSame()
    {
        $this->managerRegistry->getManager()->willReturn($this->ddm->reveal());
        $this->managerRegistry->getManager('live')->willReturn($this->ddm->reveal());

        $this->pdm->persist(Argument::cetera())->shouldNotBeCalled();

        $this->createSyncManager()->synchronize(new TestDocument([]));
    }

    /**
     * It should cascade configured referrers for the document and synchronize them.
     */
    public function testSynchronizeRoutes()
    {
        $document = new TestDocument();

        $this->managerRegistry->getManager()->willReturn($this->ddm->reveal());
        $this->managerRegistry->getManager('live')->willReturn($this->pdm->reveal());

        // return one route and one stdClass (the stdClass should be filtered)
        $this->ddmInspector->getReferrers($document)->willReturn([
            $this->route1->reveal(),
            new \stdClass(),
        ]);
        $this->ddmInspector->getReferrers($this->route1->reveal())->willReturn([]);

        // neither document nor route are currently synchronized
        $this->route1->getSynchronizedManagers()->willReturn([]);

        $this->ddmInspector->getLocale($document)->willReturn('fr');
        $this->ddmInspector->getPath($document)->willReturn('/');
        $this->ddmInspector->getNode($document)->willReturn($this->ddmNode1->reveal());

        $this->ddmInspector->getLocale($this->route1->reveal())->willReturn('fr');
        $this->ddmInspector->getPath($this->route1->reveal())->willReturn('/path/1');
        $this->ddmInspector->getNode($this->route1->reveal())->willReturn($this->ddmNode2->reveal());

        // persist should be called once for both the document and the route object
        $this->pdm->persist($this->route1->reveal(), 'fr', [ 'path' => '/path/1' ])
            ->shouldBeCalled();
        $this->pdm->persist($document, 'fr', [ 'path' => '/' ])
            ->shouldBeCalled();

        $this->pdm->flush()->shouldNotBeCalled();
        $this->ddm->flush()->shouldNotBeCalled();

        $this->createSyncManager([
            SynchronizeBehavior::class => [
                RouteDocument::class
            ]
        ])->synchronize($document, [ 'cascade' => true ]);
    }

    /**
     * It should return early if the default and publish manager are the same.
     */
    public function testSameDefaultAndPublishManagers()
    {
        $this->managerRegistry->getManager()->willReturn($this->ddm->reveal());
        $this->managerRegistry->getManager('live')->willReturn($this->ddm->reveal());

        $this->pdm->persist(Argument::cetera())->shouldNotBeCalled();

        $this->createSyncManager()->synchronize(new TestDocument());
    }

    /**
     * It should localize the PHPCR property if the document is localized.
     */
    public function testLocalizedPhpcrSyncedProperty()
    {
        $document = new LocalizedTestDocument([], 'fr');
        $this->managerRegistry->getManager()->willReturn($this->ddm->reveal());
        $this->managerRegistry->getManager('live')->willReturn($this->pdm->reveal());

        $this->ddmInspector->getLocale($document)->willReturn('fr');
        $this->ddmInspector->getPath($document)->willReturn('/path/1');
        $this->ddmInspector->getNode($document)->willReturn($this->ddmNode1->reveal());

        $this->pdm->persist(Argument::cetera())->shouldBeCalled();
        $this->propertyEncoder->localizedSystemName(
            SynchronizeBehavior::SYNCED_FIELD,
            'fr'
        )->willReturn('foobar');
        $this->ddmNode1->setProperty('foobar', ['live'])->shouldBeCalled();

        $this->createSyncManager()->synchronize($document);
    }

    /**
     * It should not synchronize if force is false and the document believes that it is
     * already synchronized.
     */
    public function testDocumentBelievesItIsSynchronizedNoForce()
    {
        $document = new TestDocument(['live']);
        $this->managerRegistry->getManager()->willReturn($this->ddm->reveal());
        $this->managerRegistry->getManager('live')->willReturn($this->pdm->reveal());

        $this->pdm->persist(Argument::cetera())->shouldNotBeCalled();

        $this->createSyncManager()->synchronize($document);
    }

    private function createSyncManager(array $cascadeMap = [])
    {
        return new SynchronizationManager(
            $this->managerRegistry->reveal(),
            $this->propertyEncoder->reveal(),
            'live',
            $cascadeMap,
            $this->registrator->reveal()
        );

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
