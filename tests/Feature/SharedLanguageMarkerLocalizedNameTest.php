<?php

namespace Tests\Feature;

use App\Models\TranslationLanguageMarker;
use App\Services\TeslaPartsUkraineCatalogImporter;
use App\Services\TskCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedLanguageMarkerLocalizedNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_language_marker_fills_ru_and_ua_when_all_cyrillic_words_match(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
            'ru_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
        ]);

        $name = "\u{0411}\u{043E}\u{043B}\u{0442} M6-1.0X23[8.8]";

        $this->assertSame([
            'name_ru' => $name,
            'name_ua' => $name,
        ], app(TeslaPartsUkraineCatalogImporter::class)->localizedNamePayload($name));

        $this->assertSame([
            'name_ru' => $name,
            'name_ua' => $name,
        ], app(TskCatalogImporter::class)->localizedNamePayload($name));
    }

    public function test_shared_language_marker_rule_ignores_names_without_cyrillic_words(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
            'ru_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
        ]);

        $this->assertSame([], app(TeslaPartsUkraineCatalogImporter::class)->localizedNamePayload('BOLT M6-1.0X23'));
        $this->assertSame([], app(TskCatalogImporter::class)->localizedNamePayload('BOLT M6-1.0X23'));
    }

    public function test_shared_language_marker_rule_requires_every_cyrillic_word_to_match(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
            'ru_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
        ]);

        $name = "\u{0411}\u{043E}\u{043B}\u{0442} \u{043A}\u{0440}\u{0435}\u{043F}\u{043B}\u{0435}\u{043D}\u{0438}\u{044F}";

        $this->assertSame([], app(TeslaPartsUkraineCatalogImporter::class)->localizedNamePayload($name));
        $this->assertSame([], app(TskCatalogImporter::class)->localizedNamePayload($name));
    }

    public function test_language_markers_match_whole_words_for_tesla_parts_ukraine(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0437}",
            'ru_marker' => "\u{0441}",
        ]);
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{043B}\u{0456}\u{0432}\u{0435}",
            'ru_marker' => "\u{043B}\u{0435}\u{0432}\u{043E}\u{0435}",
        ]);

        $name = "\u{041A}\u{0440}\u{0435}\u{043F}\u{043B}\u{0435}\u{043D}\u{0438}\u{0435} \u{043E}\u{0431}\u{0448}\u{0438}\u{0432}\u{043A}\u{0438} \u{0430}\u{0440}\u{043A}\u{0438} \u{0437}\u{0430}\u{0434}\u{043D}\u{0435}\u{0435} \u{043B}\u{0435}\u{0432}\u{043E}\u{0435} 1516195-00-B";

        $this->assertSame(['name_ru' => $name], app(TeslaPartsUkraineCatalogImporter::class)->localizedNamePayload($name));
        $this->assertSame(['name_ru' => $name], app(TskCatalogImporter::class)->localizedNamePayload($name));
    }

    public function test_language_markers_ignore_short_words_and_words_with_digits(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0437}",
            'ru_marker' => "\u{0441}",
        ]);
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "1\u{043C}\u{043C}",
            'ru_marker' => "1\u{043C}\u{043C}",
        ]);

        foreach ([TeslaPartsUkraineCatalogImporter::class, TskCatalogImporter::class] as $importer) {
            $this->assertSame([], app($importer)->localizedNamePayload("\u{0437}"));
            $this->assertSame([], app($importer)->localizedNamePayload("1\u{043C}\u{043C}"));
        }
    }

    public function test_shared_language_marker_rule_ignores_short_and_digit_words_in_name(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
            'ru_marker' => "\u{0411}\u{043E}\u{043B}\u{0442}",
        ]);
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "1\u{043C}\u{043C}",
            'ru_marker' => "1\u{043C}\u{043C}",
        ]);

        $name = "\u{0411}\u{043E}\u{043B}\u{0442} 1\u{043C}\u{043C}";

        $this->assertSame(['name_ru' => $name, 'name_ua' => $name], app(TeslaPartsUkraineCatalogImporter::class)->localizedNamePayload($name));
        $this->assertSame(['name_ru' => $name, 'name_ua' => $name], app(TskCatalogImporter::class)->localizedNamePayload($name));
    }

    public function test_language_marker_majority_wins_when_markers_conflict(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{043F}\u{0440}\u{0430}\u{0432}\u{0430}",
            'ru_marker' => "\u{043F}\u{0440}\u{0430}\u{0432}\u{0430}\u{044F}",
        ]);
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{043F}\u{0430}\u{043D}\u{0435}\u{043B}\u{0456}",
            'ru_marker' => "\u{043F}\u{0430}\u{043D}\u{0435}\u{043B}\u{0438}",
        ]);
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0434}\u{0432}\u{0456}\u{0440}\u{043D}\u{0438}\u{043A}\u{0456}\u{0432}",
            'ru_marker' => "\u{0434}\u{0432}\u{043E}\u{0440}\u{043D}\u{0438}\u{043A}\u{043E}\u{0432}",
        ]);

        $name = "\u{041D}\u{0430}\u{043A}\u{043B}\u{0430}\u{0434}\u{043A}\u{0430} \u{043F}\u{0430}\u{043D}\u{0435}\u{043B}\u{0438} \u{0434}\u{0432}\u{043E}\u{0440}\u{043D}\u{0438}\u{043A}\u{043E}\u{0432} \u{043F}\u{0440}\u{0430}\u{0432}\u{0430} \u{0440}\u{0435}\u{0437}\u{0438}\u{043D}\u{043E}\u{0432}\u{0430}\u{044F}";

        $this->assertSame(['name_ru' => $name], app(TeslaPartsUkraineCatalogImporter::class)->localizedNamePayload($name));
        $this->assertSame(['name_ru' => $name], app(TskCatalogImporter::class)->localizedNamePayload($name));
        $this->assertSame('ua', app(TskCatalogImporter::class)->localizedNameDetection($name)['conflict']['locale']);
        $this->assertSame(1, app(TskCatalogImporter::class)->localizedNameDetection($name)['conflict']['count']);
    }

    public function test_parenthetical_marker_participates_in_language_detection(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044C}\u{043E}\u{0433}\u{043E}",
            'ru_marker' => "\u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0433}\u{043E}",
        ]);

        $name = "\u{0417}\u{0430}\u{0433}\u{043B}\u{0443}\u{0448}\u{043A}\u{0430} \u{0431}\u{0443}\u{043A}\u{0441}\u{0438}\u{0440}\u{043E}\u{0432}\u{043E}\u{0447}\u{043D}\u{043E}\u{0433}\u{043E} \u{043A}\u{0440}\u{044E}\u{043A}\u{0430} (\u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044C}\u{043E}\u{0433}\u{043E} \u{0431}\u{0430}\u{043C}\u{043F}\u{0435}\u{0440}\u{0430})";

        $this->assertSame(['name_ua' => $name], app(TeslaPartsUkraineCatalogImporter::class)->localizedNamePayload($name));
        $this->assertSame(['name_ua' => $name], app(TskCatalogImporter::class)->localizedNamePayload($name));
    }

    public function test_parenthetical_opposite_marker_is_still_reported_as_conflict(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0433}\u{0430}\u{043A}\u{0430}",
            'ru_marker' => "\u{043A}\u{0440}\u{044E}\u{043A}\u{0430}",
        ]);
        TranslationLanguageMarker::query()->create([
            'ua_marker' => "\u{0437}\u{0430}\u{0433}\u{043B}\u{0443}\u{0448}\u{043A}\u{0430}",
            'ru_marker' => "\u{0437}\u{0430}\u{0433}\u{043B}\u{0443}\u{0448}\u{043A}\u{0430}",
        ]);

        $name = "\u{0417}\u{0430}\u{0433}\u{043B}\u{0443}\u{0448}\u{043A}\u{0430} (\u{043A}\u{0440}\u{044E}\u{043A}\u{0430})";
        $detection = app(TskCatalogImporter::class)->localizedNameDetection($name);

        $this->assertSame(['name_ru' => $name, 'name_ua' => $name], app(TskCatalogImporter::class)->localizedNamePayload($name));
        $this->assertSame('language_marker', $detection['source']);
        $this->assertSame('ru', $detection['conflict']['locale']);
        $this->assertSame(['крюка'], $detection['conflict']['markers']);
    }
}
