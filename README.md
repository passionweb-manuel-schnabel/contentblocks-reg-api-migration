# Migrate content blocks from Content Blocks Registration API to TYPO3 CMS Content Blocks.

Adds a migration command to migrate content blocks from the Content Blocks Registration API to TYPO3 CMS Content Blocks.

## What does it do?

* copy old content blocks to new place (folder ContentBlocks/ContentElements/)
* build new folder structure
  * delete unnecessary files (e.g. composer.json)
  * rename files (e.g. language files, icon file)
  * rename folders (e.g. src to Source and dist to Assets)
  * adjust language files
* convert the EditorInterface.yaml
* adjust template files (only Frontend.html and EditorPreview.html)
  * (adds the "data-" prefix to all variables which are not declared as "useExistingFields" in the EditorInterface.yaml)
  * convert the EXT:content_blocks specific ViewHelpers
* convert database structure

## What does it not do?

* Considers Collections on first level (Collections in Collections ... in Collections ... are not tested/supported)
* Template adjustments must be done manually (the migration only adjust the above-mentioned parts)

## Installation

Add via composer:

    composer require passionweb/contentblocks-reg-api-migration --dev

or

    composer require passionweb/contentblocks-reg-api-migration:dev-master --dev


* Install the extension via composer
* Flush TYPO3 and PHP Cache

## Requirements

* TYPO3 12.4
* [EXT:content_blocks](https://extensions.typo3.org/extension/content_blocks "EXT:content_blocks")

## Important notes / before you start

Be sure to have a backup of your database and files (if not, do it before you start)!
This migration should not be executed on a live system without a backup!
An additional question will be asked before the migration starts, so you have the possibility to cancel the migration before things get changed.

## Command details

    ddev typo3 content-blocks:migrate --target-extension=EXTENSION --vendor-name=VENDOR_NAME --package-path=PACKAGE_PATH --source-content-block=CONTENT_BLOCK_PACKAGE

* `--target-extension` (required): The extension key of the target extension where the content blocks should be migrated to.
* `--vendor-name` (required): The vendor name of the migrated content blocks.
* `--package-path` (required): The path to the package of the "old" content blocks which should be migrated (relative from document root).
* `--source-content-block` (optional): The package key of one "old" content block which should be migrated.

## Drawbacks to keep in mind

If you migrate the database structure, it is possible that you get a "Row size too large" error while running `database updateschema`.

Sometimes it is enough if you run the `database updateschema` command again with additional argument like `"table.add"`.
If this does not help, you have to analyze the database structure manually based on the information from the "Row size too large" error and execute some necessary SQL queries.

## Troubleshooting and logging

If something does not work as expected take a look at the log file first.
Every problem is logged to the TYPO3 log (normally found in `var/log/typo3_*.log`)

## Achieving more together or Feedback, Feedback, Feedback

I'm grateful for any feedback! Be it suggestions for improvement, extension requests or just a (constructive) feedback on how good or crappy the extension is.

Feel free to send me your feedback to [service@passionweb.de](mailto:service@passionweb.de "Send Feedback") or [contact me on Slack](https://typo3.slack.com/team/U02FG49J4TG "Contact me on Slack")

