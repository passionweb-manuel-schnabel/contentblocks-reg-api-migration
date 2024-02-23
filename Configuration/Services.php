<?php

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use ContentBlocks\ContentBlocksRegApiMigration\Command\ContentBlockMigrationCommand;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    $services = $containerConfigurator->services();
    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure();

    $services->load('ContentBlocks\\ContentBlocksRegApiMigration\\', __DIR__ . '/../Classes/')
        ->exclude([
            __DIR__ . '/../Classes/Domain/Model',
        ]);

    $services->set('ContentBlockMigrationCommand', ContentBlockMigrationCommand::class)
        ->tag('console.command', [
            'command' => 'content-blocks:migrate',
            'description' => 'Migrate content blocks from Content Blocks Registration API to TYPO3 Content Blocks.',
            'schedulable' => false,
            'hidden' => false,
        ]);
};
