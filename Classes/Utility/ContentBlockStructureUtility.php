<?php

namespace ContentBlocks\ContentBlocksRegApiMigration\Utility;

use Symfony\Component\Console\Output\OutputInterface;

class ContentBlockStructureUtility
{
    public function checkFolderStructure(string $packagePath): void {
        if(!is_dir($packagePath."ContentBlocks")) {
            mkdir($packagePath."ContentBlocks");
        }
        if(!is_dir($packagePath."ContentBlocks/ContentElements")) {
            mkdir($packagePath."ContentBlocks/ContentElements");
        }
    }

    public function copyFilesAndFolders(string $source, string $destination): void
    {
        if (is_dir($source)) {
            @mkdir($destination);
            $directory = dir($source);
            while (false !== ($entry = $directory->read())) {
                if ($entry == '.' || $entry == '..') {
                    continue;
                }
                $destEntry = $entry;
                if($entry === 'src') {
                    $destEntry = 'Source';
                }
                if($entry === 'dist') {
                    $destEntry = 'Assets';
                }
                $this->copyFilesAndFolders("$source/$entry", "$destination/$destEntry");
            }
            $directory->close();
        } else {
            copy($source, $destination);
        }
    }

    public function moveIconAndLanguageFile(string $packagePath, string $contentBlockFilename): void
    {
        rename($packagePath."ContentBlocks/ContentElements/".$contentBlockFilename."/ContentBlockIcon.svg",
            $packagePath."ContentBlocks/ContentElements/".$contentBlockFilename."/Assets/Icon.svg");

        rename($packagePath."ContentBlocks/ContentElements/".$contentBlockFilename."/Source/Language/Default.xlf",
            $packagePath."ContentBlocks/ContentElements/".$contentBlockFilename."/Source/Language/Labels.xlf");
    }

    public function refactorFirstLevelIdentifiers(string $packageSourcePath, string $package, array $tableStructure, OutputInterface $output): void
    {
        $hasTemplateDependencies = false;
        $editorPreviewContents = file_get_contents($packageSourcePath."/EditorPreview.html");
        // replace strings that starts with <f:format.html parseFuncTSPath="*"> with <f:format.raw>
        $editorPreviewContents = preg_replace('/<f:format.html parseFuncTSPath=".*">/', '<f:format.raw>', $editorPreviewContents);
        $editorPreviewContents = str_replace('</f:format.html>', '</f:format.raw>', $editorPreviewContents);
        file_put_contents($packageSourcePath."/EditorPreview.html", $this->refactorTemplateContents($editorPreviewContents, $package, $tableStructure,$hasTemplateDependencies));

        $frontendContents = file_get_contents($packageSourcePath."/Frontend.html");
        file_put_contents($packageSourcePath."/Frontend.html", $this->refactorTemplateContents($frontendContents, $package, $tableStructure,$hasTemplateDependencies));

        if($hasTemplateDependencies) {
            $output->writeln('<warning>Some of your template files have dependencies, like layouts, sections or partials, which won\'t be migrated.</warning>');
        }
    }

    public function refactorLanguageFile(
        string $packageSourcePath,
        string $package,
        array $tableStructure
    ): void {
        $xliffContent = file_get_contents($packageSourcePath."/Language/Labels.xlf");
        // replace default title and description
        $xliffContent = str_replace('typo3-contentblocks.'.$package.'.title', 'title', $xliffContent);
        $xliffContent = str_replace('typo3-contentblocks.'.$package.'.description', 'description', $xliffContent);
        foreach ($tableStructure as $oldFieldname => $newFieldname) {
            if(!is_array($newFieldname)) {
                $oldKey = 'typo3-contentblocks.' . str_replace('cb_'.$package.'_', $package.'.', $oldFieldname);
                $xliffContent = str_replace($oldKey . '.label', $newFieldname . '.label', $xliffContent);
                $xliffContent = str_replace($oldKey . '.description', $newFieldname . '.description', $xliffContent);
            }
        }
        file_put_contents($packageSourcePath."/Language/Labels.xlf", $xliffContent);
    }

    protected function refactorTemplateContents(
        string $content,
        string $package,
        array $tableStructure,
        bool &$hasTemplateDependencies
    ): string {
        $packageName = str_replace('-', '_', $package);
        foreach ($tableStructure as $oldFieldname => $newFieldname) {
            if(!is_array($newFieldname)) {
                // search and replace fields (variants with _and -)
                $oldTemplateVariable = str_replace('cb_'.$packageName.'_', '', $oldFieldname);
                $content = str_replace('{'.$oldTemplateVariable, '{data.'.$newFieldname, $content);
                $oldTemplateVariable = str_replace('_', '-',  $oldTemplateVariable);
                $content = str_replace('{'.$oldTemplateVariable, '{data.'.$newFieldname, $content);
            }
        }
        $content = str_replace('f:asset', 'cb:asset', $content);
        // asset script collectors
        $content = str_replace('src="CB:'.$package.'/dist/', 'file="', $content);
        // asset css collectors
        $content = str_replace('href="CB:'.$package.'/dist/', 'file="', $content);

        if(str_contains($content, 'f:layout name=', ) || str_contains($content, 'f:section name=') || str_contains($content, 'f:render partial=')) {
            $hasTemplateDependencies = true;
        }

        return $content;
    }
}
