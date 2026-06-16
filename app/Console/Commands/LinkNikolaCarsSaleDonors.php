<?php

namespace App\Console\Commands;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Services\NikolaCarsInterimDonorResolver;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Console\Command;

class LinkNikolaCarsSaleDonors extends Command
{
    protected $signature = 'nikolacars:sales:link-donors
        {--dry-run : Show what would be linked without saving changes}
        {--relink-interim : Re-link sales currently attached to synthetic NC-* donors}
        {--relink-catalog-vins : Re-link interim donors to real donors when the catalog item stores a VIN}
        {--delete-orphan-synthetic : Delete NC-* donors after re-linking if they have no products or sales}
        {--delete-orphan-interim : Delete interim NikolaCars donors after re-linking if they have no products or sales}';

    protected $description = 'Create interim NikolaCars donors from catalog donor labels and link imported sales to them.';

    public function handle(NikolaCarsInterimDonorResolver $donorResolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $relinkInterim = (bool) $this->option('relink-interim');
        $relinkCatalogVins = (bool) $this->option('relink-catalog-vins');
        $stats = [
            'sales_scanned' => 0,
            'sales_linked' => 0,
            'donors_created' => 0,
            'sales_without_catalog_item' => 0,
            'sales_without_donor_label' => 0,
            'synthetic_donors_deleted' => 0,
            'interim_donors_deleted' => 0,
        ];

        PartSale::query()
            ->with('partCatalogItem')
            ->where('source', 'nikolacars')
            ->where(function ($query) use ($relinkInterim, $relinkCatalogVins): void {
                $query->whereNull('donor_car_id');

                if ($relinkInterim) {
                    $query->orWhereHas('donorCar', fn ($donorQuery) => $donorQuery->where('vin', 'like', 'NC-%'));
                }

                if ($relinkCatalogVins) {
                    $query->orWhereHas('donorCar', fn ($donorQuery) => $donorQuery->where('notes', 'like', 'Interim NikolaCars donor%'));
                }
            })
            ->orderBy('id')
            ->chunkById(200, function ($sales) use ($donorResolver, $dryRun, &$stats): void {
                foreach ($sales as $sale) {
                    $stats['sales_scanned']++;

                    if (! $sale->partCatalogItem) {
                        $stats['sales_without_catalog_item']++;

                        continue;
                    }

                    $donorVin = $sale->donor_vin ?: $this->catalogDonorVin($sale->partCatalogItem);
                    $label = $donorResolver->donorLabel($sale->partCatalogItem);
                    if ($label === null && $donorVin === null) {
                        $stats['sales_without_donor_label']++;

                        continue;
                    }

                    if ($dryRun) {
                        $stats['sales_linked']++;

                        continue;
                    }

                    $donor = $donorResolver->resolve($sale->partCatalogItem, $donorVin);
                    if (! $donor) {
                        $stats['sales_without_donor_label']++;

                        continue;
                    }

                    if ($donor->wasRecentlyCreated) {
                        $stats['donors_created']++;
                    }

                    if (! $dryRun) {
                        $sale->forceFill(['donor_car_id' => $donor->id])->save();
                    }

                    $stats['sales_linked']++;
                }
            });

        if (! $dryRun && (bool) $this->option('delete-orphan-synthetic')) {
            $stats['synthetic_donors_deleted'] = DonorCar::query()
                ->where('vin', 'like', 'NC-%')
                ->doesntHave('products')
                ->doesntHave('partSales')
                ->delete();
        }

        if (! $dryRun && (bool) $this->option('delete-orphan-interim')) {
            $stats['interim_donors_deleted'] = DonorCar::query()
                ->where('notes', 'like', 'Interim NikolaCars donor%')
                ->doesntHave('products')
                ->doesntHave('partSales')
                ->delete();
        }

        $this->info(json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    protected function catalogDonorVin($item): ?string
    {
        $raw = PartCatalogRawAttributes::from($item instanceof PartCatalogItem ? $item : null);

        $vin = trim((string) ($raw['donor_vin'] ?? ''));

        return $vin !== '' ? $vin : null;
    }
}
