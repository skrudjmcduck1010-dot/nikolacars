<?php

namespace App\Console\Commands;

use App\Models\CustomerOrderShipment;
use App\Services\NovaPoshtaInternetDocumentService;
use Illuminate\Console\Command;

class DiagnoseNovaPoshtaTrackingNumbers extends Command
{
    protected $signature = 'customer-orders:diagnose-nova-poshta-ttns
        {--order= : Limit to one customer order number}
        {--no-api : Do not ask Nova Poshta API to resolve missing refs}
        {--fix : Save refs that the current API key can resolve}';

    protected $description = 'Classify Nova Poshta TTNs as API-visible or likely manual/other-cabinet.';

    public function handle(NovaPoshtaInternetDocumentService $novaPoshtaService): int
    {
        $orderNumber = trim((string) $this->option('order'));
        $useApi = ! (bool) $this->option('no-api');
        $fix = (bool) $this->option('fix');
        $stats = [
            'api_stored' => 0,
            'api_resolved' => 0,
            'external_or_manual' => 0,
            'unknown_no_api' => 0,
            'failed' => 0,
        ];
        $rows = [];

        $query = CustomerOrderShipment::query()
            ->with('customerOrder:id,number')
            ->where('carrier', CustomerOrderShipment::CARRIER_NOVA_POSHTA)
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->orderBy('customer_order_id')
            ->orderBy('id');

        if ($orderNumber !== '') {
            $query->whereHas('customerOrder', fn ($query) => $query->where('number', $orderNumber));
        }

        $query->each(function (CustomerOrderShipment $shipment) use ($novaPoshtaService, $useApi, $fix, &$stats, &$rows): void {
            $orderNumber = $shipment->customerOrder?->number ?? '#'.$shipment->customer_order_id;
            $trackingNumber = (string) $shipment->tracking_number;
            $storedRef = trim((string) $shipment->np_ref);
            $resolvedRef = $storedRef;
            $status = 'api-stored';
            $note = 'np_ref already stored';

            if ($storedRef !== '') {
                $stats['api_stored']++;
            } elseif (! $useApi) {
                $status = 'unknown-no-api';
                $note = 'np_ref is empty; API check skipped';
                $stats['unknown_no_api']++;
            } else {
                try {
                    $resolvedRef = $novaPoshtaService->resolveDocumentRef($shipment, $fix) ?? '';
                    if ($resolvedRef !== '') {
                        $status = $fix ? 'api-resolved-saved' : 'api-resolved';
                        $note = $fix ? 'current API key resolved and saved np_ref' : 'current API key can resolve np_ref';
                        $stats['api_resolved']++;
                    } else {
                        $status = 'external-or-manual';
                        $note = 'current API key cannot resolve this TTN';
                        $stats['external_or_manual']++;
                    }
                } catch (\Throwable $exception) {
                    $status = 'failed';
                    $note = $exception->getMessage();
                    $stats['failed']++;
                }
            }

            $rows[] = [
                $orderNumber,
                $shipment->id,
                $trackingNumber,
                $status,
                $resolvedRef !== '' ? $resolvedRef : '-',
                $note,
            ];
        });

        if ($rows === []) {
            $this->info('No Nova Poshta TTNs found.');

            return self::SUCCESS;
        }

        $this->table(['Order', 'Shipment', 'TTN', 'Classification', 'NP Ref', 'Note'], $rows);
        $this->line(sprintf(
            'Stored: %d, API-resolved: %d, external/manual: %d, unknown(no API): %d, failed: %d.',
            $stats['api_stored'],
            $stats['api_resolved'],
            $stats['external_or_manual'],
            $stats['unknown_no_api'],
            $stats['failed'],
        ));

        if ($stats['external_or_manual'] > 0) {
            $this->warn('external-or-manual means: the current API key did not find this TTN in InternetDocument.getDocumentList. It may still print in the browser cabinet if that browser session has access.');
        }

        return self::SUCCESS;
    }
}
