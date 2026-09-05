<?php

namespace App\Http\Controllers;

use App\Services\SkladStorefrontClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^\+380(39|50|63|66|67|68|73|77|91|92|93|94|95|96|97|98|99)\d{7}$/'],
            'details' => ['nullable', 'string', 'max:2000'],
            'page' => ['nullable', 'string', 'max:255'],
            'source_page' => ['nullable', 'string', 'max:50'],
            'slide_title' => ['nullable', 'string', 'max:255'],
            'slide_service' => ['nullable', 'string', 'max:50'],
            'service_title' => ['nullable', 'string', 'max:255'],
        ]);

        $externalId = (string) Str::uuid();
        $serviceTitle = $data['service_title'] ?? ($data['slide_service'] ?? null);

        try {
            $response = $sklad->createStoWebsiteRequest([
                'external_id' => $externalId,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'details' => $data['details'] ?? null,
                'page' => $data['page'] ?? null,
                'source_page' => $data['source_page'] ?? null,
                'slide_title' => $data['slide_title'] ?? null,
                'service_title' => $serviceTitle,
            ]);

            if (! $response->successful()) {
                Log::warning('STO website request delivery to sklad failed.', [
                    'external_id' => $externalId,
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('STO website request delivery to sklad failed.', [
                'external_id' => $externalId,
                'message' => $exception->getMessage(),
            ]);
        }

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return response()->json(['ok' => false, 'message' => 'Telegram is not configured'], 500);
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

        if (! $response->ok()) {
            return response()->json(['ok' => false, 'message' => 'Telegram send failed', 'tg' => $response->json()], 500);
        }

        return response()->json(['ok' => true, 'request_id' => $externalId]);
    }
}
