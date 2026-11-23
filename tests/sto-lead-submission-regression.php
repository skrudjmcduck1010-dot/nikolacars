<?php

$root = dirname(__DIR__);
$runtime = realpath((string) getenv('NC_REGRESSION_RUNTIME'));
if (getenv('APP_ENV') !== 'testing' || ! $runtime) {
    throw new RuntimeException('Run through isolated regression runner.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'services.sklad_storefront.base_url' => 'http://127.0.0.1:9/api/storefront/v1',
    'services.sklad_storefront.token' => 'synthetic',
    'services.telegram.bot_token' => 'synthetic-token',
    'services.telegram.chat_id' => 'synthetic-chat',
]);
Illuminate\Support\Facades\Http::preventStrayRequests();
function leadAssert(bool $value, string $message): void
{
    if (! $value) throw new RuntimeException($message);
    echo "PASS lead: $message\n";
}
$crmMode = 'success';
$tgMode = 'success';
$crmCalls = $tgCalls = 0;
$seenIds = [];
Illuminate\Support\Facades\Http::fake(function ($request) use (&$crmMode, &$tgMode, &$crmCalls, &$tgCalls, &$seenIds) {
    if ($request->url() === 'http://127.0.0.1:9/api/storefront/v1/sto-website-requests') {
        $crmCalls++;
        $seenIds[] = $request['external_id'];
        if ($crmMode === 'throw') throw new Illuminate\Http\Client\ConnectionException('Synthetic lost response');
        return Illuminate\Support\Facades\Http::response($crmMode === 'success' ? ['id' => 123, 'created' => false] : ['error' => 'synthetic'], $crmMode === 'success' ? 200 : 503);
    }
    if ($request->url() === 'https://api.telegram.org/botsynthetic-token/sendMessage') {
        $tgCalls++;
        if ($tgMode === 'throw') throw new Illuminate\Http\Client\ConnectionException('Synthetic uncertain notification');
        return Illuminate\Support\Facades\Http::response(['ok' => $tgMode === 'success'], $tgMode === 'success' ? 200 : ($tgMode === 'reject' ? 400 : 500));
    }
    throw new RuntimeException('Unexpected synthetic request');
});
$session = new Illuminate\Session\Store('synthetic', new Illuminate\Session\ArraySessionHandler(120));
$session->start();
$payload = ['name' => 'Synthetic request', 'phone' => '+380500000000', 'details' => 'Synthetic fixture', 'page' => 'https://nikolacars.kiev.ua/services/tesla-service/'];
$invoke = function (array $data) use ($app, $session): array {
    $request = Illuminate\Http\Request::create('/lead/callback', 'POST', $data);
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    $response = app(App\Http\Controllers\LeadController::class)->callback($request, app(App\Services\SkladStorefrontClient::class));
    return [$response->getStatusCode(), $response->getData(true)];
};
$id = fn () => (string) Illuminate\Support\Str::uuid();
$first = $payload + ['request_id' => $id()];
leadAssert(in_array('throttle:10,1', app('router')->getRoutes()->getByName('lead.callback')->gatherMiddleware(), true), 'callback intake uses bounded per-IP route throttle');
[$status, $result] = $invoke($first);
leadAssert($status === 200 && $result['crm_saved'] && $result['request_id'] === $first['request_id'], 'CRM accepted replay contract returns same request ID');
leadAssert($result['business_unit'] === 'sto', 'known service path classified by server');
$counts = [$crmCalls, $tgCalls];
$invoke($first);
leadAssert([$crmCalls, $tgCalls] === $counts, 'successful replay performs no external calls');
[$status] = $invoke(array_replace($first, ['details' => 'Changed intent']));
leadAssert($status === 409 && [$crmCalls, $tgCalls] === $counts, 'same ID with different content conflicts before sending');
$tgMode = 'failure';
$tgUnknown = $payload + ['request_id' => $id()];
[$status, $result] = $invoke($tgUnknown);
leadAssert($status === 200 && $result['crm_saved'], 'CRM success survives Telegram failure');
$tgBefore = $tgCalls;
$invoke($tgUnknown);
leadAssert($tgCalls === $tgBefore, 'Telegram 5xx cannot be blindly retried');
$crmMode = 'failure'; $tgMode = 'success';
$fallback = $payload + ['request_id' => $id()];
[$status, $result] = $invoke($fallback);
leadAssert($status === 200 && $result['ok'] && ! $result['crm_saved'], 'Telegram fallback accepted without false CRM success');
$tgBefore = $tgCalls;
$crmMode = 'success';
[$status, $result] = $invoke($fallback);
leadAssert($result['crm_saved'] && $tgCalls === $tgBefore && end($seenIds) === $fallback['request_id'], 'fallback reconciliation reuses warehouse ID without duplicate notification');
$crmMode = 'throw'; $tgMode = 'throw';
$uncertain = $payload + ['request_id' => $id()];
[$status, $result] = $invoke($uncertain);
leadAssert($status === 503 && ! $result['ok'] && ! $result['crm_saved'], 'both uncertain outcomes never report success');
$tgBefore = $tgCalls;
$crmMode = 'success'; $tgMode = 'success';
[$status, $result] = $invoke($uncertain);
leadAssert($status === 200 && $result['crm_saved'] && $tgCalls === $tgBefore, 'unknown Telegram attempt is never resent on retry');
$crmMode = 'failure'; $tgMode = 'reject';
$recoverable = $payload + ['request_id' => $id()];
[$status] = $invoke($recoverable);
leadAssert($status === 503, 'explicit Telegram rejection and CRM failure remain unsuccessful');
$tgBefore = $tgCalls; $tgMode = 'success';
[$status, $result] = $invoke($recoverable);
leadAssert($status === 200 && ! $result['crm_saved'] && $tgCalls === $tgBefore + 1, 'explicitly rejected notification safely retries after recovery');
config(['services.telegram.bot_token' => '']);
$unconfigured = $payload + ['request_id' => $id()];
$tgBefore = $tgCalls;
[$status] = $invoke($unconfigured);
leadAssert($status === 503 && $tgCalls === $tgBefore, 'missing Telegram config does not create an outbound attempt');
config(['services.telegram.bot_token' => 'synthetic-token']);
[$status, $result] = $invoke($unconfigured);
leadAssert($status === 200 && ! $result['crm_saved'] && $tgCalls === $tgBefore + 1, 'missing configuration recovery permits fallback on explicit retry');
$crmMode = 'success';
foreach (['https://nikolacars.kiev.ua/services/prigon-tesla-usa/' => 'import', 'https://nikolacars.kiev.ua/ru/' => 'general', '' => 'general', 'https://example.test/services/tesla-service/' => 'general'] as $page => $expected) {
    [$status, $result] = $invoke(array_replace($payload, ['request_id' => $id(), 'page' => $page]));
    leadAssert($result['business_unit'] === $expected, 'ambiguous/import/foreign sources never become STO: '.$expected);
}
[$status, $legacy] = $invoke($payload);
$counts = [$crmCalls, $tgCalls];
[$status, $legacyReplay] = $invoke($payload);
leadAssert($status === 200 && $legacy['request_id'] === $legacyReplay['request_id'] && [$crmCalls, $tgCalls] === $counts, 'old form without request ID is session-idempotent');
$directory = storage_path('app/private/sto-lead-submissions');
$ledgerPath = fn (string $requestId) => $directory.'/'.sprintf('%02x', hexdec(substr(hash('sha256', $requestId), 0, 2)) % 64).'.ledger';
do { $corrupt = $payload + ['request_id' => $id()]; } while (is_file($ledgerPath($corrupt['request_id'])));
file_put_contents($ledgerPath($corrupt['request_id']), '');
$counts = [$crmCalls, $tgCalls];
[$status] = $invoke($corrupt);
leadAssert($status === 503 && [$crmCalls, $tgCalls] === $counts, 'empty/corrupt receipt blocks all sends, never recreated');
do { $nestedId = $id(); } while ($ledgerPath($nestedId) === $ledgerPath($corrupt['request_id']));
$nestedResult = null;
$service = app(App\Services\StoLeadSubmission::class);
$service->submit($nestedId, ['synthetic' => true], function () use ($service, $nestedId, &$nestedResult): bool {
    $nestedResult = $service->submit($nestedId, ['synthetic' => true], fn () => throw new RuntimeException('Concurrent CRM write'), fn () => throw new RuntimeException('Concurrent notification'));
    return true;
}, fn () => 'confirmed');
leadAssert($nestedResult['http_status'] === 409 && $nestedResult['submission_status'] === 'pending', 'concurrent retry is locked without a TTL or external calls');
$onDisk = file_get_contents($ledgerPath($first['request_id']));
leadAssert(! str_contains($onDisk, $payload['phone']) && ! str_contains($onDisk, $payload['name']), 'receipt stores encrypted states and keyed fingerprint, no plaintext customer data');
do { $stageFailure = $payload + ['request_id' => $id()]; } while (is_file($ledgerPath($stageFailure['request_id'])));
$blockedStage = $ledgerPath($stageFailure['request_id']).'.stage';
mkdir($blockedStage);
$counts = [$crmCalls, $tgCalls];
[$status] = $invoke($stageFailure);
leadAssert($status === 503 && [$crmCalls, $tgCalls] === $counts, 'stage storage failure blocks external calls before claim');
rmdir($blockedStage);
[$status] = $invoke($stageFailure);
leadAssert($status === 200, 'recovered stage storage permits explicit retry');
leadAssert(count(glob($directory.'/*')) <= 192 && glob($directory.'/*.stage') === [], 'ledger inode count is bounded and no stage files remain after normal completion');
