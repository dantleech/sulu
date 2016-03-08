<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\MediaBundle\Tests\Functional\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionMeta;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Component\Cache\CacheInterface;

class CollectionControllerTest extends SuluTestCase
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var Collection
     */
    private $collection1;

    /**
     * @var CollectionType
     */
    private $collectionType1;

    /**
     * @var CollectionType
     */
    private $collectionType2;

    /**
     * @var CacheInterface
     */
    private $systemCollectionCache;

    /**
     * @var array
     */
    private $systemCollectionConfig;

    protected function setUp()
    {
        parent::setUp();

        $this->purgeDatabase();
        $this->em = $this->db('ORM')->getOm();
        $this->initOrm();

        $this->systemCollectionCache = $this->getContainer()->get('sulu_media.system_collections.cache');
        $this->systemCollectionConfig = $this->getContainer()->getParameter('sulu_media.system_collections');

        // to be sure that the system collections will rebuild after purge database
        $this->systemCollectionCache->invalidate();
    }

    /**
     * Returns amount of system collections.
     *
     * @param int|null $depth
     *
     * @return int
     */
    protected function getAmountOfSystemCollections($depth = null)
    {
        $amount = 1; // 1 root collection

        if ($depth > 0 || $depth === null) {
            $amount += $this->iterateOverSystemCollections($this->systemCollectionConfig, $depth);
        }

        return $amount;
    }

    /**
     * Loops thru all system collections until reach the depth.
     *
     * @param $config
     * @param null $depth
     *
     * @return int
     */
    protected function iterateOverSystemCollections($config, $depth = null)
    {
        $amount = count($config);

        if ($depth > 0 || $depth === null) {
            foreach ($config as $child) {
                if (array_key_exists('collections', $child)) {
                    $amount += $this->iterateOverSystemCollections($child['collections'], $depth - 1);
                }
            }
        }

        return $amount;
    }

    protected function initOrm()
    {
        // force id = 1
        $metadata = $this->em->getClassMetaData(CollectionType::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);

        $this->collectionType1 = $this->createCollectionType(
            1,
            'collection.default',
            'Default Collection Type',
            'Default Collection Type'
        );
        $this->collectionType2 = $this->createCollectionType(2, 'collection.system', 'System Collections');
        $this->em->persist($this->collectionType1);
        $this->em->persist($this->collectionType2);
        $this->em->flush();

        $this->collection1 = $this->createCollection(
            $this->collectionType1,
            ['en-gb' => 'Test Collection', 'de' => 'Test Kollektion']
        );
    }

    private function createCollection(CollectionType $collectionType, $title = [], $parent = null, $key = null)
    {
        // Collection
        $collection = new Collection();

        $style = [
            'type' => 'circle',
            'color' => '#ffcc00',
        ];

        $collection->setStyle(json_encode($style));
        $collection->setKey($key);
        $collection->setType($collectionType);

        // Collection Meta 1
        $collectionMeta = new CollectionMeta();
        $collectionMeta->setTitle(isset($title['en-gb']) ? $title['en-gb'] : 'Collection');
        $collectionMeta->setDescription('This Description is only for testing');
        $collectionMeta->setLocale('en-gb');
        $collectionMeta->setCollection($collection);

        $collection->setDefaultMeta($collectionMeta);
        $collection->addMeta($collectionMeta);

        // Collection Meta 2
        $collectionMeta2 = new CollectionMeta();
        $collectionMeta2->setTitle(isset($title['de']) ? $title['de'] : 'Kollection');
        $collectionMeta2->setDescription('Dies ist eine Test Beschreibung');
        $collectionMeta2->setLocale('de');
        $collectionMeta2->setCollection($collection);

        $collection->addMeta($collectionMeta2);

        $collection->setParent($parent);

        $this->em->persist($collection);
        $this->em->persist($collectionType);
        $this->em->persist($collectionMeta);
        $this->em->persist($collectionMeta2);

        $this->em->flush();

        return $collection;
    }

    private function createCollectionType($id, $key, $name, $description = '')
    {
        $collectionType = new CollectionType();
        $collectionType->setId($id);
        $collectionType->setName($name);
        $collectionType->setKey($key);
        $collectionType->setDescription($description);

        return $collectionType;
    }

    /**
     * @description Test Collection GET by ID
     */
    public function testGetById()
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections/' . $this->collection1->getId(),
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $style = json_decode(
            json_encode(
                [
                    'type' => 'circle',
                    'color' => '#ffcc00',
                ]
            ),
            false
        );

        $this->assertEquals($style, $response->style);
        $this->assertEquals('This Description is only for testing', $response->description);
        $this->assertNotNull($response->id);
        $this->assertCount(0, $response->_embedded->collections);
        $this->assertEquals('en-gb', $response->locale);
        $this->assertEquals('Test Collection', $response->title);
        $this->assertNotNull($response->type->id);
        $this->assertEquals('Default Collection Type', $response->type->name);
        $this->assertEquals('Default Collection Type', $response->type->description);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->changed)));
    }

    /**
     * @description Test GET all Collections
     */
    public function testcGet()
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());

        $this->assertNotEmpty($response->_embedded->collections);

        $this->assertCount(1, $response->_embedded->collections);
    }

    /**
     * @description Test GET for non existing Resource (404)
     */
    public function testGetByIdNotExisting()
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections/10'
        );

        $this->assertEquals(404, $client->getResponse()->getStatusCode());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(5005, $response->code);
        $this->assertTrue(isset($response->message));
    }

    /**
     * @description Test POST to create a new Collection
     */
    public function testPost()
    {
        $client = $this->createAuthenticatedClient();

        $generateColor = '#ffcc00';

        $this->assertNotEmpty($generateColor);
        $this->assertEquals(7, strlen($generateColor));

        $client->request(
            'POST',
            '/api/collections',
            [
                'locale' => 'en-gb',
                'style' => [
                        'type' => 'circle',
                        'color' => $generateColor,
                    ],
                'type' => [
                    'id' => $this->collectionType1->getId(),
                ],
                'title' => 'Test Collection 2',
                'description' => 'This Description 2 is only for testing',
                'parent' => null,
            ]
        );

        $response = json_decode($client->getResponse()->getContent());

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = $generateColor;

        $this->assertEquals('en-gb', $response->locale);
        $this->assertEquals($style, $response->style);
        $this->assertNotNull($response->id);
        $this->assertNotNull($response->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->changed)));
        $this->assertEquals('Test Collection 2', $response->title);
        $this->assertEquals('This Description 2 is only for testing', $response->description);
        /*
        $this->assertNotEmpty($response->creator);
        $this->assertNotEmpty($response->changer);
        */

        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections?flat=true',
            [
                'locale' => 'en-gb',
            ]
        );

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $response = json_decode($client->getResponse()->getContent());

        $this->assertNotEmpty($response);

        $this->assertEquals(2, $response->total);

        // check if first entity is unchanged
        $this->assertTrue(isset($response->_embedded->collections[0]));
        $responseFirstEntity = $response->_embedded->collections[0];

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = '#ffcc00';

        $this->assertEquals($this->collection1->getId(), $responseFirstEntity->id);
        $this->assertEquals('en-gb', $responseFirstEntity->locale);
        $this->assertEquals($style, $responseFirstEntity->style);
        $this->assertEquals($this->collectionType1->getId(), $responseFirstEntity->type->id);
        $this->assertNotEmpty($responseFirstEntity->created);
        $this->assertNotEmpty($responseFirstEntity->changed);
        $this->assertEquals('Test Collection', $responseFirstEntity->title);
        $this->assertEquals('This Description is only for testing', $responseFirstEntity->description);

        // check second entity was created right
        $this->assertTrue(isset($response->_embedded->collections[1]));
        $responseSecondEntity = $response->_embedded->collections[1];

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = $generateColor;

        $this->assertNotNull($responseSecondEntity->id);
        $this->assertEquals('en-gb', $responseSecondEntity->locale);
        $this->assertEquals($style, $responseSecondEntity->style);
        $this->assertNotNull($responseSecondEntity->type->id);
        $this->assertNotEmpty($responseSecondEntity->created);
        $this->assertNotEmpty($responseSecondEntity->changed);
        $this->assertEquals('Test Collection 2', $responseSecondEntity->title);
        $this->assertEquals('This Description 2 is only for testing', $responseSecondEntity->description);
    }

    /**
     * @description Test POST to create a new nested Collection
     */
    public function testPostNested()
    {
        $client = $this->createAuthenticatedClient();

        $generateColor = '#ffcc00';

        $this->assertNotEmpty($generateColor);
        $this->assertEquals(7, strlen($generateColor));

        $client->request(
            'POST',
            '/api/collections',
            [
                'locale' => 'en-gb',
                'style' => [
                    'type' => 'circle',
                    'color' => $generateColor,
                ],
                'type' => [
                    'id' => $this->collectionType1->getId(),
                ],
                'title' => 'Test Collection 2',
                'description' => 'This Description 2 is only for testing',
                'parent' => $this->collection1->getId(),
            ]
        );

        $response = json_decode($client->getResponse()->getContent());

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = $generateColor;

        $this->assertEquals('en-gb', $response->locale);
        $this->assertEquals($style, $response->style);
        $this->assertNotNull($response->id);
        $this->assertNotNull($response->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->changed)));
        $this->assertEquals('Test Collection 2', $response->title);
        $this->assertEquals('This Description 2 is only for testing', $response->description);
        $this->assertEquals($this->collection1->getId(), $response->_embedded->parent->id);

        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections/' . $this->collection1->getId() . '?depth=1',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);

        // check if first entity is unchanged
        $this->assertTrue(isset($response->_embedded->collections[0]));
        $responseFirstEntity = $response->_embedded->collections[0];

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = $generateColor;

        $this->assertNotNull($responseFirstEntity->id);
        $this->assertEquals('en-gb', $responseFirstEntity->locale);
        $this->assertEquals($style, $responseFirstEntity->style);
        $this->assertNotNull($responseFirstEntity->type->id);
        $this->assertNotEmpty($responseFirstEntity->created);
        $this->assertNotEmpty($responseFirstEntity->changed);
        $this->assertEquals('Test Collection 2', $responseFirstEntity->title);
        $this->assertEquals('This Description 2 is only for testing', $responseFirstEntity->description);

        $client->request(
            'GET',
            '/api/collections?flat=true&depth=2',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);
        $this->assertEquals(2 + $this->getAmountOfSystemCollections(), $response->total);
    }

    /**
     * @description Test POST to create a new Collection
     */
    public function testPostWithoutDetails()
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/collections',
            [
                'title' => 'Test Collection 2',
                'type' => [
                    'id' => $this->collectionType1->getId(),
                ],
            ]
        );

        $response = json_decode($client->getResponse()->getContent());

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals('en', $response->locale);
        $this->assertNotNull($response->id);
        $this->assertNotNull($response->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->changed)));
        $this->assertEquals('Test Collection 2', $response->title);

        // get collection in locale 'en-gb'
        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections',
            [
                'locale' => 'en-gb',
            ]
        );

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $response = json_decode($client->getResponse()->getContent());

        $this->assertNotEmpty($response);

        $this->assertEquals(2, $response->total);

        // check if first entity is unchanged
        $this->assertTrue(isset($response->_embedded->collections[0]));
        $responseFirstEntity = $response->_embedded->collections[0];

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = '#ffcc00';

        $this->assertEquals($this->collection1->getId(), $responseFirstEntity->id);
        $this->assertEquals('en-gb', $responseFirstEntity->locale);
        $this->assertEquals($style, $responseFirstEntity->style);
        $this->assertNotNull($responseFirstEntity->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->changed)));
        $this->assertEquals('Test Collection', $responseFirstEntity->title);
        $this->assertEquals('This Description is only for testing', $responseFirstEntity->description);

        // check second entity was created right
        $this->assertTrue(isset($response->_embedded->collections[1]));
        $responseSecondEntity = $response->_embedded->collections[1];

        $this->assertNotNull($responseSecondEntity->id);
        $this->assertEquals('en-gb', $responseSecondEntity->locale);
        $this->assertNotNull($responseSecondEntity->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseSecondEntity->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseSecondEntity->changed)));
        $this->assertEquals('Test Collection 2', $responseSecondEntity->title);

        // get collection in locale 'en'
        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections',
            [
                'locale' => 'en',
            ]
        );

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $response = json_decode($client->getResponse()->getContent());

        $this->assertNotEmpty($response);

        $this->assertEquals(2, $response->total);

        // check if first entity is unchanged
        $this->assertTrue(isset($response->_embedded->collections[0]));
        $responseFirstEntity = $response->_embedded->collections[0];

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = '#ffcc00';

        $this->assertNotNull($responseFirstEntity->id);
        $this->assertEquals('en', $responseFirstEntity->locale);
        $this->assertEquals($style, $responseFirstEntity->style);
        $this->assertNotNull($responseFirstEntity->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->changed)));
        $this->assertEquals('Test Collection', $responseFirstEntity->title);
        $this->assertEquals('This Description is only for testing', $responseFirstEntity->description);

        // check second entity was created right
        $this->assertTrue(isset($response->_embedded->collections[1]));
        $responseSecondEntity = $response->_embedded->collections[1];

        $this->assertNotNull($responseSecondEntity->id);
        $this->assertEquals('en', $responseSecondEntity->locale);
        $this->assertNotNull($responseSecondEntity->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseSecondEntity->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseSecondEntity->changed)));
        $this->assertEquals('Test Collection 2', $responseSecondEntity->title);
    }

    /**
     * @description Test POST to create a new Collection
     */
    public function testPostWithNotExistingType()
    {
        $client = $this->createAuthenticatedClient();

        $generateColor = '#ffcc00';

        $this->assertNotEmpty($generateColor);
        $this->assertEquals(7, strlen($generateColor));

        $client->request(
            'POST',
            '/api/collections',
            [
                'style' => [
                        'type' => 'circle',
                        'color' => $generateColor,
                    ],
                'type' => [
                    'id' => 91283,
                ],
                'title' => 'Test Collection 2',
                'description' => 'This Description 2 is only for testing',
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());

        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertTrue(isset($response->message));
    }

    /**
     * @description Test PUT Action
     */
    public function testPut()
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'PUT',
            '/api/collections/' . $this->collection1->getId(),
            [
                'style' => [
                    'type' => 'circle',
                    'color' => '#00ccff',
                ],
                'type' => $this->collectionType1->getId(),
                'title' => 'Test Collection changed',
                'description' => 'This Description is only for testing changed',
                'locale' => 'en-gb',
            ]
        );

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $client->request(
            'GET',
            '/api/collections/' . $this->collection1->getId(),
            [
                'locale' => 'en-gb',
            ]
        );
        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = '#00ccff';

        $id = $response->id;

        $this->assertEquals($style, $response->style);
        $this->assertEquals($this->collection1->getId(), $response->id);
        $this->assertNotNull($response->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->changed)));
        $this->assertEquals('Test Collection changed', $response->title);
        $this->assertEquals('This Description is only for testing changed', $response->description);
        $this->assertEquals('en-gb', $response->locale);

        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections?locale=en-gb'
        );

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $response = json_decode($client->getResponse()->getContent());

        $this->assertNotEmpty($response);

        $this->assertEquals(
            1 + $this->getAmountOfSystemCollections(0),
            $response->total
        );

        $this->assertTrue(isset($response->_embedded->collections[0]));
        $this->assertTrue(isset($response->_embedded->collections[1]));
        $responseFirstEntity = $response->_embedded->collections[0];
        if ($responseFirstEntity->id !== $id) {
            $responseFirstEntity = $response->_embedded->collections[1];
        }

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = '#00ccff';

        $this->assertEquals($style, $responseFirstEntity->style);
        $this->assertEquals($this->collection1->getId(), $responseFirstEntity->id);
        $this->assertNotNull($responseFirstEntity->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->changed)));
        $this->assertEquals('Test Collection changed', $responseFirstEntity->title);
        $this->assertEquals('This Description is only for testing changed', $responseFirstEntity->description);
        $this->assertEquals('en-gb', $responseFirstEntity->locale);
    }

    /**
     * @description Test PUT Action
     */
    public function testPutWithoutLocale()
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'PUT',
            '/api/collections/' . $this->collection1->getId(),
            [
                'style' => [
                    'type' => 'circle',
                    'color' => '#00ccff',
                ],
                'type' => 1,
                'title' => 'Test Collection changed',
                'description' => 'This Description is only for testing changed',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $client->request(
            'GET',
            '/api/collections/' . $this->collection1->getId()
        );
        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = '#00ccff';

        $id = $response->id;

        $this->assertEquals($style, $response->style);
        $this->assertEquals($this->collection1->getId(), $response->id);
        $this->assertNotNull($response->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($response->changed)));
        $this->assertEquals('Test Collection changed', $response->title);
        $this->assertEquals('This Description is only for testing changed', $response->description);
        $this->assertEquals('en', $response->locale);

        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections?locale=en'
        );

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $response = json_decode($client->getResponse()->getContent());

        $this->assertNotEmpty($response);

        $this->assertEquals(2, $response->total);

        $this->assertTrue(isset($response->_embedded->collections[0]));
        $this->assertTrue(isset($response->_embedded->collections[1]));
        $responseFirstEntity = $response->_embedded->collections[0];
        if ($responseFirstEntity->id !== $id) {
            $responseFirstEntity = $response->_embedded->collections[1];
        }

        $style = new \stdClass();
        $style->type = 'circle';
        $style->color = '#00ccff';

        $this->assertEquals($style, $responseFirstEntity->style);
        $this->assertEquals($this->collection1->getId(), $responseFirstEntity->id);
        $this->assertNotNull($responseFirstEntity->type->id);
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->created)));
        $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($responseFirstEntity->changed)));
        $this->assertEquals('Test Collection changed', $responseFirstEntity->title);
        $this->assertEquals('This Description is only for testing changed', $responseFirstEntity->description);
        $this->assertEquals('en', $responseFirstEntity->locale);
    }

    /**
     * @description Test PUT action without details
     */
    public function testPutNoDetails()
    {
        $client = $this->createAuthenticatedClient();

        // Add New Collection Type
        $collectionType = new CollectionType();
        $collectionType->setName('Second Collection Type');
        $collectionType->setKey('my-type');
        $collectionType->setDescription('Second Collection Type');

        $this->em->persist($collectionType);
        $this->em->flush();

        // Test put with only details
        $client->request(
            'PUT',
            '/api/collections/' . $this->collection1->getId(),
            [
                'style' => [
                        'type' => 'quader',
                        'color' => '#00ccff',
                    ],
                'type' => [
                    'id' => $collectionType->getId(),
                ],
            ]
        );

        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $client->request(
            'GET',
            '/api/collections/' . $this->collection1->getId() . '?locale=en-gb'
        );
        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $style = new \stdClass();
        $style->type = 'quader';
        $style->color = '#00ccff';

        $this->assertEquals($style, $response->style);

        $this->assertNotNull($response->type->id);
    }

    /**
     * @description Test PUT on a none existing Object
     */
    public function testPutNotExisting()
    {
        $client = $this->createAuthenticatedClient();

        $client->request(
            'PUT',
            '/api/collections/404',
            [
                'style' => [
                        'type' => 'quader',
                        'color' => '#00ccff',
                    ],
                'type' => $this->collectionType1->getId(),
            ]
        );

        $this->assertEquals(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @description Test DELETE
     */
    public function testDeleteById()
    {
        $client = $this->createAuthenticatedClient();

        $client->request('DELETE', '/api/collections/' . $this->collection1->getId());
        $this->assertEquals('204', $client->getResponse()->getStatusCode());

        $client = $this->createAuthenticatedClient();

        $client->request(
            'GET',
            '/api/collections/' . $this->collection1->getId()
        );

        $this->assertEquals(404, $client->getResponse()->getStatusCode());

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(5005, $response->code);
        $this->assertTrue(isset($response->message));
    }

    /**
     * @description Test DELETE on none existing Object
     */
    public function testDeleteByIdNotExisting()
    {
        $client = $this->createAuthenticatedClient();

        $client->request('DELETE', '/api/collections/404');
        $this->assertEquals('404', $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/collections?flat=true');
        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(1, $response->total);
    }

    private function prepareTree()
    {
        $collection1 = $this->collection1;
        $collection2 = $this->createCollection(
            $this->createCollectionType(3, 'my-type-1', 'My Type 1'),
            ['en-gb' => 'col2'],
            $collection1
        );
        $collection3 = $this->createCollection(
            $this->createCollectionType(4, 'my-type-2', 'My Type 2'),
            ['en-gb' => 'col3'],
            $collection1
        );
        $collection4 = $this->createCollection(
            $this->createCollectionType(5, 'my-type-3', 'My Type 3'),
            ['en-gb' => 'col4']
        );
        $collection5 = $this->createCollection(
            $this->createCollectionType(6, 'my-type-4', 'My Type 4'),
            ['en-gb' => 'col5'],
            $collection4
        );
        $collection6 = $this->createCollection(
            $this->createCollectionType(7, 'my-type-5', 'My Type 5'),
            ['en-gb' => 'col6'],
            $collection4
        );
        $collection7 = $this->createCollection(
            $this->createCollectionType(8, 'my-type-6', 'My Type 6'),
            ['en-gb' => 'col7'],
            $collection6
        );

        return [
            [
                'Test Collection',
                'col2',
                'col3',
                'col4',
                'col5',
                'col6',
                'col7',
            ],
            [
                $collection1->getId(),
                $collection2->getId(),
                $collection3->getId(),
                $collection4->getId(),
                $collection5->getId(),
                $collection6->getId(),
                $collection7->getId(),
            ],
            [
                $collection1,
                $collection2,
                $collection3,
                $collection4,
                $collection5,
                $collection6,
                $collection7,
            ],
        ];
    }

    public function testCGetNestedFlat()
    {
        list($titles) = $this->prepareTree();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections?flat=true',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);
        $this->assertEquals(2, $response->total);
        $this->assertCount(2, $response->_embedded->collections);
        $items = $response->_embedded->collections;

        $this->assertEquals($titles[0], $items[0]->title);
        $this->assertEquals(null, $items[0]->_embedded->parent);
        $this->assertEmpty($items[0]->_embedded->collections);
        $this->assertEquals($titles[3], $items[1]->title);
        $this->assertEquals(null, $items[1]->_embedded->parent);
        $this->assertEmpty($items[1]->_embedded->collections);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections?flat=true&depth=1',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);
        $this->assertEquals(6, $response->total);
        $this->assertCount(6, $response->_embedded->collections);
        $items = $response->_embedded->collections;

        $this->assertEquals($titles[0], $items[0]->title);
        $this->assertNull($items[0]->_embedded->parent);
        $this->assertEmpty($items[0]->_embedded->collections);

        $this->assertEquals($titles[1], $items[1]->title);
        $this->assertNotNull($items[1]->_embedded->parent);
        $this->assertEmpty($items[1]->_embedded->collections);

        $this->assertEquals($titles[2], $items[2]->title);
        $this->assertNotNull($items[2]->_embedded->parent);
        $this->assertEmpty($items[2]->_embedded->collections);

        $this->assertEquals($titles[3], $items[3]->title);
        $this->assertNull($items[3]->_embedded->parent);
        $this->assertEmpty($items[3]->_embedded->collections);

        $this->assertEquals($titles[4], $items[4]->title);
        $this->assertNotNull($items[4]->_embedded->parent);
        $this->assertEmpty($items[4]->_embedded->collections);

        $this->assertEquals($titles[5], $items[5]->title);
        $this->assertNotNull($items[5]->_embedded->parent);
        $this->assertEmpty($items[5]->_embedded->collections);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections?flat=true&depth=2',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);
        $this->assertEquals(7, $response->total);
        $this->assertCount(7, $response->_embedded->collections);
        $items = $response->_embedded->collections;

        $this->assertEquals($titles[0], $items[0]->title);
        $this->assertNull($items[0]->_embedded->parent);
        $this->assertEmpty($items[0]->_embedded->collections);

        $this->assertEquals($titles[1], $items[1]->title);
        $this->assertNotNull($items[1]->_embedded->parent);
        $this->assertEmpty($items[1]->_embedded->collections);

        $this->assertEquals($titles[2], $items[2]->title);
        $this->assertNotNull($items[2]->_embedded->parent);
        $this->assertEmpty($items[2]->_embedded->collections);

        $this->assertEquals($titles[3], $items[3]->title);
        $this->assertNull($items[3]->_embedded->parent);
        $this->assertEmpty($items[3]->_embedded->collections);

        $this->assertEquals($titles[4], $items[4]->title);
        $this->assertNotNull($items[4]->_embedded->parent);
        $this->assertEmpty($items[4]->_embedded->collections);

        $this->assertEquals($titles[5], $items[5]->title);
        $this->assertNotNull($items[5]->_embedded->parent);
        $this->assertEmpty($items[5]->_embedded->collections);

        $this->assertEquals($titles[6], $items[6]->title);
        $this->assertNotNull($items[6]->_embedded->parent);
        $this->assertEmpty($items[6]->_embedded->collections);
    }

    public function testCGetNestedTree()
    {
        list($titles) = $this->prepareTree();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);
        $this->assertCount(2, $response->_embedded->collections);
        $items = $response->_embedded->collections;

        $this->assertEquals($titles[0], $items[0]->title);
        $this->assertEquals(null, $items[0]->_embedded->parent);
        $this->assertEmpty($items[0]->_embedded->collections);
        $this->assertEquals($titles[3], $items[1]->title);
        $this->assertEquals(null, $items[1]->_embedded->parent);
        $this->assertEmpty($items[1]->_embedded->collections);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections?depth=1',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);
        $this->assertCount(2, $response->_embedded->collections);
        $items = $response->_embedded->collections;

        $this->assertEquals($titles[0], $items[0]->title);
        $this->assertNull($items[0]->_embedded->parent);
        $this->assertNotEmpty($items[0]->_embedded->collections);

        $this->assertEquals($titles[3], $items[1]->title);
        $this->assertNull($items[1]->_embedded->parent);
        $this->assertNotEmpty($items[1]->_embedded->collections);

        $subItems = $items[0]->_embedded->collections;
        $this->assertCount(2, $subItems);
        $this->assertEquals($titles[1], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[2], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertEmpty($subItems[1]->_embedded->collections);

        $subItems = $items[1]->_embedded->collections;
        $this->assertCount(2, $subItems);
        $this->assertEquals($titles[4], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[5], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertEmpty($subItems[1]->_embedded->collections);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections?depth=2',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertNotEmpty($response);
        $this->assertCount(2, $response->_embedded->collections);
        $items = $response->_embedded->collections;

        $this->assertEquals($titles[0], $items[0]->title);
        $this->assertNull($items[0]->_embedded->parent);
        $this->assertNotEmpty($items[0]->_embedded->collections);

        $this->assertEquals($titles[3], $items[1]->title);
        $this->assertNull($items[1]->_embedded->parent);
        $this->assertNotEmpty($items[1]->_embedded->collections);

        $subItems = $items[0]->_embedded->collections;
        $this->assertCount(2, $subItems);
        $this->assertEquals($titles[1], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[2], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertEmpty($subItems[1]->_embedded->collections);

        $subItems = $items[1]->_embedded->collections;
        $this->assertCount(2, $subItems);
        $this->assertEquals($titles[4], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[5], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertNotEmpty($subItems[1]->_embedded->collections);

        $subItems = $subItems[1]->_embedded->collections;
        $this->assertCount(1, $subItems);
        $this->assertEquals($titles[6], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);
    }

    public function testGetBreadcrumb()
    {
        list($titles, $ids) = $this->prepareTree();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[6],
            [
                'locale' => 'en-gb',
                'breadcrumb' => 'true',
            ]
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($titles[6], $response['title']);

        $breadcrumb = $response['_embedded']['breadcrumb'];
        $this->assertCount(2, $breadcrumb);
        $this->assertEquals($titles[3], $breadcrumb[0]['title']);
        $this->assertEquals($ids[3], $breadcrumb[0]['id']);
        $this->assertEquals($titles[5], $breadcrumb[1]['title']);
        $this->assertEquals($ids[5], $breadcrumb[1]['id']);
    }

    /**
     * @description Test Collection GET by ID with a depth
     */
    public function testGetByIdWithDepth()
    {
        list($titles, $ids) = $this->prepareTree();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[3],
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($titles[3], $response->title);
        $this->assertNull($response->_embedded->parent);
        $this->assertEmpty($response->_embedded->collections);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[3] . '?depth=1',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($titles[3], $response->title);
        $this->assertNull($response->_embedded->parent);
        $this->assertNotEmpty($response->_embedded->collections);

        $subItems = $response->_embedded->collections;
        $this->assertCount(2, $subItems);
        $this->assertEquals($titles[4], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[5], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertEmpty($subItems[1]->_embedded->collections);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[3] . '?depth=2',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($titles[3], $response->title);
        $this->assertNull($response->_embedded->parent);
        $this->assertNotEmpty($response->_embedded->collections);

        $subItems = $response->_embedded->collections;
        $this->assertCount(2, $subItems);
        $this->assertEquals($titles[4], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[5], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertNotEmpty($subItems[1]->_embedded->collections);

        $subItems = $subItems[1]->_embedded->collections;
        $this->assertCount(1, $subItems);
        $this->assertEquals($titles[6], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);
    }

    /**
     * @description Test move a Collection
     */
    public function testMove()
    {
        list($titles, $ids) = $this->prepareTree();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'POST',
            '/api/collections/' . $ids[3] . '?action=move&destination=' . $ids[0],
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($ids[0], $response->_embedded->parent->id);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections?depth=3',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $items = $response->_embedded->collections;

        // all items in this response
        $this->assertEquals(7, $response->total);

        // root collection items
        $this->assertCount(1, $items);

        $this->assertEquals($titles[0], $items[0]->title);
        $this->assertNull($items[0]->_embedded->parent);
        $this->assertNotEmpty($items[0]->_embedded->collections);

        $subItems = $items[0]->_embedded->collections;
        $this->assertCount(3, $subItems);
        $this->assertEquals($titles[1], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[2], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertEmpty($subItems[1]->_embedded->collections);

        $this->assertEquals($titles[3], $subItems[2]->title);
        $this->assertNotNull($subItems[2]->_embedded->parent);
        $this->assertNotEmpty($subItems[2]->_embedded->collections);

        $subItems = $subItems[2]->_embedded->collections;
        $this->assertCount(2, $subItems);
        $this->assertEquals($titles[4], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);

        $this->assertEquals($titles[5], $subItems[1]->title);
        $this->assertNotNull($subItems[1]->_embedded->parent);
        $this->assertNotEmpty($subItems[1]->_embedded->collections);

        $subItems = $subItems[1]->_embedded->collections;
        $this->assertCount(1, $subItems);
        $this->assertEquals($titles[6], $subItems[0]->title);
        $this->assertNotNull($subItems[0]->_embedded->parent);
        $this->assertEmpty($subItems[0]->_embedded->collections);
    }

    public function testSearchChildren()
    {
        list($titles, $ids) = $this->prepareTree();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[3] . '?depth=1&search=col5',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($titles[3], $response['title']);
        $this->assertcount(1, $response['_embedded']['collections']);
        $this->assertEquals($titles[4], $response['_embedded']['collections'][0]['title']);
    }

    public function testPaginationChildren()
    {
        list($titles, $ids, $collections) = $this->prepareTree();
        $this->createCollection(
            $this->createCollectionType(9, 'my-type', 'My new type'),
            ['en-gb' => 'my collection'],
            $collections[3]
        );

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[3] . '?depth=1&page=1&limit=2',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($titles[3], $response['title']);
        $this->assertcount(2, $response['_embedded']['collections']);
        $this->assertEquals($titles[4], $response['_embedded']['collections'][0]['title']);
        $this->assertEquals($titles[5], $response['_embedded']['collections'][1]['title']);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[3] . '?depth=1&page=2&limit=2',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($titles[3], $response['title']);
        $this->assertcount(1, $response['_embedded']['collections']);
        $this->assertEquals('my collection', $response['_embedded']['collections'][0]['title']);

        $client = $this->createAuthenticatedClient();
        $client->request(
            'GET',
            '/api/collections/' . $ids[3] . '?depth=1&page=1&limit=10',
            [
                'locale' => 'en-gb',
            ]
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $this->assertEquals($titles[3], $response['title']);
        $this->assertcount(3, $response['_embedded']['collections']);
        $this->assertEquals($titles[4], $response['_embedded']['collections'][0]['title']);
        $this->assertEquals($titles[5], $response['_embedded']['collections'][1]['title']);
        $this->assertEquals('my collection', $response['_embedded']['collections'][2]['title']);
    }

    public function testPostParentIsSystemCollection()
    {
        $collection = $this->createCollection($this->collectionType2, ['en' => 'Test'], null, 'system_collections');
        $this->em->flush();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'POST',
            '/api/collections',
            [
                'locale' => 'en-gb',
                'type' => [
                    'id' => $this->collectionType1->getId(),
                ],
                'title' => 'Test Collection 2',
                'description' => 'This Description 2 is only for testing',
                'parent' => $collection->getId(),
            ]
        );

        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }

    public function testPutSystemCollection()
    {
        $collection = $this->createCollection($this->collectionType2, ['en' => 'Test'], null, 'system_collections');
        $this->em->flush();

        $client = $this->createAuthenticatedClient();
        $client->request(
            'PUT',
            '/api/collections/' . $collection->getId(),
            [
                'locale' => 'en-gb',
                'type' => [
                    'id' => $this->collectionType1->getId(),
                ],
                'title' => 'Test Collection 2',
                'description' => 'This Description 2 is only for testing',
            ]
        );

        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }
}
