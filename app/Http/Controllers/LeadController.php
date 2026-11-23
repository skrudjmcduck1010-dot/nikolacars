<?php

namespace App\Http\Controllers;

use App\Services\SkladStorefrontClient;
use App\Services\StoLeadSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeadController extends Controller
{
    private function tgEscape(string $text): string
    {
        $replacements = [
            '_' => '\\_',
            '*' => '\\*',
            '[' => '\\[',
            ']' => '\\]',
            '(' => '\\(',
            ')' => '\\)',
            '~' => '\\~',
            '`' => '\\`',
            '>' => '\\>',
            '#' => '\\#',
            '+' => '\\+',
            '-' => '\\-',
            '=' => '\\=',
            '|' => '\\|',
            '{' => '\\{',
            '}' => '\\}',
            '.' => '\\.',
            '!' => '\\!',
        ];

        return strtr($text, $replacements);
    }

    public function callback(Request $request, SkladStorefrontClient $sklad)
    {
        $data = $request->validate([
            'request_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^\+380(39|50|63|66|67|68|73|77|91|92|93|94|95|96|97|98|99)\d{7}$/'],
            'details' => ['nullable', 'string', 'max:2000'],
            'page' => ['nullable', 'string', 'max:255'],
            'source_page' => ['nullable', 'string', 'max:50'],
            'slide_title' => ['nullable', 'string', 'max:255'],
            'slide_service' => ['nullable', 'string', 'max:50'],
            'service_title' => ['nullable', 'string', 'max:255'],
        ]);

        // Old cached forms have no UUID: the same session and payload retain an identity.
        // Fresh forms supply a random ID before POST and keep it through an unknown outcome.
        $identity = $data;
        unset($identity['request_id']);
        $legacy = hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR)
            .'|'.($request->hasSession() ? $request->session()->getId() : (string) $request->input('_token')),
            (string) config('app.key'));
        $externalId = strtolower($data['request_id'] ?? (substr($legacy, 0, 8).'-'.substr($legacy, 8, 4)
            .'-4'.substr($legacy, 13, 3).'-a'.substr($legacy, 17, 3).'-'.substr($legacy, 20, 12)));
        $serviceTitle = $data['service_title'] ?? ($data['slide_service'] ?? null);
        $kyivTime = now('Europe/Kyiv');
        // Website contact hours: Monday-Friday, 10:00-18:00 Kyiv time.
        // These fields only describe the success notice; delivery remains immediate.
        $afterHours = $kyivTime->isWeekend() || $kyivTime->hour < 10 || $kyivTime->hour >= 18;
        $nextWorkingAt = null;
        if ($afterHours) {
            $nextWorkingAt = $kyivTime->copy()->setTime(10, 0);
            if ($kyivTime->hour >= 18) {
                $nextWorkingAt->addDay();
            }
            while ($nextWorkingAt->isWeekend()) {
                $nextWorkingAt->addDay();
            }
        }

        $payload = [
            'external_id' => $externalId,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'details' => $data['details'] ?? null,
            'page' => $data['page'] ?? null,
            'source_page' => $data['source_page'] ?? null,
            'slide_title' => $data['slide_title'] ?? null,
            'service_title' => $serviceTitle,
        ];
        try {
            $result = app(StoLeadSubmission::class)->submit($externalId, $payload,
                function () use ($sklad, $payload): bool {
                    $response = $sklad->createStoWebsiteRequest($payload);

                    return $response->successful() && is_int($response->json('id'))
                        && $response->json('id') > 0 && is_bool($response->json('created'));
                },
                fn (): string => $this->notifyTelegram($data, $serviceTitle),
            );
        } catch (Throwable $exception) {
            Log::warning('STO intake requires reconciliation.', [
                'request_id' => $externalId, 'exception' => $exception::class,
            ]);

            return response()->json(['ok' => false, 'request_id' => $externalId], 503);
        }
        $status = $result['http_status'];
        unset($result['http_status']);
        if (! $result['ok'] || ! ($result['crm_saved'] ?? false)) {
            Log::warning('STO intake not confirmed by CRM.', [
                'request_id' => $externalId, 'status' => $result['submission_status'],
            ]);
        }

        return response()->json($result + [
            'request_id' => $externalId,
            'business_unit' => $this->businessUnit($data['page'] ?? ''),
            'after_hours' => $afterHours,
            'next_working_at' => $nextWorkingAt?->toIso8601String(),
        ], $status);
    }

    private function businessUnit(string $page): string
    {
        $url = parse_url($page);
        if (! is_array($url) || ! in_array(strtolower($url['host'] ?? ''), ['nikolacars.kiev.ua', 'www.nikolacars.kiev.ua'], true)) {
            return 'general';
        }
        $path = preg_replace('~^/(?:ru|ua)/~', '/', $url['path'] ?? '/');
        if (rtrim($path, '/') === '/services/prigon-tesla-usa') return 'import';
        if (preg_match('~^/services/([a-z0-9-]+)/?$~', $path, $match)
            && array_key_exists($match[1], config('service_content', []))) return 'sto';

        return 'general';
    }

    private function notifyTelegram(array $data, ?string $serviceTitle): string
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return 'retryable'; // No request made; later configuration recovery is safe.
        }

        $name = $this->tgEscape($data['name']);
        $phone = $this->tgEscape($data['phone']);
        $source = $data['source_page'] ?? null;
        $slideTitle = $data['slide_title'] ?? null;
        $sourceLabel = ($source === 'home') ? 'Головна' : ($source ? $this->tgEscape($source) : null);

        $text =
            "📩 *Заявка з сайту*\n"
            ."👤 Імʼя: *{$name}*\n"
            ."📞 Телефон: *{$phone}*\n"
            .($sourceLabel ? "📍 Джерело: *{$sourceLabel}*\n" : '')
            .(! empty($slideTitle) ? '🎞 Слайд: *'.$this->tgEscape($slideTitle)."*\n" : '')
            .(! empty($serviceTitle) ? '🧩 Послуга: *'.$this->tgEscape($serviceTitle)."*\n" : '')
            .(! empty($data['page']) ? '🌐 Сторінка: '.$this->tgEscape($data['page'])."\n" : '')
            .(! empty($data['details']) ? "\n📝 Деталі:\n".$this->tgEscape($data['details']) : '');

        $response = Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'MarkdownV2',
            'disable_web_page_preview' => true,
        ]);

        if ($response->successful() && $response->json('ok') === true) return 'confirmed';
        // Telegram's explicit client error proves rejection. Timeouts/malformed/5xx remain uncertain.
        if ($response->status() < 500 && $response->json('ok') === false) return 'retryable';

        return 'unconfirmed';
    }
}
