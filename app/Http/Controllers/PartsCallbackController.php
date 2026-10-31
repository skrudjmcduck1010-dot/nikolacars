<?php

namespace App\Http\Controllers;

use App\Services\SkladStorefrontClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartsCallbackController extends Controller
{
    public function __invoke(Request $request, SkladStorefrontClient $sklad)
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'phone' => ['required', 'regex:/^\+380(39|50|63|66|67|68|73|77|91|92|93|94|95|96|97|98|99)\d{7}$/'],
            'page' => ['required', 'url:http,https', 'max:2000'],
        ]);
        $page = parse_url($data['page']);
        $host = strtolower($page['host'] ?? '');
        if (! in_array($host, ['nikolacars.kiev.ua', 'www.nikolacars.kiev.ua', 'nikolacars.com.ua', 'www.nikolacars.com.ua'], true)
            || ! preg_match('~^/(?:ru/|ua/)?parts(?:/|$)~', $page['path'] ?? '')) {
            return response()->json(['ok' => false, 'message' => 'Invalid source page.'], 422);
        }
        $sourcePage = 'https://'.$host.($page['path'] ?? '/parts/');
        try {
            $response = $sklad->createPartsCallback([
                'external_id' => $data['request_id'], 'phone' => $data['phone'], 'page' => $sourcePage,
            ]);
            if (! $response->successful() || $response->json('ok') !== true) {
                Log::warning('Parts callback intake not confirmed.', ['request_id' => $data['request_id'], 'status' => $response->status()]);

                return response()->json(['ok' => false, 'request_id' => $data['request_id']], $response->status() === 422 ? 422 : 503);
            }
        } catch (Throwable $exception) {
            Log::warning('Parts callback intake outcome requires review.', ['request_id' => $data['request_id'], 'exception' => $exception::class]);

            return response()->json(['ok' => false, 'request_id' => $data['request_id']], 503);
        }

        return response()->json(['ok' => true, 'request_id' => $data['request_id']]);
    }
}
