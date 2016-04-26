<?php

namespace Sulu\Bundle\ContentBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Sulu\Component\DocumentManager\DocumentManagerContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Sulu\Component\Content\Document\SynchronizationManager;
use Sulu\Component\DocumentManager\DocumentRegistry;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class SynchronizePassiveManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasAlias('sulu_document_manager.context')) {
            return;
        }

        $alias = $container->getAlias('sulu_document_manager.context');
        $defaultContextDef = $container->getDefinition((string) $alias);

        $defaultRegistry = new Definition(DocumentRegistry::class);
        $defaultRegistry->setFactory([ new Reference('sulu_document_manager.context'), 'getRegistry' ]);

        $name = SynchronizationManager::PASSIVE_MANAGER_NAME;
        $contextDef = $container->getDefinition('sulu_document_manager.context.' . $name);
        $contextDef->addArgument($defaultRegistry);
    }
}
