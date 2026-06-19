<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderShipment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NovaPoshtaInternetDocumentService
{
    public function create(CustomerOrder $order, CustomerOrderShipment $shipment): array
    {
        $config = $this->requiredConfig();
        $payload = [
            'apiKey' => $config['api_key'],
            'modelName' => 'InternetDocument',
            'calledMethod' => 'save',
            'methodProperties' => $this->methodProperties($order, $shipment, $config),
        ];

        $response = Http::timeout((int) config('services.nova_poshta.timeout', 15))
            ->connectTimeout((int) config('services.nova_poshta.connect_timeout', 30))
            ->acceptJson()
            ->post((string) config('services.nova_poshta.api_url'), $payload);

        if (! $response->ok()) {
            throw new RuntimeException('Nova Poshta API HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Nova Poshta API returned an invalid response.');
        }

        if (($body['success'] ?? false) !== true) {
            throw new RuntimeException($this->apiMessage($body));
        }

        $document = $body['data'][0] ?? null;

        if (! is_array($document) || empty($document['IntDocNumber'])) {
            throw new RuntimeException('Nova Poshta API did not return a TTN number.');
        }

        return [
            'ref' => $document['Ref'] ?? null,
            'tracking_number' => $document['IntDocNumber'],
            'label_url' => null,
            'raw_response' => $body,
        ];
    }

    public function labelPdf(CustomerOrderShipment $shipment): string
    {
        $apiKey = (string) config('services.nova_poshta.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Nova Poshta API key is not configured.');
        }

        if (! $shipment->tracking_number) {
            throw new RuntimeException('TTN number is missing.');
        }

        $url = rtrim((string) config('services.nova_poshta.print_url'), '/')
            .'/orders[]/'.rawurlencode($shipment->tracking_number)
            .'/type/pdf/apiKey/'.rawurlencode($apiKey);

        $response = Http::timeout((int) config('services.nova_poshta.timeout', 15))
            ->connectTimeout((int) config('services.nova_poshta.connect_timeout', 30))
            ->get($url);

        if (! $response->ok()) {
            throw new RuntimeException('Nova Poshta label API HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    public function delete(CustomerOrderShipment $shipment): array
    {
        $config = $this->requiredConfig();
        $documentRef = $shipment->np_ref ?: $shipment->tracking_number;

        if (! $documentRef) {
            throw new RuntimeException('TTN number is missing.');
        }

        $payload = [
            'apiKey' => $config['api_key'],
            'modelName' => 'InternetDocument',
            'calledMethod' => 'delete',
            'methodProperties' => [
                'DocumentRefs' => [$documentRef],
            ],
        ];

        $response = Http::timeout((int) config('services.nova_poshta.timeout', 15))
            ->connectTimeout((int) config('services.nova_poshta.connect_timeout', 30))
            ->acceptJson()
            ->post((string) config('services.nova_poshta.api_url'), $payload);

        if (! $response->ok()) {
            throw new RuntimeException('Nova Poshta delete API HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Nova Poshta delete API returned an invalid response.');
        }

        if (($body['success'] ?? false) !== true) {
            $trackingResponse = $this->trackingStatus($shipment);

            if ($this->trackingStatusIsDeleted($trackingResponse)) {
                return [
                    'success' => true,
                    'delete_response' => $body,
                    'tracking_response' => $trackingResponse,
                ];
            }

            throw new RuntimeException($this->apiMessage($body));
        }

        return $body;
    }

    public function trackingStatusDocument(CustomerOrderShipment $shipment): array
    {
        $body = $this->trackingStatus($shipment, true);
        $document = $body['data'][0] ?? null;

        if (! is_array($document)) {
            throw new RuntimeException('Nova Poshta tracking API did not return shipment status.');
        }

        return [
            'status_code' => (string) ($document['StatusCode'] ?? ''),
            'status' => (string) ($document['Status'] ?? ''),
            'raw_response' => $body,
            'document' => $document,
        ];
    }

    private function methodProperties(CustomerOrder $order, CustomerOrderShipment $shipment, array $config): array
    {
        $recipientName = $shipment->recipient_name ?: $order->client_name;
        $recipientPhone = $this->normalizePhone($shipment->recipient_phone ?: $order->client_phone);
        $declaredCost = $this->declaredCost($order, $shipment);

        if (! $recipientName || ! $recipientPhone || ! $shipment->recipient_city_name || ! $shipment->recipient_warehouse_ref) {
            throw new RuntimeException('Recipient city, warehouse, name, and phone are required for Nova Poshta TTN.');
        }

        $properties = [
            'NewAddress' => '1',
            'PayerType' => $shipment->payer_type ?: $config['payer_type'],
            'PaymentMethod' => $shipment->payment_method ?: $config['payment_method'],
            'DateTime' => now()->format('d.m.Y'),
            'CargoType' => 'Parcel',
            'Weight' => max(0.1, (float) $shipment->weight),
            'ServiceType' => 'WarehouseWarehouse',
            'SeatsAmount' => max(1, (int) $shipment->seats_amount),
            'Description' => $shipment->cargo_description ?: $config['cargo_description'],
            'Cost' => max(1, (int) round($declaredCost)),
            'CitySender' => $config['sender_city_ref'],
            'Sender' => $config['sender_ref'],
            'SenderAddress' => $config['sender_address_ref'],
            'ContactSender' => $config['sender_contact_ref'],
            'SendersPhone' => $this->normalizePhone($config['sender_phone']),
            'RecipientCityName' => $shipment->recipient_city_name,
            'RecipientName' => $recipientName,
            'RecipientType' => 'PrivatePerson',
            'RecipientsPhone' => $recipientPhone,
        ];

        $properties['RecipientAddress'] = $shipment->recipient_warehouse_ref;

        if ($shipment->length_cm && $shipment->width_cm && $shipment->height_cm) {
            $properties['OptionsSeat'] = [[
                'weight' => (string) max(0.1, (float) $shipment->weight),
                'volumetricLength' => (string) $shipment->length_cm,
                'volumetricWidth' => (string) $shipment->width_cm,
                'volumetricHeight' => (string) $shipment->height_cm,
            ]];
        }

        $afterpaymentAmount = round((float) $shipment->afterpayment_amount, 2);

        if ($afterpaymentAmount > 0) {
            $afterpayment = (string) (int) ceil($afterpaymentAmount);

            $properties['AfterpaymentOnGoodsCost'] = $afterpayment;
        }

        return $properties;
    }

    public function declaredCost(CustomerOrder $order, CustomerOrderShipment $shipment): float
    {
        $orderRemainder = round((float) $order->total_amount - (float) $order->paid_amount_uah, 2);

        if ($orderRemainder > 0) {
            return $orderRemainder;
        }

        return max(1, (float) $shipment->declared_cost);
    }

    private function requiredConfig(): array
    {
        $config = [
            'api_key' => (string) config('services.nova_poshta.api_key'),
            'sender_city_ref' => (string) config('services.nova_poshta.sender_city_ref'),
            'sender_ref' => (string) config('services.nova_poshta.sender_ref'),
            'sender_address_ref' => (string) config('services.nova_poshta.sender_address_ref'),
            'sender_contact_ref' => (string) config('services.nova_poshta.sender_contact_ref'),
            'sender_phone' => (string) config('services.nova_poshta.sender_phone'),
            'payer_type' => (string) config('services.nova_poshta.payer_type', 'Recipient'),
            'payment_method' => (string) config('services.nova_poshta.payment_method', 'Cash'),
            'cargo_description' => (string) config('services.nova_poshta.cargo_description', 'Auto parts'),
        ];

        foreach (['api_key', 'sender_city_ref', 'sender_ref', 'sender_address_ref', 'sender_contact_ref', 'sender_phone'] as $key) {
            if ($config[$key] === '') {
                throw new RuntimeException('Nova Poshta is not configured: '.$key.' is missing.');
            }
        }

        return $config;
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if (str_starts_with($digits, '380')) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '38'.$digits;
        }

        return $digits;
    }

    private function trackingStatus(CustomerOrderShipment $shipment, bool $throwOnError = false): array
    {
        if (! $shipment->tracking_number) {
            if ($throwOnError) {
                throw new RuntimeException('TTN number is missing.');
            }

            return [];
        }

        $document = [
            'DocumentNumber' => $shipment->tracking_number,
        ];
        $phone = $this->normalizePhone($shipment->recipient_phone);

        if ($phone !== '') {
            $document['Phone'] = $phone;
        }

        $response = Http::timeout((int) config('services.nova_poshta.timeout', 15))
            ->connectTimeout((int) config('services.nova_poshta.connect_timeout', 30))
            ->acceptJson()
            ->post((string) config('services.nova_poshta.api_url'), [
                'apiKey' => (string) config('services.nova_poshta.api_key'),
                'modelName' => 'TrackingDocument',
                'calledMethod' => 'getStatusDocuments',
                'methodProperties' => [
                    'Documents' => [$document],
                ],
            ]);

        if (! $response->ok()) {
            if ($throwOnError) {
                throw new RuntimeException('Nova Poshta tracking API HTTP '.$response->status().'.');
            }

            return [];
        }

        $body = $response->json();

        if (! is_array($body)) {
            if ($throwOnError) {
                throw new RuntimeException('Nova Poshta tracking API returned an invalid response.');
            }

            return [];
        }

        if ($throwOnError && ($body['success'] ?? false) !== true) {
            throw new RuntimeException($this->apiMessage($body));
        }

        return is_array($body) ? $body : [];
    }

    private function trackingStatusIsDeleted(array $body): bool
    {
        if (($body['success'] ?? false) !== true) {
            return false;
        }

        $document = $body['data'][0] ?? null;

        if (! is_array($document)) {
            return false;
        }

        $statusCode = (string) ($document['StatusCode'] ?? '');
        $status = mb_strtolower((string) ($document['Status'] ?? ''));

        return $statusCode === '2'
            || str_contains($status, "\u{0432}\u{0438}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}")
            || str_contains($status, "\u{0443}\u{0434}\u{0430}\u{043B}\u{0435}\u{043D}");
    }

    private function apiMessage(array $body): string
    {
        $messages = collect($body['errors'] ?? [])
            ->merge($body['warnings'] ?? [])
            ->merge($body['info'] ?? [])
            ->filter()
            ->map(fn ($message): string => (string) $message)
            ->join('; ');

        return $messages !== '' ? $messages : 'Nova Poshta API rejected the TTN request.';
    }
}
