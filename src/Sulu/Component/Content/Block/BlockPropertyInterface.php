<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Block;

use Sulu\Component\Content\PropertyInterface;

/**
 * interface definition for block property
 */
interface BlockPropertyInterface extends PropertyInterface
{
    /**
     * returns a list of properties managed by this block
     * @return PropertyInterface[]
     */
    public function getChildProperties();

    /**
     * @param PropertyInterface $property
     */
    public function addChild(PropertyInterface $property);

    /**
     * returns property with given name
     * @param string $name of property
     * @throws \Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException
     * @return PropertyInterface
     */
    public function getChild($name);
} 