<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ContentBundle\Tests\Functional\Document;

use Sulu\Bundle\ContentBundle\Document\PageDocument;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;

class SyncronizationManagerTest extends SuluTestCase
{
    /**
     * @var mixed
     */
    private $manager;

    /**
     * @var mixed
     */
    private $syncManager;

    /**
     * @var mixed
     */
    private $publishDocumentManager;

    public function setUp()
    {
        $kernel = $this->getKernel([
            'environment' => 'multiple_document_managers',
        ]);

        $this->manager = $kernel->getContainer()->get('sulu_document_manager.document_manager');
        $this->syncManager = $kernel->getContainer()->get('sulu_content.document.synchronization_manager');
        $this->publishDocumentManager = $this->syncManager->getPublishDocumentManager();
        $this->initPhpcr($kernel);
        $this->parent = $this->manager->find('/cmf/sulu_io/contents', 'de');
    }

    /**
     * Assert that the test system is confgiured to use two separate document managers.
     */
    public function testSystemUsesTwoDocumentManagers()
    {
        $this->assertNotSame($this->manager, $this->syncManager->getPublishDocumentManager());
    }

    /**
     * New documents are automatically synced via. the subscriber.
     */
    public function testAutomaticSync()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);
        $page->setResourceSegment('/bar');

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->manager->find($page->getUuid(), 'de');
        $this->assertEquals(['live'], $page->getSynchronizedManagers());

        $this->assertExistsInPublishDocumentManager($page);

        $route = $this->publishDocumentManager->find('/cmf/sulu_io/routes/de/bar');
        $this->assertNotNull($route);
    }

    /**
     * It should update the published document when synchronized action is invoked.
     */
    public function testSynchronize()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);
        $page->setResourceSegment('/bar');

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $page->setTitle('Barbar');
        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->syncManager->synchronize($page, [ 'flush' => true ]);
        $this->assertExistsInPublishDocumentManager($page);

        $page = $this->publishDocumentManager->find($page->getUuid(), 'de');
        $this->assertEquals('Barbar', $page->getTitle());
    }

    /**
     * It should update the related routes (cascade) when the document is synchronized.
     */
    public function testSynchronizeCascade()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);
        $page->setResourceSegment('/bar');

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $page->setTitle('Barbar');
        $page->setResourceSegment('/new-route');
        $this->manager->persist($page, 'de');
        $this->manager->flush();

        // assert that both page and route, having been updated,
        // no longer think they are synchronized.
        $page = $this->manager->find($page->getUuid(), 'de');
        $this->assertEmpty($page->getSynchronizedManagers());

        // synchronize the page, and cascade.
        $this->syncManager->synchronize($page, [ 'cascade' => true, 'flush' => true ]);

        $this->assertEquals($page->getSynchronizedManagers(), [ 'live' ]);
        $this->assertExistsInPublishDocumentManager($page);

        $page = $this->publishDocumentManager->find($page->getUuid(), 'de');
        $this->assertEquals('Barbar', $page->getTitle());

        // the old route should have been updated and it should now be a
        // "history" route.
        //
        // TODO: We should not couple the test to this behavior, but the overhead
        //       of creating a new document is too great for now.
        //       see: https://github.com/sulu/sulu-document-manager/issues/73
        $route = $this->publishDocumentManager->find('/cmf/sulu_io/routes/de/bar');
        $this->assertTrue($route->isHistory());
    }

    /**
     * It should publish documents that have been moved in the default document manager.
     */
    public function testMovedInDefault()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);
        $page->setResourceSegment('/bar');

        $this->manager->persist($page, 'de');
        $this->manager->flush();
        $this->manager->getNodeManager()->createPath('/cmf/sulu_io/contents/foo/bar');
        $this->manager->move($page, '/cmf/sulu_io/contents/foo/bar');
        $this->manager->flush();

        $this->syncManager->synchronize($page, [ 'flush' => true ]);

        $this->assertExistsInPublishDocumentManager($page);
        $page = $this->publishDocumentManager->find($page->getUuid(), 'de');
        $this->assertEquals('/cmf/sulu_io/contents/foo/bar/foobar', $page->getPath());
    }

    private function createPage($data)
    {
        $page = new PageDocument();

        $page->setTitle($data['title']);
        $page->setParent($this->parent);
        $page->setStructureType('contact');
        $page->setResourceSegment('/foo');
        $page->getStructure()->bind($data, true);

        return $page;
    }

    private function assertExistsInPublishDocumentManager($document)
    {
        $path = $this->manager->getInspector()->getPath($document);
        $this->assertTrue($this->publishDocumentManager->getNodeManager()->has($path), sprintf('Document "%s" exists in PDM', $path));
    }

    private function assertNotExistsInPublishDocumentManager($document)
    {
        $path = $this->manager->getInspector()->getPath($document);
        $this->assertFalse($this->publishDocumentManager->getNodeManager()->has($path), 'Page does not exist in PDM');
    }
}
