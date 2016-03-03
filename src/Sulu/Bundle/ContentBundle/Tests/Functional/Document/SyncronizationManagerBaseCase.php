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
class SyncronizationManagerBaseCase extends SuluTestCase
{
    /**
     * @var mixed
     */
    protected $manager;

    /**
     * @var mixed
     */
    protected $syncManager;

    /**
     * @var mixed
     */
    protected $targetContext;

    public function setUp()
    {
        $kernel = $this->getKernel([
            'environment' => 'multiple_document_managers',
        ]);

        $this->context = $kernel->getContainer()->get('sulu_document_manager.context');
        $this->manager = $this->context->getManager();
        $this->syncManager = $kernel->getContainer()->get('sulu_content.document.synchronization_manager');
        $this->targetContext = $this->syncManager->getTargetContext();
        $this->initPhpcr($kernel);
        $this->parent = $this->context->getManager()->find('/cmf/sulu_io/contents', 'de');
    }

    /**
     * Assert that the test system is confgiured to use two separate document managers.
     */
    public function testSystemUsesTwoDocumentManagers()
    {
        $this->assertNotSame($this->context, $this->syncManager->getTargetContext());
    }

    protected function createPage($data)
    {
        $page = new PageDocument();

        $page->setTitle($data['title']);
        $page->setParent($this->parent);
        $page->setStructureType('contact');
        $page->setResourceSegment('/foo');
        $page->getStructure()->bind($data, true);

        return $page;
    }

    protected function assertExistsInTargetDocumentManager($document)
    {
        $path = $this->manager->getInspector()->getPath($document);
        $this->assertTrue($this->targetContext->getNodeManager()->has($path), sprintf('Document "%s" exists in TDM', $path));
    }

    protected function assertNotExistsInTargetDocumentManager($document)
    {
        $path = $this->manager->getInspector()->getPath($document);
        $this->assertFalse($this->targetContext->getNodeManager()->has($path), 'Page does not exist in TDM');
    }
}
