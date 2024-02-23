<?php

namespace ContentBlocks\ContentBlocksRegApiMigration\Utility;

use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseUtility
{
    public function __construct(
        protected readonly CommandLineUtility $commandLineUtility
    ) {
    }

    /**
     * @throws Exception
     */
    public function executeDatabaseMigrations(
        array $tableStructure,
        string $currentVendorName,
        string $newVendorName,
        string $package,
        bool $hasCollection,
        OutputInterface $output
    ): void
    {
        // ALTER TABLE tt_content with given fields
        $this->alterTtContentFields($tableStructure, $newVendorName, $package);
        // build and update CType in tt_content

        $ttContentUids = $this->replaceOldWithNewCType($currentVendorName, $newVendorName, $package);

        $childTableUids = [];
        // execute database:updateschema to create child tables if exists
        if($hasCollection) {
            $this->commandLineUtility->flushCache($output);
            $this->commandLineUtility->updateDatabaseSchema($output);
            $this->migrateChildTables($tableStructure, $childTableUids);
        }
        // migrate sys_file_reference entries of tt_content and child tables
        if(array_key_exists('sys_file_reference', $tableStructure)) {
            $this->migrateFileReferences($tableStructure['sys_file_reference'], $ttContentUids, $childTableUids, $output);
        }
    }
    /**
     * @throws Exception
     */
    public function alterTtContentFields(array $tableStructure, string $newVendorName, string $package): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $columnDataTypes = $connection->executeQuery('SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = "'.$connection->getDatabase().'" AND TABLE_NAME = "tt_content";')->fetchAllAssociative();
        foreach ($columnDataTypes as $key => $columnDataType) {
            $columnDataTypes[$columnDataType['COLUMN_NAME']] = $columnDataType['COLUMN_TYPE'];
            unset($columnDataTypes[$key]);
        }
        $package = str_replace('-', '_', $package);
        $insertFields = [];
        foreach ($tableStructure as $oldFieldname => $newFieldname) {
            if(!is_array($newFieldname)) {
                if(array_key_exists($oldFieldname, $columnDataTypes)) {
                    $newField = $newVendorName . '_' . str_replace(['-', '_'], '', $package) . '_' . $newFieldname;
                    if(!in_array($newField, $insertFields)) {
                        $resultSet = $connection->executeQuery('ALTER TABLE tt_content CHANGE COLUMN `' . $oldFieldname . '` `' . $newField .'` ' . $columnDataTypes[$oldFieldname]);
                        $insertFields[] = $newField;
                    }
                }
            } else {
                if(array_key_exists('content_block_field_identifier', $newFieldname) && array_key_exists($newFieldname['content_block_field_identifier'], $columnDataTypes)) {
                    if(!in_array($oldFieldname, $insertFields)) {
                        $resultSet = $connection->executeQuery('ALTER TABLE tt_content CHANGE COLUMN `' . $newFieldname['content_block_field_identifier'] . '` `' . $oldFieldname .'` ' . $columnDataTypes[$newFieldname['content_block_field_identifier']]);
                        $insertFields[] = $newFieldname['content_block_field_identifier'];
                    }
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    public function replaceOldWithNewCType(string $currentVendorName, string $newVendorName, string $package): array
    {
        $ttContentUids = [0];
        $currentCType = $currentVendorName . '_' . $package;
        $newCType = $newVendorName . '_' . str_replace(['-', '_'], '', $package);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $resultSet = $connection->executeQuery('SELECT uid FROM tt_content WHERE CType="' . $currentCType . '"');
        $fetchedRows = $resultSet->fetchAllAssociative();
        foreach ($fetchedRows as $row) {
            $ttContentUids[] = $row['uid'];
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tt_content');
            $connection->update('tt_content', ['CType' => $newCType], ['uid' => $row['uid']]);
        }
        return $ttContentUids;
    }

    /**
     * @throws Exception
     */
    public function migrateChildTables(array $tableStructure, array &$childTableUids): void
    {
        // migrate child tables
        foreach ($tableStructure as $childTable => $fields) {
            if (is_array($fields) && $childTable !== 'sys_file_reference') {
                $parentTable = $fields['content_block_foreign_table_field'];
                $parentField = $fields['content_block_field_identifier'];
                unset($fields['content_block_foreign_table_field']);
                unset($fields['content_block_field_identifier']);
                $translationMapping = [];
                // fetch all entries from collections table with l10n_parent = 0
                foreach ($this->fetchFieldsFromCollectionsTable($fields, $parentTable, $parentField) as $row) {
                    $oldEntryUid = $row['uid'];
                    unset($row['uid']);
                    $lastInsertId = $this->insertNewEntryInChildTable($fields, $childTable, $row);
                    $childTableUids[$childTable][$oldEntryUid] = $lastInsertId;
                    $translationMapping[$oldEntryUid] = $lastInsertId;
                    // delete entry from collections table when successfully migrated
                    $this->deleteEntryFromCollectionsTable($oldEntryUid);
                }

                // fetch and migrate all entries from collections table with l10n_parent != 0
                foreach ($this->fetchFieldsFromCollectionsTable($fields, $parentTable, $parentField, false) as $row) {
                    $oldEntryUid = $row['uid'];
                    unset($row['uid']);
                    $row['l10n_parent'] = $translationMapping[$row['l10n_parent']] ?? 0;
                    $lastInsertId = $this->insertNewEntryInChildTable($fields, $childTable, $row);
                    $childTableUids[$childTable][$oldEntryUid] = $lastInsertId;
                    // delete entry from collections table when successfully migrated
                    $this->deleteEntryFromCollectionsTable($oldEntryUid);
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    protected function fetchFieldsFromCollectionsTable(array $fields, string $parentTable, string $parentField, bool $excludeTranslations = true): array {
        // get core fields + content_block_foreign_field
        $select = 'uid,pid,crdate,tstamp,starttime,endtime,deleted,hidden,sorting,sys_language_uid,l10n_parent,l10n_diffsource,t3_origuid,content_block_foreign_field,';
        foreach ($fields as $oldFieldname => $newFieldname) {
            $select .= $oldFieldname . ',';
        }
        $select = rtrim($select, ',');
        $where = 'WHERE content_block_foreign_table_field="' . $parentTable . '" AND content_block_field_identifier="' . $parentField . '"';
        if($excludeTranslations) {
            $where .= ' AND l10n_parent = 0';
        } else {
            $where .= ' AND l10n_parent > 0';
        }
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_contentblocks_reg_api_collection');
        // TODO special case where extbase tables were migrated to content blocks child tables
        try {
            $resultSet = $connection->executeQuery('SELECT ' . $select . ' FROM tx_contentblocks_reg_api_collection ' . $where);
            return $resultSet->fetchAllAssociative();
        } catch (Exception $e) {
            echo "Child table could not be migrated. Maybe you have some manual changes after first migration. Please check the table manually.\n";
            return [];
        }
    }

    protected function insertNewEntryInChildTable(array $fields, string $childTable, array $row): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($childTable);
        foreach ($fields as $oldFieldname => $newFieldname) {
            $row[$newFieldname] = $row[$oldFieldname];
            unset($row[$oldFieldname]);
        }
        // get/set foreign_table_parent_uid from content_block_foreign_field
        $row['foreign_table_parent_uid'] = $row['content_block_foreign_field'];
        unset($row['content_block_foreign_field']);
        // reset t3_origuid
        $row['t3_origuid'] = 0;
        $affectedRows = $connection->insert($childTable, $row);
        if($affectedRows === 0) {
            throw new \RuntimeException(
                'The entry could not be inserted in the child table "' . $childTable . '"',
                1678781015
            );
        }
        return $connection->lastInsertId();
    }

    protected function deleteEntryFromCollectionsTable(int $uid): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_contentblocks_reg_api_collection');
        $connection->delete('tx_contentblocks_reg_api_collection', [
            'uid' => $uid
        ]);
    }

    protected function migrateFileReferences(array $sys_file_reference, array $ttContentUids, array $childTableUids, OutputInterface $output): void
    {
        foreach ($sys_file_reference as $sysFileProperties) {
            if(!empty($sysFileProperties['collectionIdentifier']) && array_key_exists($sysFileProperties['tablenames'], $childTableUids)) {
                $this->migrateSysFileReferenceCollection($sysFileProperties, $childTableUids[$sysFileProperties['tablenames']]);
            }
            else if($sysFileProperties['tablenames'] === 'tt_content') {
                if($sysFileProperties['fieldname'] !== $sysFileProperties['fieldnameOld']) {
                    $this->migrateSysFileReferenceTtContent($sysFileProperties, $ttContentUids, $output);
                }
            } else {
                $output->writeln('<warning>No matching migration requirements were found for sys_file_references of table "' . $sysFileProperties['tablenames'] . '" and fieldname "' . $sysFileProperties['fieldname'] . '" .</warning>');
            }
        }
    }

    protected function migrateSysFileReferenceTtContent(array $sysFileProperties, array $ttContentUids, OutputInterface $output): void {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');
        $queryBuilder = $connection->createQueryBuilder();
        $affectedRows = $queryBuilder
            ->update('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->in('uid_foreign', $ttContentUids),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($sysFileProperties['fieldnameOld']))
            )
            ->set('fieldname', $sysFileProperties['fieldname'])
            ->executeStatement();
        if($affectedRows === 0 && count($ttContentUids) > 1) {
            $output->writeln('<warning>No entries found for fieldname "' . $sysFileProperties['fieldname'] . '" which need to be migrated.</warning>');
        }
    }

    protected function migrateSysFileReferenceCollection(array $sysFileProperties, $childTableUids): void {
        foreach ($childTableUids as $oldChildUid => $newChildUid) {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('sys_file_reference');
            $connection->update(
                'sys_file_reference',
                [
                    'fieldname' => $sysFileProperties['fieldname'],
                    'tablenames' => $sysFileProperties['tablenames'],
                    'uid_foreign' => $newChildUid
                ],
                [
                    'fieldname' => $sysFileProperties['collectionIdentifier'].'.'.$sysFileProperties['fieldname'],
                    'tablenames' => 'tx_contentblocks_reg_api_collection',
                    'uid_foreign' => $oldChildUid
                ]
            );
        }
    }
}
