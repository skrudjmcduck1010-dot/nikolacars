<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartCatalogZoneClassifier
{
    public const ZONES = DonorProductGenerationService::DAMAGE_ZONES;

    protected const RULES = [
        'front' => [
            'confidence' => 95,
            'patterns' => [
                'front bumper', 'bumper front', 'front fascia', 'front reinforcement', 'front panel', 'front impact', 'front absorber',
                'hood', 'bonnet', 'frunk', 'headlight', 'radiator', 'condenser',
                'передний бампер', 'переднего бампера', 'бампер перед', 'бампера переднего', 'передняя панель', 'передняя балка',
                'передній бампер', 'переднього бампера', 'бампер перед', 'бампера переднього', 'передня панель', 'передня балка',
                'усилитель переднего бампера', 'підсилювач переднього бампера', 'абсорбер переднего бампера', 'абсорбер переднього бампера',
                'пінопласт підсилювача переднього бампера', 'капот', 'фара', 'радиатор', 'радіатор',
            ],
        ],
        'rear' => [
            'confidence' => 95,
            'patterns' => [
                'rear bumper', 'bumper rear', 'rear fascia', 'rear reinforcement', 'rear panel', 'rear impact', 'rear absorber',
                'tailgate', 'liftgate', 'trunk lid', 'trunk floor', 'taillight', 'tail light', 'rear lamp',
                'задний бампер', 'заднего бампера', 'бампер зад', 'бампера заднего', 'задняя панель', 'задняя балка',
                'задній бампер', 'заднього бампера', 'бампера заднього', 'задня панель', 'задня балка',
                'усилитель заднего бампера', 'підсилювач заднього бампера', 'абсорбер заднего бампера', 'абсорбер заднього бампера',
                'крышка багажника', 'кришка багажника', 'дверь багажника', 'двері багажника', 'пол багажника', 'підлога багажника',
                'задний фонарь', 'задній ліхтар',
            ],
        ],
        'left_front' => [
            'confidence' => 90,
            'patterns' => ['left front', 'front left', 'driver front', 'левая передняя', 'левый передний', 'левая фара', 'лев перед', 'перед лев', 'ліва передня', 'лівий передній', 'ліва фара'],
        ],
        'right_front' => [
            'confidence' => 90,
            'patterns' => ['right front', 'front right', 'passenger front', 'правая передняя', 'правый передний', 'правая фара', 'прав перед', 'перед прав', 'права передня', 'правий передній', 'права фара'],
        ],
        'left_rear' => [
            'confidence' => 90,
            'patterns' => ['left rear quarter', 'rear left quarter', 'left tail light', 'left taillight', 'заднее левое крыло', 'левый задний фонарь', 'задний левый фонарь', 'ліве заднє крило', 'лівий задній ліхтар'],
        ],
        'right_rear' => [
            'confidence' => 90,
            'patterns' => ['right rear quarter', 'rear right quarter', 'right tail light', 'right taillight', 'заднее правое крыло', 'правый задний фонарь', 'задний правый фонарь', 'праве заднє крило', 'правий задній ліхтар'],
        ],
        'left' => [
            'confidence' => 80,
            'patterns' => ['left door', 'left fender', 'left mirror', 'left rocker', 'driver door', 'driver mirror', 'левая дверь', 'левое крыло', 'левое зеркало', 'левый порог', 'ліва двер', 'ліве крило', 'ліве дзеркало', 'лівий поріг'],
        ],
        'right' => [
            'confidence' => 80,
            'patterns' => ['right door', 'right fender', 'right mirror', 'right rocker', 'passenger door', 'passenger mirror', 'правая дверь', 'правое крыло', 'правое зеркало', 'правый порог', 'права двер', 'праве крило', 'праве дзеркало', 'правий поріг'],
        ],
        'roof' => [
            'confidence' => 90,
            'patterns' => ['roof', 'panoramic', 'sunroof', 'крыша', 'потолок', 'панорам', 'дах', 'стеля'],
        ],
        'interior' => [
            'confidence' => 85,
            'patterns' => ['interior', 'seat', 'dashboard', 'instrument panel', 'console', 'trim panel', 'carpet', 'салон', 'сиден', 'торпед', 'панель прибор', 'консоль', 'обшив', 'ковер', 'салон', 'сидін', 'панель прилад', 'килим'],
        ],
        'battery' => [
            'confidence' => 90,
            'patterns' => ['battery', 'high voltage', 'hv battery', 'аккумулятор', 'батарея', 'высоковольт', 'акумулятор', 'високовольт'],
        ],
        'suspension' => [
            'confidence' => 85,
            'patterns' => ['suspension', 'control arm', 'knuckle', 'shock', 'strut', 'stabilizer', 'subframe', 'подвес', 'рычаг', 'амортиз', 'стойка', 'стабилизатор', 'подрамник', 'підвіс', 'важіль', 'амортиз', 'стійка', 'підрамник'],
        ],
        'glass' => [
            'confidence' => 85,
            'patterns' => ['glass', 'windshield', 'window', 'quarter window', 'стекл', 'лобов', 'форточк', 'скло', 'лобов'],
        ],
        'wheels' => [
            'confidence' => 90,
            'patterns' => ['wheel', 'rim', 'tire', 'tyre', 'колес', 'диск', 'шина'],
        ],
        'airbags' => [
            'confidence' => 90,
            'patterns' => ['airbag', 'srs', 'seat belt', 'pretensioner', 'подуш', 'ремень безопасности', 'преднатяж', 'подушка', 'пас безпеки', 'переднатягувач'],
        ],
    ];

    protected const NEGATIVE_CONTEXT = [
        'rear' => ['rear door', 'задняя дверь', 'задней двери', 'задня двер', 'задньої двер', 'rear view mirror', 'зеркало заднего вида', 'дзеркало заднього виду'],
        'front' => ['front seat', 'переднее сиденье', 'переднє сидіння'],
    ];

    public function classify(PartCatalogItem $item): array
    {
        $haystack = $this->haystack($item);
        $matches = [];

        foreach (self::RULES as $zone => $rule) {
            if ($this->hasNegativeContext($zone, $haystack)) {
                continue;
            }

            foreach ($rule['patterns'] as $pattern) {
                if ($this->containsPattern($haystack, $pattern)) {
                    $matches[$zone] = [
                        'zone' => $zone,
                        'confidence' => $rule['confidence'],
                        'matched_rule' => $pattern,
                    ];
                    break;
                }
            }
        }

        $matches = $this->applyCompositeRules($haystack, $matches);

        return array_values($this->normalizeSideMatches($matches));
    }

    public function refreshAll(bool $dryRun = false, ?callable $progress = null): array
    {
        $stats = [
            'items_seen' => 0,
            'items_with_zones' => 0,
            'zones_saved' => 0,
            'zones_deleted' => 0,
        ];

        PartCatalogItem::query()
            ->with(['category.parent.parent', 'zones'])
            ->orderBy('id')
            ->chunkById(500, function ($items) use (&$stats, $dryRun, $progress): void {
                foreach ($items as $item) {
                    $stats['items_seen']++;
                    $zones = $this->classify($item);

                    if ($zones !== []) {
                        $stats['items_with_zones']++;
                    }

                    if (! $dryRun) {
                        DB::transaction(function () use ($item, $zones, &$stats): void {
                            $stats['zones_deleted'] += PartCatalogItemZone::query()
                                ->where('part_catalog_item_id', $item->id)
                                ->delete();

                            foreach ($zones as $zone) {
                                PartCatalogItemZone::query()->create([
                                    'part_catalog_item_id' => $item->id,
                                    'zone' => $zone['zone'],
                                    'confidence' => $zone['confidence'],
                                    'matched_rule' => Str::limit($zone['matched_rule'], 255, ''),
                                ]);
                                $stats['zones_saved']++;
                            }
                        });
                    } else {
                        $stats['zones_saved'] += count($zones);
                    }
                }

                if ($progress) {
                    $progress("Processed {$stats['items_seen']} catalog items...");
                }
            });

        return $stats;
    }

    protected function haystack(PartCatalogItem $item): string
    {
        return Str::lower(collect([
            $item->name,
            $item->part_number,
            $item->main_category_code,
            $item->main_category_name,
            $item->subcategory_code,
            $item->subcategory_name,
            $item->node_name,
            $item->compatibility_text,
            $item->category?->code,
            $item->category?->name,
            $item->category?->parent?->code,
            $item->category?->parent?->name,
            $item->category?->parent?->parent?->code,
            $item->category?->parent?->parent?->name,
        ])->filter()->implode(' '));
    }

    protected function hasNegativeContext(string $zone, string $haystack): bool
    {
        foreach (self::NEGATIVE_CONTEXT[$zone] ?? [] as $pattern) {
            if ($this->containsPattern($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function containsPattern(string $haystack, string $pattern): bool
    {
        $pattern = Str::lower($pattern);

        if (preg_match('/^[a-z0-9]{2,4}$/', $pattern) === 1) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($pattern, '/').'(?![a-z0-9])/', $haystack) === 1;
        }

        return Str::contains($haystack, $pattern);
    }

    protected function applyCompositeRules(string $haystack, array $matches): array
    {
        $sideBodyPart = collect([
            'door', 'fender', 'mirror', 'rocker', 'handle', 'lock', 'latch',
            'двер', 'крыл', 'крыло', 'зеркал', 'дзеркал', 'порог', 'ручк', 'замок',
        ])->contains(fn (string $pattern): bool => Str::contains($haystack, $pattern));

        if (! $sideBodyPart) {
            return $matches;
        }

        $leftMarkers = ['left', 'driver', 'лев', 'ліво', 'ліва', 'ліве', 'лівий', 'лівої'];
        $rightMarkers = ['right', 'passenger', 'прав', 'права', 'праве', 'правий', 'правої'];

        if (! isset($matches['left']) && collect($leftMarkers)->contains(fn (string $pattern): bool => Str::contains($haystack, $pattern))) {
            $matches['left'] = [
                'zone' => 'left',
                'confidence' => 75,
                'matched_rule' => 'side body part + left marker',
            ];
        }

        if (! isset($matches['right']) && collect($rightMarkers)->contains(fn (string $pattern): bool => Str::contains($haystack, $pattern))) {
            $matches['right'] = [
                'zone' => 'right',
                'confidence' => 75,
                'matched_rule' => 'side body part + right marker',
            ];
        }

        return $matches;
    }

    protected function normalizeSideMatches(array $matches): array
    {
        if (isset($matches['left_front'])) {
            unset($matches['left'], $matches['front']);
        }

        if (isset($matches['right_front'])) {
            unset($matches['right'], $matches['front']);
        }

        if (isset($matches['left_rear'])) {
            unset($matches['left']);
        }

        if (isset($matches['right_rear'])) {
            unset($matches['right']);
        }

        return $matches;
    }
}
