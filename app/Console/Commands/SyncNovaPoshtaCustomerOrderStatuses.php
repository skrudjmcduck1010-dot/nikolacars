<?php

namespace App\Console\Commands;

use App\Models\CustomerOrder;
use App\Services\CustomerOrderNovaPoshtaStatusSyncService;
use Illuminate\Console\Command;

class SyncNovaPoshtaCustomerOrderStatuses extends Command
{
    protected $signature = 'customer-orders:sync-nova-poshta-statuses';

    protected $description = 'Sync Nova Poshta TTN statuses and mark accepted shipments as shipped.';

    public function handle(CustomerOrderNovaPoshtaStatusSyncService $syncService): int
    {
        $stats = $syncService->syncPending(function (CustomerOrder $order, ?array $result, ?\Throwable $exception): void {
            if ($exception instanceof \Throwable) {
                $this->warn("Order {$order->number}: ".$exception->getMessage());

                return;
            }

            if (! is_array($result) || ! ($result['checked'] ?? false)) {
                $this->line("Order {$order->number}: skipped.");

                return;
            }

            $status = trim((string) ($result['status'] ?? '')) ?: '-';
            $code = trim((string) ($result['status_code'] ?? '')) ?: '-';
            $prefix = ($result['refused'] ?? false)
                ? 'refused'
                : (($result['shipped'] ?? false) ? 'shipped' : 'checked');

            $this->line("Order {$order->number}: {$prefix}, NP {$code} {$status}");
        });

        $this->info(sprintf(
            'Nova Poshta statuses synced. Checked: %d, shipped: %d, refused: %d, skipped: %d, failed: %d.',
            $stats['checked'],
            $stats['shipped'],
            $stats['refused'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
