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
use Sulu\Bundle\ContentBundle\Document\RouteDocument;

/**
 * This test is for the specific requirements of Sulu not the general
 * functionality of the synchronization manager.
 */
class SyncronizationManagerPushTest extends SyncronizationManagerBaseCase
{
    /**
     * Route documents are not automatically synced via. the subscriber.
     */
    public function testNotAutomaticallyPushed()
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

        $this->assertNotExistsInTargetDocumentManager($page);
    }

    /**
     * When a page is moved, it MUST also be moved in the TDM.
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
        $this->context->getNodeManager()->createPath('/cmf/sulu_io/contents/foo/bar');
        $this->manager->move($page, '/cmf/sulu_io/contents/foo/bar');
        $this->manager->flush();

        $this->assertExistsInTargetDocumentManager($page);
        $page = $this->targetContext->getManager()->find($page->getUuid(), 'de');

        $this->assertEquals(
            '/cmf/sulu_io/contents/foo/bar/foobar', 
            $page->getPath(),
            'Page has new path'
        );
    }

    /**
     * When a page is deleted, it MUST also be removed from the TDM.
     */
    public function testPageDeleted()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->syncManager->push($page, [ 'flush' => true, 'cascade' => true ]);

        $this->assertExistsInTargetDocumentManager($page);

        $this->manager->remove($page);
        $this->manager->flush();

        $this->assertFalse(
            $this->targetContext->getNodeManager()->has($page->getPath()),
            'Remove has been propagated to the TDM'
        );
    }

    /**
     * When a page is deleted from the SDM, any properties referencing this
     * page from the TDM should be removed.
     */
    public function testRemoveReferences()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->syncManager->push($page, [ 'flush' => true, 'cascade' => true ]);

        $this->assertExistsInTargetDocumentManager($page);

        // create a reference property in the TDM
        $node = $this->targetContext->getNodeManager()->createPath('/cmf/sulu_io/content/foobar');
        $node->setProperty(
            'reference', 
            $this->manager->getInspector()->getNode($page),
            PropertyType::REFERENCE
        );
        $this->targetContext->getNodeManager()->save();

        $this->manager->remove($page);
        $this->manager->flush();

        // the system would crash if it didn't remove the reference above, as
        // it is a hard reference.
        $this->assertFalse(
            $this->targetContext->getNodeManager()->has($page->getPath()),
            'Remove has been propagated to the TDM'
        );
    }

    /**
     * Routes must not be automatically synced.
     */
    public function testRoutesNotPushed()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->assertTrue(
            $this->context->getNodeManager()->has('/cmf/sulu_io/routes/de/foo'),
            'Route exists in source manager'
        );

        $this->assertFalse(
            $this->targetContext->getNodeManager()->has('/cmf/sulu_io/routes/de/foo'),
            'Route does not exist in target manager'
        );
    }

    /**
     * Any referring routes which exist in the TDM and do not exist in the SDM should be deleted from the TDM.
     */
    public function testSyncPageWithDeletedRoutes()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);

        $this->manager->persist($page, 'de');
        $this->manager->flush();
        $this->syncManager->push($page, [ 'flush' => true, 'cascade' => true ]);

        $route1 = $this->manager->create('route');
        $route1->setTargetDocument($page);
        $route1->setHistory(true);

        $this->targetContext->getManager()->persist(
            $route1,
            null,
            [
                'path' => '/cmf/sulu_io/routes/de/foobar'
            ]
        );
        $this->targetContext->getManager()->flush();

        $this->assertTrue(
            $this->targetContext->getNodeManager()->has('/cmf/sulu_io/routes/de/foobar'),
            'Route exists in target document manager'
        );
        $this->assertFalse(
            $this->context->getNodeManager()->has('/cmf/sulu_io/routes/de/foobar'),
            'Route does not exist in source manager'
        );

        $this->syncManager->push($page, [ 'force' => true, 'flush' => true, 'cascade' => true ]);

        $this->assertFalse(
            $this->targetContext->getNodeManager()->has('/cmf/sulu_io/routes/de/foobar'),
            'Route has been removed from target manager'
        );
    }

    /**
     * Pages push must cascade to any related routes and the route-referrers of those routes.
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
        $this->syncManager->push($page, [ 'cascade' => true, 'flush' => true ]);

        $this->assertEquals($page->getSynchronizedManagers(), [ 'live' ]);
        $this->assertExistsInTargetDocumentManager($page);

        $page = $this->targetContext->getManager()->find($page->getUuid(), 'de');
        $this->assertEquals('Barbar', $page->getTitle());

        // the old route should have been updated and it should now be a
        // "history" route.
        //
        // TODO: We should not couple the test to this behavior, but the overhead
        //       of creating a new document is too great for now.
        //       see: https://github.com/sulu/sulu-document-manager/issues/73
        $route = $this->targetContext->getManager()->find('/cmf/sulu_io/routes/de/bar');
        $this->assertTrue($route->isHistory());
    }

    /**
     * It should update the published document when synchronized action is invoked.
     */
    public function testPush()
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

        $this->syncManager->push($page, [ 'flush' => true ]);
        $this->assertExistsInTargetDocumentManager($page);

        $page = $this->targetContext->getManager()->find($page->getUuid(), 'de');
        $this->assertEquals('Barbar', $page->getTitle());
    }

    /**
     * Snippets (not being mapped) should be automatically created, updated, moved and deleted.
     */
    public function testSnippets()
    {
        $this->markTestSkipped('todo');
    }
}
