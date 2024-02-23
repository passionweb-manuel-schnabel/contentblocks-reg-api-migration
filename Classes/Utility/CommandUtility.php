<?php

namespace ContentBlocks\ContentBlocksRegApiMigration\Utility;


use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Package\PackageInterface;

class CommandUtility
{
    public function __construct(
        protected readonly ContentBlockStructureUtility $contentBlockStructureUtility,
        protected readonly YamlUtility $yamlUtility,
        protected readonly DatabaseUtility $databaseUtility
    ) {

    }
    public function getAvailableContentBlocks(string $originPackagePath): array
    {
        $contentBlocksFolder = scandir($originPackagePath);
        $contentBlocksFolder = array_diff($contentBlocksFolder, ['.', '..']);
        $availableContentBlocks = [];
        foreach ($contentBlocksFolder as $contentBlockFolder) {
            if (is_dir($originPackagePath.$contentBlockFolder)) {
                $availableContentBlocks[$contentBlockFolder] = $contentBlockFolder;
            }
        }
        return $availableContentBlocks;
    }
    public function getNameAndDeleteComposerFile(string $destination): string {
        $composerJsonContent = file_get_contents($destination."/composer.json");
        $parsedContent = json_decode($composerJsonContent, true);
        unlink($destination."/composer.json");
        return $parsedContent['name'];
    }

    /**
     * @param array<string, PackageInterface> $availablePackages
     * @return array<string, string>
     */
    public function getPackageTitles(array $availablePackages): array
    {
        return array_map(fn(PackageInterface $package): string => $package->getPackageMetaData()->getTitle(), $availablePackages);
    }

    /**
     * @param array<string, PackageInterface> $availablePackages
     * @return array<string, string>
     */
    public function getPackageKeys(array $availablePackages): array
    {
        return array_map(fn(PackageInterface $package): string => $package->getPackageKey(), $availablePackages);
    }

    /**
     * @throws Exception
     */
    public function migrateContentBlock(
        string $contentBlockPathname,
        string $contentBlockFilename,
        string $packagePath,
        string $newVendorName,
        OutputInterface $output
    ): void
    {
        $tableStructure = [];
        $hasCollection = false;
        $output->writeln("ContentBlock: " . $contentBlockFilename);

        $this->contentBlockStructureUtility->checkFolderStructure($packagePath);
        $contentBlockFilename = str_replace('_', '-', $contentBlockFilename);
        // Recursive call to iterate over subdirectories
        $refactorContentBlocksFolder = true;

        if(is_dir($packagePath."ContentBlocks/ContentElements/".$contentBlockFilename)) {
            $refactorContentBlocksFolder = false;
            $name = $this->yamlUtility->getNameFromEditorInterfaceYaml($packagePath."ContentBlocks/ContentElements/".$contentBlockFilename);
        } else {
            $this->contentBlockStructureUtility->copyFilesAndFolders(
                $contentBlockPathname, $packagePath."ContentBlocks/ContentElements/".$contentBlockFilename);
            // move and rename icon and language file to new place
            $this->contentBlockStructureUtility->moveIconAndLanguageFile($packagePath, $contentBlockFilename);
            // get name from composer.json and delete the file
            $name = $this->getNameAndDeleteComposerFile($packagePath."ContentBlocks/ContentElements/".$contentBlockFilename);
        }

        $currentVendorName = explode('/', $name)[0];
        $package = explode('/', $name)[1];
        $onlyDbMigrations = false;
        if($currentVendorName === $newVendorName) {
            $currentVendorName = 'typo3-contentblocks';
            $onlyDbMigrations = true;
        }
        // refactor EditorInterface.yaml
        $this->yamlUtility->refactorEditorInterface($packagePath, $package, $newVendorName, $tableStructure, $hasCollection, $onlyDbMigrations);
        // database migrations
        $this->databaseUtility->executeDatabaseMigrations($tableStructure, $currentVendorName, $newVendorName, $package, $hasCollection, $output);

        if($refactorContentBlocksFolder) {
            // migrate template files and paths (assets, identifiers, etc.)
            $this->contentBlockStructureUtility->refactorFirstLevelIdentifiers(
                $packagePath."ContentBlocks/ContentElements/".$contentBlockFilename."/Source",
                $package,
                $tableStructure,
                $output
            );
            // migrate language files (Default.xlf)
            $this->contentBlockStructureUtility->refactorLanguageFile(
                $packagePath."ContentBlocks/ContentElements/".$contentBlockFilename."/Source",
                $package,
                $tableStructure
            );
        }
    }
}
