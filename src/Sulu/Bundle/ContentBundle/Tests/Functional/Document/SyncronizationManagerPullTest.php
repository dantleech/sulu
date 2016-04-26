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
class SyncronizationManagerPullTest extends SyncronizationManagerBaseCase
{
    /**
     * It should pull the counterpart document from the target document manager.
     */
    public function testPull()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);
        $page->setResourceSegment('/bar');

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->syncManager->push($page, [ 'flush' => true ]);

        $page->setTitle('Barbar');
        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->syncManager->pull($page, [ 'flush' => true ]);

        $page = $this->manager->find($page->getUuid(), 'de');
        $this->assertEquals('Foobar', $page->getTitle());
    }

    /**
     * It should cascade the pull, restoring the state of the referrers (here
     * the Route documents).
     */
    public function testCascade()
    {
        $page = $this->createPage([
            'title' => 'Foobar',
            'integer' => 1234,
        ]);
        $page->setResourceSegment('/bar');

        $this->manager->persist($page, 'de');
        $this->manager->flush();

        // push the initial state to the TDM
        $this->syncManager->push($page, [ 'cascade' => true, 'flush' => true ]);

        // change the document
        $page->setTitle('Barbar');
        $page->setResourceSegment('/bar/boo');
        $this->manager->persist($page, 'de');
        $this->manager->flush();

        $this->assertTrue(
            $this->context->getNodeManager()->has('/cmf/sulu_io/routes/de/bar/boo'),
            'SDM has the new route'
        );
        $this->assertTrue(
            $this->context->getNodeManager()->has('/cmf/sulu_io/routes/de/bar'),
            'SDM has the old route'
        );
        $this->assertTrue(
            $this->manager->find('/cmf/sulu_io/routes/de/bar', 'de')->isHistory(),
            'SDM The old route is a history route'
        );

        // PULL the page from the TDM to the SDM
        $this->syncManager->pull($page, [ 'force' => true, 'cascade' => true, 'flush' => true ]);

        $this->assertTrue(
            $this->context->getNodeManager()->has('/cmf/sulu_io/routes/de/bar'),
            'SDM has the old route'
        );
        $this->context->getRegistry()->clear();
        $this->assertFalse(
            $this->manager->find('/cmf/sulu_io/routes/de/bar')->isHistory(),
            'The old route has reverted to the primary route'
        );
        $this->assertFalse(
            $this->context->getNodeManager()->has('/cmf/sulu_io/routes/de/bar/boo'),
            'SDM does NOT have the new route'
        );

        $page = $this->manager->find($page->getUuid(), 'de');
        $this->assertEquals('Foobar', $page->getTitle());
    }
}
