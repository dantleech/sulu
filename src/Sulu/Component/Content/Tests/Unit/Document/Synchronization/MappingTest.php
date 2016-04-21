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

use Sulu\Component\Content\Document\Syncronization\Mapping;

class MappingTest extends \PHPUnit_Framework_TestCase
{
    /**
     * It should add class mapping.
     * It should return true if a class is mapped.
     */
    public function testAddClassMapping()
    {
        $mapping = $this->createMapping([], [
            \stdClass::class => []
        ]);
        $this->assertTrue($mapping->isMapped(new \stdClass()));
        $this->assertFalse($mapping->isMapped(new \ArrayObject()));
    }

    /**
     * It should return the auto sync policy for a class.
     *
     * @dataProvider provideAutoSyncPolicy
     */
    public function testAutoSyncPolicy($expectedResult, $class, array $policy, $context, array $defaultAutoSync = [], $defaultCascade = [])
    {
        $mapping = $this->createMapping($defaultAutoSync, [
            \stdClass::class => [
                'auto_sync' => [
                    'create',
                    'update',
                ]
            ]
        ]);

        // TRUE: Has given policy.
        $this->assertEquals(
            $expectedResult, 
            $mapping->hasAutoSyncPolicy(
                $class,
                $policy
            ),
            $context
        );
    }

    public function provideAutoSyncPolicy()
    {
        return [
            [
                true,
                new \stdClass(),
                [ 'update' ],
                'Has single policy.',
            ],
            [
                true,
                new \stdClass(),
                [ 'delete', 'update' ],
                'Has any of the given policies.',
            ],
            [
                false,
                new \stdClass(),
                [ 'delete'  ],
                'Has none of the given policies.',
            ],
            [
                false,
                new \stdClass(),
                [ ],
                'No given policies.',
            ],
            [
                true,
                new \ArrayObject(),
                [ 'update' ],
                'Fallsback to global policy if class not recognized',
                [ 'update'],
            ],
            [
                false,
                new \ArrayObject(),
                [ 'update' ],
                'Fallsback to global policy if class not recognized',
                [ ],
            ],
        ];
    }

    // TODO: Test class inheritance.

    /**
     * It should return cascade classes.
     */
    public function testGetCascade()
    {
        $mapping = $this->createMapping([], [
            \stdClass::class => [
                'cascade_referrers' => [
                    'FooClass',
                ]
            ]
        ]);

        $this->assertEquals([
            'FooClass',
        ], $mapping->getCascadeReferrers(new \stdClass));
    }

    private function createMapping($defaultAutoSync = [], $classMapping = [])
    {
        return new Mapping($defaultAutoSync, $classMapping);
    }
}
