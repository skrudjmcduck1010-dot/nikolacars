<?php

namespace App\Services;

final class CatalogPath
{
    private const WIFI_UK_PARENT = 'informaciino-rozvazalna-sistema/antena-wi-fi';
    private const WIFI_RU_PARENT = 'informacionno-razvlekatelnaia-sistema/antenna-wi-fi';
    // Only reviewed equivalent landing pages. Taxonomy levels and IDs stay intact.
    // Full non-empty product sets were checked in both locales on 2026-10-27;
    // Model Y wires and front/rear axles were independently rechecked on 2026-11-02.
    // Global and Model S 2016-2021 wheel parent/leaf sets were rechecked on 2026-11-08.
    // Older aliases prefer the existing leaf; reviewed 2026-11-21 duplicates use
    // their existing parent. Complete non-empty ordered inventories match in UA/RU.
    // Battery roots stay distinct; only individually reviewed inner levels merge.
    private const PREFERRED_PATHS = [
        'model-y-01-2020-01-2025' => [
            'visokovoltna-sistema/visokovoltni-provoda' => 'visokovoltna-sistema/visokovoltni-provoda/visokovoltni-provoda',
            'vysokovoltnaia-sistema/vysokovoltnye-provoda' => 'vysokovoltnaia-sistema/vysokovoltnye-provoda/vysokovoltnye-provoda',
            'perednii-motor/peredni-pivosi' => 'perednii-motor/peredni-pivosi/peredni-pivosi',
            'perednii-motor/perednie-poluosi' => 'perednii-motor/perednie-poluosi/perednie-poluosi',
            'zadnii-motor/zadni-pivosi' => 'zadnii-motor/zadni-pivosi/zadni-pivosi',
            'zadnii-motor/zadnie-poluosi' => 'zadnii-motor/zadnie-poluosi/zadnie-poluosi',
            'vnutrisnje-ozdoblennia/ozdoblennia-stiiok-a-b-c-i-porogiv/ozdoblennia-stiiok-a-b-c-i-porogiv' => 'vnutrisnje-ozdoblennia/ozdoblennia-stiiok-a-b-c-i-porogiv',
            'vnutrenniaia-otdelka/obsivka-stoek-a-b-c-i-porogov/obsivka-stoek-a-b-c-i-porogov' => 'vnutrenniaia-otdelka/obsivka-stoek-a-b-c-i-porogov',
            'galma/elektromexanicnii-pidsiliuvac-galm/elektromexanicnii-pidsiliuvac-galm' => 'galma/elektromexanicnii-pidsiliuvac-galm',
            'tormoza/elektromexaniceskii-usilitel-tormozov/elektromexaniceskii-usilitel-tormozov' => 'tormoza/elektromexaniceskii-usilitel-tormozov',
            'diski-i-sini/sistema-kontroliu-tisku-v-sinax-tpms/sistema-kontroliu-tisku-v-sinax-tpms' => 'diski-i-sini/sistema-kontroliu-tisku-v-sinax-tpms',
            'diski-i-siny/sistema-kontrolia-davleniia-v-sinax-tpms/sistema-kontrolia-davleniia-v-sinax-tpms' => 'diski-i-siny/sistema-kontrolia-davleniia-v-sinax-tpms',
            'visokovoltna-batareia/visokovoltna-batareia' => 'visokovoltna-batareia',
            'vysokovoltnaia-batareia/vysokovoltnaia-batareia' => 'vysokovoltnaia-batareia',
            'visokovoltna-batareia/visokovoltna-batareia/visokovoltna-batareia' => 'visokovoltna-batareia',
            'vysokovoltnaia-batareia/vysokovoltnaia-batareia/vysokovoltnaia-batareia' => 'vysokovoltnaia-batareia',
        ],
        'model-3-06-2017-12-2023' => [
            'galma/pedal-galma' => 'galma/pedal-galma/pedal-galma',
            'tormoza/pedal-tormoza' => 'tormoza/pedal-tormoza/pedal-tormoza',
            'perednii-motor/peredni-pivosi/peredni-pivosi' => 'perednii-motor/peredni-pivosi',
            'perednii-motor/perednie-poluosi/perednie-poluosi' => 'perednii-motor/perednie-poluosi',
            'elektrika/frontalna-kamera/frontalna-kamera' => 'elektrika/frontalna-kamera',
            'elektrika/frontalnaia-kamera/frontalnaia-kamera' => 'elektrika/frontalnaia-kamera',
            'vnutrisnje-ozdoblennia/ozdoblennia-stiiok-a-b-c-i-porogiv/ozdoblennia-stiiok-a-b-c-i-porogiv' => 'vnutrisnje-ozdoblennia/ozdoblennia-stiiok-a-b-c-i-porogiv',
            'vnutrenniaia-otdelka/obsivka-stoek-a-b-c-i-porogov/obsivka-stoek-a-b-c-i-porogov' => 'vnutrenniaia-otdelka/obsivka-stoek-a-b-c-i-porogov',
            'zovnisnia-furnitura/pidkrilki/pidkrilki' => 'zovnisnia-furnitura/pidkrilki',
            'naruznaia-furnitura/podkrylki/podkrylki' => 'naruznaia-furnitura/podkrylki',
            'panel-priladiv/panel-priladiv' => 'panel-priladiv',
            'pribornaia-panel/panel-pribornaia' => 'pribornaia-panel',
            'visokovoltna-batareia/visokovoltna-batareia/visokovoltna-batareia' => 'visokovoltna-batareia/visokovoltna-batareia',
            'vysokovoltnaia-batareia/vysokovoltnaia-batareia/vysokovoltnaia-batareia' => 'vysokovoltnaia-batareia/vysokovoltnaia-batareia',
        ],
        'model-s-02-2012-03-2016' => [
            'visokovoltna-sistema/bortovii-zariadnii-modul-cardzer' => 'visokovoltna-sistema/bortovii-zariadnii-modul-cardzer/bortovii-zariadnii-modul-cardzer',
            'vysokovoltnaia-sistema/bortovoi-zariadnyi-modul-cardzer' => 'vysokovoltnaia-sistema/bortovoi-zariadnyi-modul-cardzer/bortovoi-zariadnyi-modul-cardzer',
            'panel-priladiv/panel-priladiv' => 'panel-priladiv',
            'pribornaia-panel/panel-pribornaia' => 'pribornaia-panel',
            'vnutrisnje-ozdoblennia/ozdoblennia-steli-salonu/ozdoblennia-steli-salonu' => 'vnutrisnje-ozdoblennia/ozdoblennia-steli-salonu',
            'vnutrenniaia-otdelka/obsivka-potolka-salona/obsivka-potolka-salona' => 'vnutrenniaia-otdelka/obsivka-potolka-salona',
            'galma/elektromexanicnii-pidsiliuvac-galm/elektromexanicnii-pidsiliuvac-galm' => 'galma/elektromexanicnii-pidsiliuvac-galm',
            'tormoza/elektromexaniceskii-usilitel-tormozov/elektromexaniceskii-usilitel-tormozov' => 'tormoza/elektromexaniceskii-usilitel-tormozov',
            'zovnisnia-furnitura/nakladki-pidkapotnogo-vidsiku/nakladki-pidkapotnogo-vidsiku' => 'zovnisnia-furnitura/nakladki-pidkapotnogo-vidsiku',
            'naruznaia-furnitura/nakladki-podkapotnogo-otseka/nakladki-podkapotnogo-otseka' => 'naruznaia-furnitura/nakladki-podkapotnogo-otseka',
            'elektrika/frontalna-kamera/frontalna-kamera' => 'elektrika/frontalna-kamera',
            'elektrika/frontalnaia-kamera/frontalnaia-kamera' => 'elektrika/frontalnaia-kamera',
            'visokovoltna-batareia/visokovoltna-batareia' => 'visokovoltna-batareia',
            'vysokovoltnaia-batareia/vysokovoltnaia-batareia' => 'vysokovoltnaia-batareia',
            'visokovoltna-batareia/visokovoltna-batareia/visokovoltna-batareia' => 'visokovoltna-batareia',
            'vysokovoltnaia-batareia/vysokovoltnaia-batareia/vysokovoltnaia-batareia' => 'vysokovoltnaia-batareia',
        ],
        'model-s-04-2016-01-2021' => [
            'diski-i-sini/diski' => 'diski-i-sini/diski/diski',
            'diski-i-siny/diski' => 'diski-i-siny/diski/diski',
            'rulyovii-mexanizm/verxnia-castina-rulyovoyi-kolonki-i-kermo/verxnia-castina-rulyovoyi-kolonki-i-kermo' => 'rulyovii-mexanizm/verxnia-castina-rulyovoyi-kolonki-i-kermo',
            'rulevoi-mexanizm/verxniaia-cast-rulevoi-kolonki-i-rul/verxniaia-cast-rulevoi-kolonki-i-rul' => 'rulevoi-mexanizm/verxniaia-cast-rulevoi-kolonki-i-rul',
            'vnutrisnje-ozdoblennia/ozdoblennia-zadnyogo-bagaznogo-vidsiku/ozdoblennia-zadnyogo-bagaznogo-vidsiku' => 'vnutrisnje-ozdoblennia/ozdoblennia-zadnyogo-bagaznogo-vidsiku',
            'vnutrenniaia-otdelka/oblicovka-zadnego-bagaznogo-otseka/obsivka-zadnego-bagaznogo-otseka' => 'vnutrenniaia-otdelka/oblicovka-zadnego-bagaznogo-otseka',
            'galma/pedal-galma/pedal-galma' => 'galma/pedal-galma',
            'tormoza/pedal-tormoza/pedal-tormoza' => 'tormoza/pedal-tormoza',
        ],
        'model-3-highland-01-2024' => [
            'diski-i-sini/diski' => 'diski-i-sini/diski/diski',
            'diski-i-siny/diski' => 'diski-i-siny/diski/diski',
            'galma/elektromexanicnii-pidsiliuvac-galm/elektromexanicnii-pidsiliuvac-galm' => 'galma/elektromexanicnii-pidsiliuvac-galm',
            'tormoza/elektromexaniceskii-usilitel-tormozov/elektromexaniceskii-usilitel-tormozov' => 'tormoza/elektromexaniceskii-usilitel-tormozov',
            'vnutrisnje-ozdoblennia/ozdoblennia-steli-salonu/ozdoblennia-steli-salonu' => 'vnutrisnje-ozdoblennia/ozdoblennia-steli-salonu',
            'vnutrenniaia-otdelka/obsivka-potolka-salona/obsivka-potolka-salona' => 'vnutrenniaia-otdelka/obsivka-potolka-salona',
            'elektrika/pedal-akseleratora/pedal-akseleratora' => 'elektrika/pedal-akseleratora',
            'elektrika/datciki-radaru/datciki-radaru' => 'elektrika/datciki-radaru',
            'elektrika/datciki-radara/datciki-radara' => 'elektrika/datciki-radara',
            'zovnisnia-furnitura/ozdoblennia-otvoru-kriski-bagaznika/ozdoblennia-otvoru-kriski-bagaznika' => 'zovnisnia-furnitura/ozdoblennia-otvoru-kriski-bagaznika',
            'naruznaia-furnitura/obsivka-proema-kryski-bagaznika/obsivka-proema-kryski-bagaznika' => 'naruznaia-furnitura/obsivka-proema-kryski-bagaznika',
        ],
        '' => [
            'diski-i-sini/diski' => 'diski-i-sini/diski/diski',
            'diski-i-siny/diski' => 'diski-i-siny/diski/diski',
            'galma/pedal-galma' => 'galma/pedal-galma/pedal-galma',
            'tormoza/pedal-tormoza' => 'tormoza/pedal-tormoza/pedal-tormoza',
            'zadnii-motor/zadni-pivosi' => 'zadnii-motor/zadni-pivosi/zadni-pivosi',
            'zadnii-motor/zadnie-poluosi' => 'zadnii-motor/zadnie-poluosi/zadnie-poluosi',
            'zovnisnia-furnitura/podium-nomernogo-znaka' => 'zovnisnia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
            'naruznaia-furnitura/podium-nomernogo-znaka' => 'naruznaia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
            'panel-priladiv/panel-priladiv' => 'panel-priladiv',
            'pribornaia-panel/panel-pribornaia' => 'pribornaia-panel',
            'informaciino-rozvazalna-sistema/kompiuter-avtomobilia/kompiuter-avtomobilia' => 'informaciino-rozvazalna-sistema/kompiuter-avtomobilia',
            'informacionno-razvlekatelnaia-sistema/kompiuter-avtomobilia/kompiuter-avtomobilia' => 'informacionno-razvlekatelnaia-sistema/kompiuter-avtomobilia',
            'zovnisnia-furnitura/pidkrilki/pidkrilki' => 'zovnisnia-furnitura/pidkrilki',
            'naruznaia-furnitura/podkrylki/podkrylki' => 'naruznaia-furnitura/podkrylki',
            'rulyovii-mexanizm/verxnia-castina-rulyovoyi-kolonki-i-kermo/verxnia-castina-rulyovoyi-kolonki-i-kermo' => 'rulyovii-mexanizm/verxnia-castina-rulyovoyi-kolonki-i-kermo',
            'rulevoi-mexanizm/verxniaia-cast-rulevoi-kolonki-i-rul/verxniaia-cast-rulevoi-kolonki-i-rul' => 'rulevoi-mexanizm/verxniaia-cast-rulevoi-kolonki-i-rul',
            'rulyovii-mexanizm/rulyova-reika-i-niznia-castina-rulyovoyi-kolonki/rulyova-reika-i-niznia-castina-rulyovoyi-kolonki' => 'rulyovii-mexanizm/rulyova-reika-i-niznia-castina-rulyovoyi-kolonki',
            'rulevoi-mexanizm/rulevaia-reika-i-nizniaia-cast-rulevoi-kolonki/rulevaia-reika-i-nizniaia-cast-rulevoi-kolonki' => 'rulevoi-mexanizm/rulevaia-reika-i-nizniaia-cast-rulevoi-kolonki',
            'zovnisnia-furnitura/zovnisni-dzerkala/zovnisni-dzerkala' => 'zovnisnia-furnitura/zovnisni-dzerkala',
            'naruznaia-furnitura/naruznye-zerkala/vnesnie-zerkala' => 'naruznaia-furnitura/naruznye-zerkala',
            'galma/elektromexanicnii-pidsiliuvac-galm/elektromexanicnii-pidsiliuvac-galm' => 'galma/elektromexanicnii-pidsiliuvac-galm',
            'tormoza/elektromexaniceskii-usilitel-tormozov/elektromexaniceskii-usilitel-tormozov' => 'tormoza/elektromexaniceskii-usilitel-tormozov',
            'visokovoltna-sistema/bortovii-zariadnii-modul-cardzer/bortovii-zariadnii-modul-cardzer' => 'visokovoltna-sistema/bortovii-zariadnii-modul-cardzer',
            'vysokovoltnaia-sistema/bortovoi-zariadnyi-modul-cardzer/bortovoi-zariadnyi-modul-cardzer' => 'vysokovoltnaia-sistema/bortovoi-zariadnyi-modul-cardzer',
            'perednii-motor/peredni-pivosi/peredni-pivosi' => 'perednii-motor/peredni-pivosi',
            'perednii-motor/perednie-poluosi/perednie-poluosi' => 'perednii-motor/perednie-poluosi',
            'zovnisnia-furnitura/ozdoblennia-otvoru-kriski-bagaznika/ozdoblennia-otvoru-kriski-bagaznika' => 'zovnisnia-furnitura/ozdoblennia-otvoru-kriski-bagaznika',
            'naruznaia-furnitura/obsivka-proema-kryski-bagaznika/obsivka-proema-kryski-bagaznika' => 'naruznaia-furnitura/obsivka-proema-kryski-bagaznika',
            'vnutrisnje-ozdoblennia/ozdoblennia-steli-salonu/ozdoblennia-steli-salonu' => 'vnutrisnje-ozdoblennia/ozdoblennia-steli-salonu',
            'vnutrenniaia-otdelka/obsivka-potolka-salona/obsivka-potolka-salona' => 'vnutrenniaia-otdelka/obsivka-potolka-salona',
            'elektrika/datciki-radaru/datciki-radaru' => 'elektrika/datciki-radaru',
            'elektrika/datciki-radara/datciki-radara' => 'elektrika/datciki-radara',
            'diski-i-sini/sistema-kontroliu-tisku-v-sinax-tpms/sistema-kontroliu-tisku-v-sinax-tpms' => 'diski-i-sini/sistema-kontroliu-tisku-v-sinax-tpms',
            'diski-i-siny/sistema-kontrolia-davleniia-v-sinax-tpms/sistema-kontrolia-davleniia-v-sinax-tpms' => 'diski-i-siny/sistema-kontrolia-davleniia-v-sinax-tpms',
            'informacionno-razvlekatelnaia-sistema/antenna-wi-fi/antenna-wi-fi' => 'informacionno-razvlekatelnaia-sistema/antenna-wi-fi',
        ],
        'model-x-09-2015-02-2021' => [
            'zovnisnia-furnitura/nakladki-pidkapotnogo-vidsiku/nakladki-pidkapotnogo-vidsiku' => 'zovnisnia-furnitura/nakladki-pidkapotnogo-vidsiku',
            'naruznaia-furnitura/nakladki-podkapotnogo-otseka/nakladki-podkapotnogo-otseka' => 'naruznaia-furnitura/nakladki-podkapotnogo-otseka',
            'galma/elektromexanicnii-pidsiliuvac-galm/elektromexanicnii-pidsiliuvac-galm' => 'galma/elektromexanicnii-pidsiliuvac-galm',
            'tormoza/elektromexaniceskii-usilitel-tormozov/elektromexaniceskii-usilitel-tormozov' => 'tormoza/elektromexaniceskii-usilitel-tormozov',
        ],
    ];

    public static function preferred(string $path, string $modelSlug = ''): string
    {
        $path = self::normalize($path);

        return self::PREFERRED_PATHS[$modelSlug][$path] ?? $path;
    }

    public static function preferredPaths(): array
    {
        return self::PREFERRED_PATHS;
    }

    public static function expandedCatalogQuery(array $query): array
    {
        $model = (string) ($query['model_slug'] ?? '');
        $path = self::normalize((string) (($query['category_path_slug'] ?? '') ?: ($query['category_slug'] ?? '')));
        if ($path === '') {
            return $query;
        }

        $expanded = $path;
        foreach (self::PREFERRED_PATHS[$model] ?? [] as $alias => $canonical) {
            if ($canonical === $path && str_starts_with($alias, $path.'/')) {
                // A canonical parent and its reviewed child have identical full
                // inventories. Read the child's expanded tree in the same single
                // request, otherwise lazy grandchildren disappear after merging.
                if (strlen($alias) > strlen($expanded)) {
                    $expanded = $alias;
                }
            }
        }

        if ($expanded !== $path) {
            unset($query['category_slug'], $query['category_path_slug']);
            $query['category_path_slug'] = str_replace('/', '--', $expanded);
        }

        return $query;
    }

    public static function equivalentLocalePaths(array $paths, string $locale, string $model): array
    {
        // Two separate UA leaves (7 + 3 items) collide at one RU slug (10).
        // Only the common parents are genuine alternate-language equivalents.
        if ($model === '' && $locale === 'ru'
            && self::normalize((string) ($paths['ru'] ?? '')) === self::WIFI_RU_PARENT.'/antenna-wi-fi') {
            $paths['uk'] = self::WIFI_UK_PARENT;
        }

        return $paths;
    }

    public static function hasEquivalentRussianAlternate(string $path, string $locale, string $model): bool
    {
        return $model !== '' || $locale !== 'uk' || !in_array(self::normalize($path), [
            self::WIFI_UK_PARENT.'/antena-wi-fi',
            self::WIFI_UK_PARENT.'/antenna-wi-fi',
        ], true);
    }

    public static function localized(string $path, string $locale): string
    {
        $path = self::normalize($path);
        if ($locale === 'ru' && ($path === 'visokovoltna-batareia' || str_starts_with($path, 'visokovoltna-batareia/'))) {
            return 'vysokovoltnaia-batareia'.substr($path, strlen('visokovoltna-batareia'));
        }

        return $path;
    }

    public static function normalize(string $path): string
    {
        $segments = preg_split('~(?:/|--)+~', trim($path, '/'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Equal labels can represent distinct official levels (e.g. 14 and 1405).
        return implode('/', $segments);
    }

    public static function legacyCollapsed(string $path): string
    {
        $segments = explode('/', self::normalize($path));
        $normalized = [];
        foreach ($segments as $segment) {
            if ($normalized === [] || $segment !== $normalized[count($normalized) - 1]) {
                $normalized[] = $segment;
            }
        }

        return implode('/', $normalized);
    }
}
