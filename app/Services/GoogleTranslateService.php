<?php

namespace App\Services;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleTranslateService
{
    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): ?string
    {
        $text = trim($text);
        $targetLanguage = $this->languageCode($targetLanguage);
        $sourceLanguage = $sourceLanguage !== null ? $this->languageCode($sourceLanguage) : null;
        $key = trim((string) config('services.google_translate.key'));

        if ($text === '' || $targetLanguage === '' || $key === '') {
            return null;
        }

        if (App::environment('testing') && ! (bool) config('services.google_translate.allow_in_testing')) {
            return null;
        }

        $payload = [
            'q' => $text,
            'target' => $targetLanguage,
            'format' => 'text',
            'key' => $key,
        ];

        if ($sourceLanguage !== null && $sourceLanguage !== '') {
            $payload['source'] = $sourceLanguage;
        }

        $response = Http::timeout((int) config('services.google_translate.timeout', 5))
            ->asForm()
            ->post('https://translation.googleapis.com/language/translate/v2', $payload);

        if (! $response->successful()) {
            return null;
        }

        $translated = data_get($response->json(), 'data.translations.0.translatedText');

        if (! is_string($translated)) {
            return null;
        }

        $translated = html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $translated = trim(preg_replace('/\s+/u', ' ', $translated) ?? $translated);

        return $translated !== '' ? Str::limit($translated, 255, '') : null;
    }

    protected function languageCode(string $language): string
    {
        return match (strtolower(trim($language))) {
            'ua', 'ukr', 'ukrainian' => 'uk',
            'rus', 'russian' => 'ru',
            'eng', 'english' => 'en',
            default => strtolower(trim($language)),
        };
    }
}
