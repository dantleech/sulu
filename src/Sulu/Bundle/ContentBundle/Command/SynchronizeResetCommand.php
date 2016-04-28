<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ContentBundle\Command;

use Sulu\Component\Content\Document\Behavior\SynchronizeBehavior;
use Sulu\Component\Content\Document\SynchronizationManager;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Sulu\Component\Content\Document\Behavior\LocalizedStructureBehavior;
use Symfony\Component\Console\Input\InputArgument;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentManagerRegistry;
use PHPCR\Util\NodeHelper;
use PHPCR\ImportUUIDBehaviorInterface;

class SynchronizeResetCommand extends Command
{
    private $registry;

    public function __construct(
        DocumentManagerRegistry $registry
    ) {
        parent::__construct();
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this->setName('sulu:document:sync-reset');
        $this->addArgument('sourceManager', InputArgument::REQUIRED);
        $this->addArgument('targetManager', InputArgument::REQUIRED);
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        // TODO: ensure that we are not going to purge the default manager.
        $sourceContext = $this->registry->getContext($input->getArgument('sourceManager'));
        $targetContext = $this->registry->getContext($input->getArgument('targetManager'));

        $output->writeln(sprintf(
            'Dumping workspace "%s" for session "%s"',
            $sourceContext->getSession()->getWorkspace()->getName(),
            $input->getArgument('sourceManager')
        ));
        $file = '_export.xml';
        $handle = fopen($file, 'w');
        $sourceContext->getSession()->exportSystemView('/cmf', $handle, false, false);

        $output->writeln(sprintf(
            'Purging workspace "%s" for session "%s"',
            $targetContext->getSession()->getWorkspace()->getName(),
            $input->getArgument('targetManager')
        ));
        // TODO: Show dialog here, ask for confirmation
        NodeHelper::purgeWorkspace($targetContext->getSession());
        $output->write('Saving..');
        $targetContext->getSession()->save();
        $output->writeln(' OK');

        $output->writeln(sprintf(
            'Loading dump to "%s" for session "%s"',
            $targetContext->getSession()->getWorkspace()->getName(),
            $input->getArgument('targetManager')
        ));
        $targetContext->getSession()->importXml('/', $file, ImportUUIDBehaviorInterface::IMPORT_UUID_COLLISION_THROW);
        $output->write('Saving..');
        $targetContext->getSession()->save();
        $output->writeln(' OK');
    }
}
