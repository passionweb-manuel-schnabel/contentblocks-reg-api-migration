<?php

namespace ContentBlocks\ContentBlocksRegApiMigration\Utility;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CommandLineUtility
{
    public function flushCache(OutputInterface $output): void
    {
        $output->writeln('<info>Execute ".typo3/bin/typo3 cache:flush".</info>');
        $command = $this->determineBinPath() ?? '.typo3/bin/typo3';

        exec($command . ' cache:flush', $execOutput, $returnVar);
        foreach ($execOutput as $line) {
            $output->writeln($line);
        }
        if($returnVar !== 0) {
            throw new \RuntimeException(
                'Execution of command ".typo3/bin/typo3 cache:flush" failed.',
                1678781015
            );
        }
    }

    public function updateDatabaseSchema(OutputInterface $output): void
    {
        $output->writeln('<info>Execute ".typo3/bin/typo3 database:updateschema" to generate child tables.</info>');
        $command = $this->determineBinPath() ?? '.typo3/bin/typo3';
        exec($command. ' database:updateschema "table.add"', $execOutput, $returnVar);
        foreach ($execOutput as $line) {
            $output->writeln($line);
        }
        if($returnVar !== 0) {
            throw new \RuntimeException(
                'Execution of command ".typo3/bin/typo3 database:updateschema "table.add"" failed.',
                1678781015
            );
        }
    }

    private function determineBinPath(): ?string
    {
        if (!Environment::isComposerMode()) {
            return GeneralUtility::getFileAbsFileName('EXT:core/bin/typo3');
        }
        $composerJsonFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/composer.json';
        if (!file_exists($composerJsonFile) || !($jsonContent = file_get_contents($composerJsonFile))) {
            return null;
        }
        $jsonConfig = @json_decode($jsonContent, true);
        if (empty($jsonConfig) || !is_array($jsonConfig)) {
            return null;
        }
        $vendorDir = trim($jsonConfig['config']['vendor-dir'] ?? 'vendor', '/');
        $binDir = trim($jsonConfig['config']['bin-dir'] ?? $vendorDir . '/bin', '/');

        return sprintf('%s/%s/typo3', getenv('TYPO3_PATH_COMPOSER_ROOT'), $binDir);
    }
}
