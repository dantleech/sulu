<?php

namespace Sulu\Component\Content\Document\Syncronization;

class Mapping
{
    private $classMapping = [];
    private $cascadeMap = [];
    private $defaultAutoSync = [];

    public function __construct(
        array $defaultAutoSync = [],
        array $classMapping = []
    )
    {
        $this->defaultAutoSync = $defaultAutoSync;

        foreach ($classMapping as $classFqn => $config) {
            $config = array_merge([
                'auto_sync' => [],
                'cascade_referrers' => [],
            ], $config);

            $this->addClassMapping($classFqn, $config);
        }

        foreach ($this->classMapping as $classFqn => $config) {
            foreach ($config['cascade_referrers'] as $cascadeReferrer) {
                $this->cascadeMap[$cascadeReferrer] = $classFqn;
            }
        }
    }

    public function isMapped($document)
    {
        return null !== $this->getMapping($document);
    }

    public function hasAutoSyncPolicy($document, array $policy)
    {
        if ($mapping = $this->getMapping($document)) {
            return $this->checkAnyPolicy($mapping['auto_sync'], $policy);
        }

        return $this->checkAnyPolicy($this->defaultAutoSync, $policy);
    }

    public function getCascadeReferrers($document)
    {
        $mapping = $this->getMapping($document);

        return $mapping['cascade_referrers'];
    }

    private function checkAnyPolicy($availablePolicy, $givenPolicy)
    {
        foreach ($givenPolicy as $policy) {
            if (in_array($policy, $availablePolicy)) {
                return true;
            }
        }

        return false;
    }

    private function addClassMapping($classFqn, array $config)
    {
        $this->classMapping[$classFqn] = $config;
    }

    private function getMapping($document)
    {
        if (!is_object($document)) {
            throw new \InvalidArgumentException(sprintf(
                'Expected an object, got: "%s"',
                gettype($document)
            ));
        }

        $classes = class_parents($document);
        array_unshift($classes, get_class($document));

        foreach ($classes as $classFqn) {
            if (!isset($this->classMapping[$classFqn])) {
                continue;
            }

            return $this->classMapping[$classFqn];
        }

        return null;
    }
}
