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
use PHPCR\PropertyType;

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
     * 1. Route documents are not automatically synced via. the subscriber.
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
        $this->assertEmpty($page->getSynchronizedManagers());

        $this->assertNotExistsInPublishDocumentManager($page);
    }

    /**
     * 2. When a page is moved, it MUST also be moved in the TDM.
     */
    public function testMovePageInTdm()
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

        $this->assertExistsInPublishDocumentManager($page);
        $page = $this->publishDocumentManager->find($page->getUuid(), 'de');

        $this->assertEquals(
            '/cmf/sulu_io/contents/foo/bar/foobar', 
            $page->getPath(),
            'Page has new path'
        );
    }

    /**
     * 3. When a page is deleted, it MUST also be removed from the TDM.
     */
    public function testPageDeleted()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->syncManager->synchronize($page, [ 'flush' => true, 'cascade' => true ]);

        $this->assertExistsInPublishDocumentManager($page);

        $this->manager->remove($page);
        $this->manager->flush();

        $this->assertFalse(
            $this->publishDocumentManager->getNodeManager()->has($page->getPath()),
            'Remove has been propagated to the PDM'
        );
    }

    /**
     * 4. When a page is deleted from the SDM, any properties referencing this
     *    page from the TDM should be removed.
     */
    public function testRemoveReferences()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->syncManager->synchronize($page, [ 'flush' => true, 'cascade' => true ]);

        $this->assertExistsInPublishDocumentManager($page);

        // create a reference property in the PDM
        $node = $this->publishDocumentManager->getNodeManager()->createPath('/cmf/sulu_io/content/foobar');
        $node->setProperty(
            'reference', 
            $this->manager->getInspector()->getNode($page),
            PropertyType::REFERENCE
        );
        $this->publishDocumentManager->getNodeManager()->save();

        $this->manager->remove($page);
        $this->manager->flush();

        // the system would crash if it didn't remove the reference above, as
        // it is a hard reference.
        $this->assertFalse(
            $this->publishDocumentManager->getNodeManager()->has($page->getPath()),
            'Remove has been propagated to the PDM'
        );
    }

    /**
     * 5. Routes must not be automatically synced.
     */
    public function testRoutesNotSynced()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->assertTrue(
            $this->manager->getNodeManager()->has('/cmf/sulu_io/routes/de/foo'),
            'Route exists in default manager'
        );

        $this->assertFalse(
            $this->publishDocumentManager->getNodeManager()->has('/cmf/sulu_io/routes/de/foo'),
            'Route does not exist in publish manager'
        );
    }

    /**
     * 6. Pages MUST be immediately deleted from the TDM when deleted from the SDM.
     */
    public function testDeletePage()
    {
        $this->markTestSkipped('todo');
    }

    /**
     * 7. Any referring routes which exist in the TDM and do not exist in the SDM should be deleted from the TDM.
     */
    public function testSyncPageWithDeletedRoutes()
    {
        $this->markTestSkipped('todo');
    }

    /**
     * 9. Pages push must cascade to any related routes and the route-referrers of those routes.
     */
    public function testPushCascade()
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
     * It should publish documents that have been moved in the default document manager.
     */
    public function testMovedInDefault()
    {
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
