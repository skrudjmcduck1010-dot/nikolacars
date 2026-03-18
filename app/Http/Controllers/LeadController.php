<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LeadController extends Controller
{
    private function tgEscape(string $text): string
    {
        // Экранируем символы, которые ломают Telegram Markdown
        // (т.к. ты используешь parse_mode=Markdown)
        $replacements = [
            '_' => '\_',
            '*' => '\*',
            '[' => '\[',
            ']' => '\]',
            '(' => '\(',
            ')' => '\)',
            '~' => '\~',
            '`' => '\`',
            '>' => '\>',
            '#' => '\#',
            '+' => '\+',
            '-' => '\-',
            '=' => '\=',
            '|' => '\|',
            '{' => '\{',
            '}' => '\}',
            '.' => '\.',
            '!' => '\!',
        ];
        return strtr($text, $replacements);
    }

    public function callback(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'phone'        => ['required', 'string', 'max:40'],
            'details'      => ['nullable', 'string', 'max:2000'],
            'page'         => ['nullable', 'string', 'max:255'],

            // ✅ новые поля
            'source_page'  => ['nullable', 'string', 'max:50'],   // например: home
            'slide_title'  => ['nullable', 'string', 'max:255'],  // например: "Пригін Tesla із США під ключ"
            'slide_service'=> ['nullable', 'string', 'max:50'],   // например: import/service/fw/cert (если добавишь)
        ]);

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (!$token || !$chatId) {
            return response()->json(['ok' => false, 'message' => 'Telegram is not configured'], 500);
        }

        $name  = $this->tgEscape($data['name']);
        $phone = $this->tgEscape($data['phone']);

        $source = $data['source_page'] ?? null;
        $slideTitle = $data['slide_title'] ?? null;
        $slideService = $data['slide_service'] ?? null;

        // Если пришло source_page=home — пишем "Главная"
        $sourceLabel = ($source === 'home') ? 'Головна' : ($source ? $this->tgEscape($source) : null);

        $text =
            "📩 *Заявка з сайту*\n"
            ."👤 Імʼя: *{$name}*\n"
            ."📞 Телефон: *{$phone}*\n"
            .($sourceLabel ? "📍 Джерело: *{$sourceLabel}*\n" : "")
            .(!empty($slideTitle) ? "🎞 Слайд: *".$this->tgEscape($slideTitle)."*\n" : "")
            .(!empty($slideService) ? "🧩 Послуга: *".$this->tgEscape($slideService)."*\n" : "")
            .(!empty($data['page']) ? "🌐 Сторінка: ".$this->tgEscape($data['page'])."\n" : "")
            .(!empty($data['details']) ? "\n📝 Деталі:\n".$this->tgEscape($data['details']) : "");

        $resp = Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]);

        if (!$resp->ok()) {
            return response()->json(['ok' => false, 'message' => 'Telegram send failed', 'tg' => $resp->json()], 500);
        }

        return response()->json(['ok' => true]);
    }
}
