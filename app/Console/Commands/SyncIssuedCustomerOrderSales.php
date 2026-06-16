<?php

namespace App\Console\Commands;

use App\Models\CustomerOrder;
use App\Services\CustomerOrderIssuedSaleService;
use Illuminate\Console\Command;

class SyncIssuedCustomerOrderSales extends Command
{
    protected $signature = 'customer-orders:sync-issued-sales';

    protected $description = 'Create NikolaCars sales and reduce inventory for issued customer orders.';

    public function handle(CustomerOrderIssuedSaleService $issuedSaleService): int
    {
        $orders = CustomerOrder::query()
            ->issuedToClient()
            ->with(['items.partCatalogItem'])
            ->orderBy('id')
            ->get();

        $sales = 0;

        foreach ($orders as $order) {
            $sales += $issuedSaleService->syncOrder($order);
        }

        $this->info(sprintf('Issued customer order sales synced. Orders: %d. Sales created or updated: %d.', $orders->count(), $sales));

        return self::SUCCESS;
    }
}
