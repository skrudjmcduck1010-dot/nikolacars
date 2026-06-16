<?php

namespace App\Services;

class CompetitorCatalogPartNumberDedupeService
{
    public const SOURCES = [
        'tcarservice',
        'teslapartsukraine',
        'tsk',
        'teslahelp',
        'stock-tesla',
        'driveparts',
        'dkparts',
        'erazborka',
        'toprazborka',
        'teslawestparts',
        'teslacompany',
    ];

    public function run(array $options = []): array
    {
        return [
            'items_seen' => 0,
            'part_number_groups' => 0,
            'duplicate_groups' => 0,
            'occurrences_saved' => 0,
            'items_merged' => 0,
            'items_skipped_with_product_conflicts' => 0,
            'disabled' => 1,
        ];
    }
}
