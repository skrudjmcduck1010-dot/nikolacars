<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncActiveCustomerOrderPricesToCatalog extends Command
{
    protected $signature = 'customer-orders:sync-active-prices-to-catalog {--dry-run : Show what would change without saving}';

    protected $description = 'Retired compatibility command; customer order prices no longer sync back to catalog items.';

    public function handle(): int
    {
        $this->warn('Customer order prices are order-only now; no catalog or product prices were changed.');

        return self::SUCCESS;
    }
}
