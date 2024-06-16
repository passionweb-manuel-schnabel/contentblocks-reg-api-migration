<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ContentBlocks\ContentBlocksRegApiMigration\Command;

use ContentBlocks\ContentBlocksRegApiMigration\Utility\CommandLineUtility;
use ContentBlocks\ContentBlocksRegApiMigration\Utility\CommandUtility;
use DirectoryIterator;
use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\ContentBlocks\Service\PackageResolver;
use TYPO3\CMS\Core\Core\Environment;

/**
 * ddev typo3 content-blocks:migrate --target-extension=EXTENSION --vendor-name=VENDOR_NAME --package-path=PACKAGE_PATH --source-content-block=CONTENT_BLOCK_PACKAGE
 */
class ContentBlockMigrationCommand extends Command
{
    public function __construct(
        protected readonly PackageResolver $packageResolver,
        protected readonly CommandUtility $commandUtility,
        protected readonly CommandLineUtility $commandLineUtility
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'target-extension',
            'te',
            InputOption::VALUE_REQUIRED,
            'Target extension in which the Content Blocks should be migrated.'
        );
        $this->addOption(
            'vendor-name',
            'vn',
            InputOption::VALUE_REQUIRED,
            'Enter new vendor name new if it you want to change them (Default: extension name).'
        );
        $this->addOption(
            'package-path',
            'pp',
            InputOption::VALUE_REQUIRED,
            'Relative path from public directory to content blocks which should be migrated (with trailing slash).'
        );
        $this->addOption(
            'source-content-block',
            'scb',
            InputOption::VALUE_OPTIONAL,
            'Content block which should be migrated.'
        );
    }

    /**
     * @throws Exception
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if(!$io->askQuestion(new ConfirmationQuestion('We strongly recommend that you do not run this command in production mode!. Please perform a database backup before execution! Do you have a database backup and want to go ahead? (y/n)', false))) {
            $output->writeln('<error>Execution aborted.</error>');
            return Command::FAILURE;
        }
        $availablePackages = $this->packageResolver->getAvailablePackages();

        if ($input->getOption('package-path')) {
            $originPackagePath = $input->getOption('package-path');
        } else {
            $packagePath = $io->askQuestion(new Question('Enter relative path from public directory to content blocks which should be migrated (with trailing slash)'));
            $originPackagePath = $packagePath;
        }

        // Fix Missing / at beginning of package path:
        $originPackagePath = Environment::getPublicPath() . "/" . ltrim( $originPackagePath , "/") ;

        if ( !is_dir($originPackagePath)) {
            throw new \RuntimeException('Please check the given --package-path, as absolute Path results in a not existing folder: ' . $originPackagePath , 1678699706);
        }
        $availableContentBlocks = $this->commandUtility->getAvailableContentBlocks($originPackagePath);
        if ($availablePackages === []) {
            throw new \RuntimeException('No packages were found in which to migrate the content blocks.', 1678699706);
        }
        if ($availableContentBlocks === []) {
            throw new \RuntimeException('No content blocks were found for migration.', 1678699706);
        }
        $sourceContentBlock = null;
        if ($input->getOption('source-content-block')) {
            $sourceContentBlock = $input->getOption('source-content-block');
            if (!array_key_exists($sourceContentBlock, $availableContentBlocks)) {
                throw new \RuntimeException(
                    'The content-block "' . $sourceContentBlock . '" could not be found. Please choose one of these content blocks: ' . implode(', ', $availableContentBlocks),
                    1678781015
                );
            }
        }
        if ($input->getOption('target-extension')) {
            $targetExtension = $input->getOption('target-extension');
            if (!array_key_exists($targetExtension, $availablePackages)) {
                throw new \RuntimeException(
                    'The extension "' . $targetExtension . '" could not be found. Please choose one of these extensions: ' . implode(', ', $this->commandUtility->getPackageKeys($availablePackages)),
                    1678781015
                );
            }
        } else {
            $targetExtension = $io->askQuestion(new ChoiceQuestion('Choose the target extension in which the content blocks should be migrated', $this->commandUtility->getPackageTitles($availablePackages)));
        }
        if ($input->getOption('vendor-name')) {
            $newVendorName = $input->getOption('vendor-name');
        } else {
            $newVendorName = $io->askQuestion(new Question('Enter new vendor name new if it you want to change them (Default: "'. $targetExtension .'")'));
            if($newVendorName === null) {
                $newVendorName = $targetExtension;
            }
        }
        // migrate only selected content block
        if($sourceContentBlock !== null) {
            $this->commandUtility->migrateContentBlock($originPackagePath.$sourceContentBlock, $sourceContentBlock, $availablePackages[$targetExtension]->getPackagePath(), $newVendorName, $output);
        }
        // migrate all content blocks
        else {
            $contentBlocksDir = new DirectoryIterator($originPackagePath);
            foreach ($contentBlocksDir as $contentBlockFolder) {
                if (!$contentBlockFolder->isDot()) {
                    if ($contentBlockFolder->isDir()) {
                        $this->commandUtility->migrateContentBlock($contentBlockFolder->getPathname(), $contentBlockFolder->getFilename(), $availablePackages[$targetExtension]->getPackagePath(), $newVendorName, $output);
                    }
                }
            }
        }
        $this->commandLineUtility->flushCache($output);
        $output->writeln('<success>Migration finished.</success>');
        return Command::SUCCESS;
    }
}