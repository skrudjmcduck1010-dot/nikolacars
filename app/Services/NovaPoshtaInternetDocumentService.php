<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderShipment;
use App\Support\CatalogTextEncoding;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NovaPoshtaInternetDocumentService
{
    protected const DEFAULT_CARGO_DESCRIPTION = "\u{0430}\u{0432}\u{0442}\u{043E}\u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{0438}\u{043D}\u{0438}";

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
        $body = $this->printDocument($shipment, 'pdf');

        if (! str_starts_with(ltrim($body), '%PDF-')) {
            throw new RuntimeException('Nova Poshta label API did not return a PDF document.');
        }

        return $body;
    }

    public function labelHtml(CustomerOrderShipment $shipment): string
    {
        $body = $this->printDocument($shipment, 'html');

        if (trim($body) === '') {
            throw new RuntimeException('Nova Poshta label API returned an empty HTML document.');
        }

        if ($this->looksLikeMissingPrintDocumentPage($body)) {
            throw new RuntimeException('Nova Poshta did not find a printable document for this TTN.');
        }

        return $body;
    }

    public function cabinetPrintUrl(CustomerOrderShipment $shipment, string $type = 'html'): string
    {
        $trackingNumber = trim((string) $shipment->tracking_number);

        if ($trackingNumber === '') {
            throw new RuntimeException('TTN number is missing.');
        }

        return rtrim((string) config('services.nova_poshta.print_url'), '/')
            .'/orders[]/'.rawurlencode($trackingNumber)
            .'/type/'.rawurlencode($type);
    }

    public function resolveDocumentRef(CustomerOrderShipment $shipment, bool $save = false): ?string
    {
        $documentRef = trim((string) $shipment->np_ref);

        if ($documentRef !== '') {
            return $documentRef;
        }

        $trackingNumber = trim((string) $shipment->tracking_number);

        if ($trackingNumber === '') {
            throw new RuntimeException('TTN number is missing.');
        }

        $documentRef = $this->findDocumentRefByTrackingNumber($trackingNumber);

        if ($documentRef !== null && $save && $shipment->exists) {
            $shipment->forceFill(['np_ref' => $documentRef])->save();
        }

        return $documentRef;
    }

    public function findDocumentRefByTrackingNumber(string $trackingNumber): ?string
    {
        $apiKey = (string) config('services.nova_poshta.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Nova Poshta API key is not configured.');
        }

        $documentRef = $this->lookupDocumentRefByTrackingNumber(trim($trackingNumber), $apiKey);

        return $documentRef !== '' ? $documentRef : null;
    }

    private function printDocument(CustomerOrderShipment $shipment, string $type): string
    {
        $apiKey = (string) config('services.nova_poshta.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Nova Poshta API key is not configured.');
        }

        $documentRef = $this->printDocumentRef($shipment);

        if ($documentRef === '') {
            throw new RuntimeException('Новая почта не нашла печатную форму для этой ТТН в текущем API-кабинете. Если ТТН создана вручную или в другом кабинете НП, ее нельзя распечатать через этот API-ключ.');
        }

        $url = rtrim((string) config('services.nova_poshta.print_url'), '/')
            .'/orders[]/'.rawurlencode($documentRef)
            .'/type/'.rawurlencode($type).'/apiKey/'.rawurlencode($apiKey)
            .'/copies/1';

        $response = Http::timeout((int) config('services.nova_poshta.timeout', 15))
            ->connectTimeout((int) config('services.nova_poshta.connect_timeout', 30))
            ->get($url);

        if (! $response->ok()) {
            throw new RuntimeException('Nova Poshta label API HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    private function printDocumentRef(CustomerOrderShipment $shipment): string
    {
        return $this->resolveDocumentRef($shipment, true) ?? '';
    }

    private function lookupDocumentRefByTrackingNumber(string $trackingNumber, string $apiKey): string
    {
        $response = Http::timeout((int) config('services.nova_poshta.timeout', 15))
            ->connectTimeout((int) config('services.nova_poshta.connect_timeout', 30))
            ->acceptJson()
            ->post((string) config('services.nova_poshta.api_url'), [
                'apiKey' => $apiKey,
                'modelName' => 'InternetDocument',
                'calledMethod' => 'getDocumentList',
                'methodProperties' => [
                    'IntDocNumber' => $trackingNumber,
                ],
            ]);

        if (! $response->ok()) {
            return '';
        }

        $body = $response->json();

        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            return '';
        }

        $document = collect($body['data'] ?? [])
            ->first(fn ($document): bool => is_array($document)
                && trim((string) ($document['Ref'] ?? '')) !== ''
                && (trim((string) ($document['IntDocNumber'] ?? '')) === '' || trim((string) $document['IntDocNumber']) === $trackingNumber));

        return is_array($document) ? trim((string) ($document['Ref'] ?? '')) : '';
    }

    private function looksLikeMissingPrintDocumentPage(string $body): bool
    {
        return str_contains($body, "\u{0414}\u{043E}\u{043A}\u{0443}\u{043C}\u{0435}\u{043D}\u{0442} \u{043D}\u{0435} \u{0437}\u{043D}\u{0430}\u{0439}\u{0434}\u{0435}\u{043D}\u{043E}")
            || str_contains($body, "\u{0414}\u{043E}\u{043A}\u{0443}\u{043C}\u{0435}\u{043D}\u{0442} \u{043D}\u{0435} \u{043D}\u{0430}\u{0439}\u{0434}\u{0435}\u{043D}")
            || str_contains($body, 'document not found');
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

        return $this->trackingStatusFromBody($body);
    }

    public function trackingStatusNumber(string $trackingNumber, ?string $phone = null): array
    {
        $body = $this->trackingStatusByNumber($trackingNumber, $phone, true);

        return $this->trackingStatusFromBody($body);
    }

    private function trackingStatusFromBody(array $body): array
    {
        $document = $body['data'][0] ?? null;

        if (! is_array($document)) {
            throw new RuntimeException('Nova Poshta tracking API did not return shipment status.');
        }

        return [
            'status_code' => (string) ($document['StatusCode'] ?? ''),
            'status' => $this->translatedTrackingStatus((string) ($document['Status'] ?? '')),
            'status_detail' => $this->translatedTrackingDetail($document),
            'afterpayment_amount' => $this->trackingMoneyValue($document['AfterpaymentOnGoodsCost'] ?? null),
            'return_tracking_number' => $this->returnTrackingNumber($document),
            'return_document_type' => $this->returnDocumentType($document),
            'return_created_at' => $this->returnCreatedAt($document),
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
            'Description' => self::DEFAULT_CARGO_DESCRIPTION,
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
            'cargo_description' => self::DEFAULT_CARGO_DESCRIPTION,
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

    private function cargoDescription(?string $description): string
    {
        return self::DEFAULT_CARGO_DESCRIPTION;
    }

    private function trackingStatus(CustomerOrderShipment $shipment, bool $throwOnError = false): array
    {
        if (! $shipment->tracking_number) {
            if ($throwOnError) {
                throw new RuntimeException('TTN number is missing.');
            }

            return [];
        }

        $phone = $this->normalizePhone($shipment->recipient_phone);

        return $this->trackingStatusByNumber($shipment->tracking_number, $phone, $throwOnError);
    }

    private function trackingStatusByNumber(string $trackingNumber, ?string $phone = null, bool $throwOnError = false): array
    {
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            if ($throwOnError) {
                throw new RuntimeException('TTN number is missing.');
            }

            return [];
        }

        $document = [
            'DocumentNumber' => $trackingNumber,
        ];
        $phone = $this->normalizePhone($phone);

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

    private function translatedTrackingStatus(string $status): string
    {
        $status = trim($status);

        return match ($status) {
            "\u{0412}\u{0456}\u{0434}\u{043C}\u{043E}\u{0432}\u{0430} \u{0432}\u{0456}\u{0434} \u{043E}\u{0442}\u{0440}\u{0438}\u{043C}\u{0430}\u{043D}\u{043D}\u{044F}",
            "\u{0412}\u{0456}\u{0434}\u{043C}\u{043E}\u{0432}\u{0430} \u{0432}\u{0456}\u{0434} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0438}" => "\u{041E}\u{0442}\u{043A}\u{0430}\u{0437} \u{043E}\u{0442} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0438}",
            default => $status,
        };
    }

    private function translatedTrackingDetail(array $document): ?string
    {
        $detail = $this->trackingTextValue($document['UndeliveryReasonsSubtypeDescription'] ?? null);

        if ($detail === '') {
            $detail = $this->trackingTextValue($document['UndeliveryReasons'] ?? null);
        }

        if ($detail === '') {
            return null;
        }

        return match ($detail) {
            "\u{0412}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043D}\u{0438}\u{043A} \u{0441}\u{043A}\u{0430}\u{0441}\u{0443}\u{0432}\u{0430}\u{0432} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0443} \u{0432}\u{0456}\u{0434}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{043D}\u{044F}" => "\u{041E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{0438}\u{0442}\u{0435}\u{043B}\u{044C} \u{043E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0438}\u{043B} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0443} \u{043E}\u{0442}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{0435}\u{043D}\u{0438}\u{044F}",
            "\u{0412}\u{0456}\u{0434}\u{043C}\u{043E}\u{0432}\u{0430} \u{0432}\u{0456}\u{0434} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0438}" => "\u{041E}\u{0442}\u{043A}\u{0430}\u{0437} \u{043E}\u{0442} \u{0434}\u{043E}\u{0441}\u{0442}\u{0430}\u{0432}\u{043A}\u{0438}",
            default => $detail,
        };
    }

    private function trackingTextValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(collect($value)
                ->flatten()
                ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
                ->map(fn (mixed $item): string => trim((string) $item))
                ->implode(', '));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function trackingMoneyValue(mixed $value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function returnTrackingNumber(array $document): ?string
    {
        $documentType = $this->returnDocumentType($document);

        if ($documentType !== 'CargoReturn') {
            return null;
        }

        $number = trim((string) ($document['LastCreatedOnTheBasisNumber'] ?? ''));

        return $number !== '' ? $number : null;
    }

    private function returnDocumentType(array $document): ?string
    {
        $type = trim((string) ($document['LastCreatedOnTheBasisDocumentType'] ?? ''));

        return $type !== '' ? $type : null;
    }

    private function returnCreatedAt(array $document): ?string
    {
        if ($this->returnTrackingNumber($document) === null) {
            return null;
        }

        $date = trim((string) ($document['LastCreatedOnTheBasisDateTime'] ?? ''));

        return $date !== '' ? $date : null;
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
