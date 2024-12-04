<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'ContentBlocks Registration-API Migration-Command',
    'description' => 'Migrate content blocks from Content Blocks Registration API to TYPO3 CMS Content Blocks.',
    'category' => 'misc',
    'author' => 'Manuel Schnabel',
    'author_email' => 'service@passionweb.de',
    'state' => 'stable',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'content_blocks' => '0.7.0-0.8.0',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
