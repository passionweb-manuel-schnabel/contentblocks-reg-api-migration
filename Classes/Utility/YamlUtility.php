<?php

namespace ContentBlocks\ContentBlocksRegApiMigration\Utility;

use Symfony\Component\Yaml\Yaml;

class YamlUtility
{
    // mapping with old and new file types and fields
    protected $fieldTypeMappings = [
        'Url' =>  ['type' => 'Link'],
        'Image' => ['type' => 'File'],
        'Date' => ['type' => 'DateTime'],
        'Integer' => ['type' => 'Number'],
        'Money' => ['type' => 'Text'],
        'Select' => ['renderType' => 'selectSingle'],
        'MultiSelect' => ['type' => 'Select', 'renderType' => 'selectMultipleSideBySide'],
    ];

    protected $fieldMappings = [
        'minItems' => 'minitems',
        'maxItems' => 'maxitems',
    ];

    public function refactorEditorInterface(
        string $packagePath,
        string $package,
        string $newVendorName,
        array &$tableStructure,
        bool &$hasCollection,
        bool $onlyDbMigrations
    ): void
    {
        $package = str_replace('_', '-', $package);
        $currentYamlContents = file_get_contents($packagePath."ContentBlocks/ContentElements/".$package."/EditorInterface.yaml");
        $currentYamlData = Yaml::parse($currentYamlContents);
        $resultArray['name'] = $newVendorName . '/' . $package;
        $resultArray = array_merge($resultArray, $this->processYamlArray($currentYamlData, $tableStructure, $newVendorName, str_replace('-', '_', $package), $hasCollection));
        if(!$onlyDbMigrations) {
            $convertedEditorInterfaceYaml = Yaml::dump($resultArray, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            file_put_contents($packagePath."ContentBlocks/ContentElements/".$package."/EditorInterface.yaml", $convertedEditorInterfaceYaml);
        }
    }

    protected function processYamlArray(
        array $inputArray,
        array &$tableStructure,
        string $vendor,
        string $package,
        bool &$hasCollection,
        string $table = 'tt_content',
        string $collectionIdentifier = '',
        string $fieldType = ''
    ): array
    {
        $outputArray = [];
        foreach ($inputArray as $key => $value) {
            // If the current element is an array, recursively process it
            if (is_array($value)) {
                $value = $this->processYamlArray($value, $tableStructure, $vendor, $package, $hasCollection, $table, $collectionIdentifier, $inputArray['type'] ?? '');
            }
            if($key === 'type') {
                $inputArrayIdentifier = str_replace('-', '_', $inputArray['identifier']);
                $useExistingField = array_key_exists('properties', $inputArray) && array_key_exists('useExistingField', $inputArray['properties']) ? $inputArray['properties']['useExistingField'] : false;
                // if we execute only db migrations, we already have the updated yaml structure
                $useExistingField = array_key_exists('useExistingField', $inputArray) ? $inputArray['useExistingField'] : $useExistingField;
                if(array_key_exists($value, $this->fieldTypeMappings)) {
                    foreach ($this->fieldTypeMappings[$value] as $fieldKey => $fieldValue) {
                        if($fieldKey === 'type') {
                            $value = $this->fieldTypeMappings[$value]['type'];
                            continue;
                        }
                        $outputArray[$fieldKey] = $fieldValue;
                    }
                }
                if($value === 'Collection') {
                    $collectionIdentifier = str_replace('-', '_', $inputArray['identifier']);
                    $currentTable = $table;
                    $table = $vendor.'_'.$package.'_'.$inputArrayIdentifier;
                    $tableStructure[$table] = [
                        'content_block_foreign_table_field' => $currentTable,
                        'content_block_field_identifier' => 'cb_'.$package.'_'.$inputArrayIdentifier
                    ];
                    $hasCollection = true;
                }
                if($value === 'File' && $table !== 'tt_content') {
                    $tableStructure['sys_file_reference']['cb_'.$package.'_'.$collectionIdentifier.'_'.$inputArrayIdentifier] = [
                        'collectionIdentifier' => $collectionIdentifier,
                        'fieldname' => $inputArrayIdentifier,
                        'tablenames' => $table,
                    ];
                } elseif($value === 'File') {
                    $tableStructure['sys_file_reference']['cb_'.$package.'_'.$inputArrayIdentifier] = [
                        'fieldname' => $useExistingField ? $inputArrayIdentifier : $vendor . '_' . str_replace('_', '', $package) . '_' . $inputArrayIdentifier,
                        'fieldnameOld' => $inputArrayIdentifier,
                        'tablenames' => $table,
                    ];
                }
            }
            // Check if "properties" field exists
            if ($key === 'properties' && is_array($value)) {
                // map fields in value array if exists
                foreach ($value as $propertyKey => $propertyValue) {
                    if (array_key_exists($propertyKey, $this->fieldMappings)) {
                        $value[$this->fieldMappings[$propertyKey]] = $propertyValue;
                        unset($value[$propertyKey]);
                    }
                }
                // Move values of "Properties" one level above
                $outputArray = array_merge($outputArray, $value);
                continue;
            }
            if($key === 'items' && $fieldType === 'Checkbox') {
                // TODO: checkbox item values are not migrated
                foreach ($value as $itemKey => $itemValue) {
                    $outputArray['items'][]['label'] = $itemValue;
                }
                continue;
            }
            if($key === 'items' && ($fieldType === 'Select'  || $fieldType === 'MultiSelect' || $fieldType === 'Radio')) {
                foreach ($value as $itemKey => $itemValue) {
                    $outputArray['items'][] = [
                        'label' => $itemValue,
                        'value' => $itemKey
                    ];
                }
                continue;
            }

            // add the processed key-value pair to the output array
            $outputArray[$key] = $value;
            // add identifier to table structure if it is not an already existing field
            $useExistingField = array_key_exists('properties', $inputArray) && array_key_exists('useExistingField', $inputArray['properties']) ? $inputArray['properties']['useExistingField'] : false;
            // if we execute only db migrations, we we already have the updated yaml structure
            $useExistingField = array_key_exists('useExistingField', $inputArray) ? $inputArray['useExistingField'] : $useExistingField;
            if($key === 'identifier' && !$useExistingField) {
                if($table !== 'tt_content') {
                    if($value !== $collectionIdentifier) {
                        $tableStructure[$table]['cb_'.$package.'_'.$collectionIdentifier.'_'.str_replace('-', '_', $value)] = $value;
                    }
                    continue;
                }
                $tableStructure['cb_'.$package.'_'.str_replace('-', '_', $value)] = $value;
            }
        }

        return $outputArray;
    }

    public function getNameFromEditorInterfaceYaml(string $destination): string {
        $yamlContent = file_get_contents($destination."/EditorInterface.yaml");
        $parsedContent = Yaml::parse($yamlContent);
        return $parsedContent['name'];
    }
}
