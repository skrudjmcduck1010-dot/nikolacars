<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NovaPoshtaDirectoryService
{
    public function cities(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return collect($this->request('Address', 'getCities', [
            'FindByString' => $query,
            'Limit' => 20,
        ]))->map(fn (array $city): array => [
            'ref' => (string) ($city['Ref'] ?? ''),
            'description' => (string) ($city['Description'] ?? ''),
            'description_ru' => (string) ($city['DescriptionRu'] ?? ''),
            'area' => (string) ($city['AreaDescription'] ?? ''),
            'settlement_type' => (string) ($city['SettlementTypeDescription'] ?? ''),
        ])->filter(fn (array $city): bool => $city['ref'] !== '' && $city['description'] !== '')
            ->values()
            ->all();
    }

    public function warehouses(string $cityRef, string $query): array
    {
        $cityRef = trim($cityRef);
        $query = trim($query);

        if ($cityRef === '' || mb_strlen($query) < 1) {
            return [];
        }

        return collect($this->request('Address', 'getWarehouses', [
            'CityRef' => $cityRef,
            'FindByString' => $query,
            'Limit' => 30,
        ]))->map(fn (array $warehouse): array => [
            'ref' => (string) ($warehouse['Ref'] ?? ''),
            'description' => (string) ($warehouse['Description'] ?? ''),
            'description_ru' => (string) ($warehouse['DescriptionRu'] ?? ''),
            'number' => (string) ($warehouse['Number'] ?? ''),
            'category' => (string) ($warehouse['CategoryOfWarehouse'] ?? ''),
            'type' => (string) ($warehouse['TypeOfWarehouse'] ?? ''),
        ])->filter(fn (array $warehouse): bool => $warehouse['ref'] !== '' && $warehouse['description'] !== '')
            ->values()
            ->all();
    }

    protected function request(string $modelName, string $calledMethod, array $methodProperties): array
    {
        $apiKey = trim((string) config('services.nova_poshta.api_key'));
        $apiUrl = trim((string) config('services.nova_poshta.api_url'));

        if ($apiKey === '' || $apiUrl === '') {
            throw new RuntimeException('Nova Poshta is not configured.');
        }

        $response = Http::timeout((int) config('services.nova_poshta.timeout', 15))
            ->connectTimeout((int) config('services.nova_poshta.connect_timeout', 30))
            ->acceptJson()
            ->post($apiUrl, [
                'apiKey' => $apiKey,
                'modelName' => $modelName,
                'calledMethod' => $calledMethod,
                'methodProperties' => $methodProperties,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Nova Poshta directory API HTTP '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            $errors = collect((array) ($payload['errors'] ?? []))->filter()->implode('; ');
            $warnings = collect((array) ($payload['warnings'] ?? []))->filter()->implode('; ');

            throw new RuntimeException($errors !== '' ? $errors : ($warnings !== '' ? $warnings : 'Nova Poshta directory API error.'));
        }

        return (array) ($payload['data'] ?? []);
    }
}
