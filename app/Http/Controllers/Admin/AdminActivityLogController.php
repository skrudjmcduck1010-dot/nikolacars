<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminActivityLogController extends Controller
{
    public function index(): View
    {
        return view('admin.activity_logs.index', [
            'logs' => AdminActivityLog::query()
                ->with('user')
                ->latest()
                ->paginate(50),
        ]);
    }

    public function teslaOfficial(): View
    {
        return view('admin.activity_logs.tesla_official', [
            'logState' => $this->teslaOfficialLogState(),
        ]);
    }

    public function teslaOfficialStatus(): JsonResponse
    {
        return response()->json($this->teslaOfficialLogState());
    }

    protected function teslaOfficialLogState(): array
    {
        $mainLog = $this->latestLogFile('tesla-official-find-part-loop*.log', ['.process.']);
        $outLog = $this->latestLogFile('tesla-official-find-part-loop*.process.out.log');
        $errLog = $this->latestLogFile('tesla-official-find-part-loop*.process.err.log');

        $mainLines = $mainLog ? $this->tailFile($mainLog, 160) : [];
        $outLines = $outLog ? $this->tailFile($outLog, 80) : [];
        $errLines = $errLog ? $this->tailFile($errLog, 80) : [];
        $summary = array_merge(
            $this->teslaOfficialSummary($mainLines),
            $this->currentTeslaOfficialProgress()
        );

        return [
            'summary' => $summary,
            'files' => [
                'main' => $this->logFileMeta($mainLog),
                'out' => $this->logFileMeta($outLog),
                'err' => $this->logFileMeta($errLog),
            ],
            'logs' => [
                'main' => $mainLines,
                'out' => $outLines,
                'err' => $errLines,
            ],
            'latest_checked_items' => $this->latestTeslaOfficialCheckedItems(),
            'refreshed_at' => now()->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'),
        ];
    }

    protected function latestTeslaOfficialCheckedItems(): array
    {
        $checkedSql = $this->teslaOfficialCheckedAtSql();

        return PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->whereRaw($checkedSql)
            ->orderByDesc('source_updated_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'part_number', 'name', 'name_en', 'source_updated_at', 'updated_at', 'raw_attributes'])
            ->map(function (PartCatalogItem $item): array {
                $raw = PartCatalogRawAttributes::from($item);
                $checkedAt = trim((string) ($raw['tesla_part_search_checked_at'] ?? ''));

                return [
                    'id' => $item->id,
                    'part_number' => (string) $item->part_number,
                    'name' => trim((string) ($item->name_en ?: $item->name)),
                    'status' => trim((string) ($raw['official_part_match_status'] ?? '')),
                    'checked_at' => $checkedAt !== '' ? $checkedAt : optional($item->source_updated_at ?: $item->updated_at)->toIso8601String(),
                    'url' => route('admin.tesla-official-catalog.show', $item),
                ];
            })
            ->values()
            ->all();
    }

    protected function currentTeslaOfficialProgress(): array
    {
        $checkedSql = $this->teslaOfficialCheckedAtSql();
        $statusSql = $this->teslaOfficialMatchStatusSql();

        $baseQuery = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereNotNull('part_number');

        $checked = (clone $baseQuery)
            ->whereRaw($checkedSql)
            ->whereRaw('coalesce('.$statusSql.", '') <> ?", ['api_error'])
            ->whereRaw('coalesce('.$statusSql.", '') <> ?", ['auth_required'])
            ->whereRaw('coalesce('.$statusSql.", '') <> ?", ['security_blocked'])
            ->count();

        $checkedTotal = (clone $baseQuery)
            ->whereRaw($checkedSql)
            ->count();

        $unchecked = (clone $baseQuery)
            ->whereRaw('not ('.$checkedSql.')')
            ->count();

        return [
            'total' => (clone $baseQuery)->count(),
            'checked' => $checked,
            'checked_total' => $checkedTotal,
            'unchecked' => $unchecked,
            'api_error' => $this->countTeslaOfficialProgressStatus($baseQuery, 'api_error'),
            'auth_required' => $this->countTeslaOfficialProgressStatus($baseQuery, 'auth_required'),
            'security_blocked' => $this->countTeslaOfficialProgressStatus($baseQuery, 'security_blocked'),
        ];
    }

    protected function countTeslaOfficialProgressStatus(Builder $baseQuery, string $status): int
    {
        $checkedSql = $this->teslaOfficialCheckedAtSql();
        $statusSql = $this->teslaOfficialMatchStatusSql();

        return (clone $baseQuery)
            ->whereRaw($checkedSql)
            ->whereRaw($statusSql.' = ?', [$status])
            ->count();
    }

    protected function teslaOfficialCheckedAtSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "nullif(trim(coalesce(raw_attributes::jsonb ->> 'tesla_part_search_checked_at', '')), '') is not null",
            'sqlite' => "nullif(trim(coalesce(json_extract(raw_attributes, '$.tesla_part_search_checked_at'), '')), '') is not null",
            default => "nullif(trim(coalesce(json_unquote(json_extract(if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object()), '$.tesla_part_search_checked_at')), '')), '') is not null",
        };
    }

    protected function teslaOfficialMatchStatusSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "raw_attributes::jsonb ->> 'official_part_match_status'",
            'sqlite' => "json_extract(raw_attributes, '$.official_part_match_status')",
            default => "json_unquote(json_extract(if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object()), '$.official_part_match_status'))",
        };
    }

    protected function latestLogFile(string $pattern, array $excludeContains = []): ?string
    {
        $files = collect(glob(storage_path('logs/'.$pattern)) ?: [])
            ->filter(function (string $path) use ($excludeContains): bool {
                $name = basename($path);

                foreach ($excludeContains as $needle) {
                    if (str_contains($name, $needle)) {
                        return false;
                    }
                }

                return is_file($path);
            })
            ->sortByDesc(fn (string $path): int => filemtime($path) ?: 0)
            ->values();

        return $files->first();
    }

    protected function latestTeslaOfficialProgress(?string $currentLog): array
    {
        $files = collect(glob(storage_path('logs/tesla-official-find-part-loop*.log')) ?: [])
            ->filter(function (string $path): bool {
                $name = basename($path);

                return is_file($path) && ! str_contains($name, '.process.');
            })
            ->sortByDesc(fn (string $path): int => filemtime($path) ?: 0)
            ->values();

        if ($currentLog !== null) {
            $files = $files
                ->reject(fn (string $path): bool => realpath($path) === realpath($currentLog))
                ->prepend($currentLog)
                ->values();
        }

        foreach ($files as $path) {
            $summary = $this->teslaOfficialSummary($this->tailFile($path, 300));

            if ($summary['checked'] !== null && $summary['unchecked'] !== null) {
                return [
                    'checked' => $summary['checked'],
                    'unchecked' => $summary['unchecked'],
                ];
            }
        }

        return [
            'checked' => null,
            'unchecked' => null,
        ];
    }

    protected function logFileMeta(?string $path): ?array
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $modifiedAt = filemtime($path) ?: null;

        return [
            'name' => basename($path),
            'size' => filesize($path) ?: 0,
            'size_label' => $this->bytesForHumans(filesize($path) ?: 0),
            'modified_at' => $modifiedAt
                ? now()->setTimestamp($modifiedAt)->timezone('Europe/Kyiv')->format('Y-m-d H:i:s')
                : null,
        ];
    }

    protected function tailFile(string $path, int $lines): array
    {
        if (! is_file($path) || $lines <= 0) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $start = max(0, $lastLine - $lines + 1);
        $buffer = [];

        for ($line = $start; $line <= $lastLine; $line++) {
            $file->seek($line);
            $value = rtrim((string) $file->current(), "\r\n");

            if ($value !== '') {
                $buffer[] = $value;
            }
        }

        return $buffer;
    }

    protected function teslaOfficialSummary(array $lines): array
    {
        $lastStarted = null;
        $lastChecking = null;
        $lastOk = null;
        $lastTimeout = null;
        $lastStopped = null;
        $lastError = null;
        $checked = null;
        $unchecked = null;
        $currentBatch = [];
        $lastEvent = null;
        $browser = null;

        foreach ($lines as $line) {
            if (str_contains($line, 'Tesla official find-part loop started')) {
                $lastStarted = $line;
                $lastEvent = $line;

                if (preg_match('/\bbrowser=([^\s]+)/', $line, $matches) === 1) {
                    $browser = $matches[1];
                }
            }

            if (preg_match('/Checking batch(?: with ([^:]+))?:\s*(.+)$/', $line, $matches) === 1) {
                $lastChecking = $line;
                $lastEvent = $line;
                if (! empty($matches[1])) {
                    $browser = trim($matches[1]);
                }
                $currentBatch = collect(explode(',', $matches[2]))
                    ->map(fn (string $value): string => trim($value))
                    ->filter()
                    ->values()
                    ->all();
            }

            if (preg_match('/Batch OK\. checked=(\d+) unchecked=(\d+)/', $line, $matches) === 1) {
                $lastOk = $line;
                $lastEvent = $line;
                $checked = (int) $matches[1];
                $unchecked = (int) $matches[2];
            }

            if (str_contains($line, 'Batch timeout')) {
                $lastTimeout = $line;
                $lastEvent = $line;
            }

            if (str_contains($line, 'BACKGROUND STOPPED WITH ERROR')) {
                $lastError = $line;
                $lastEvent = $line;
                $currentBatch = [];
            }

            if (str_contains($line, 'Tesla official find-part loop stopped.')
                || str_contains($line, 'Background worker stopped normally.')) {
                $lastStopped = $line;
                $lastEvent = $line;
                $currentBatch = [];
            }
        }

        $status = 'Нет данных';

        if ($lastEvent !== null) {
            $status = match ($lastEvent) {
                $lastChecking => 'Проверяет пачку',
                $lastTimeout => 'Последняя пачка зависла по таймауту',
                $lastOk => 'Пачка завершена',
                default => 'Запущен',
            };
        }

        if ($lastError !== null && $lastEvent === $lastError) {
            $status = 'Stopped with error';
        } elseif ($lastStopped !== null && $lastEvent === $lastStopped) {
            $status = 'Stopped';
        }

        return [
            'status' => $status,
            'started_line' => $lastStarted,
            'last_event' => $lastEvent,
            'last_checking_line' => $lastChecking,
            'last_ok_line' => $lastOk,
            'last_timeout_line' => $lastTimeout,
            'last_stopped_line' => $lastStopped,
            'last_error_line' => $lastError,
            'checked' => $checked,
            'unchecked' => $unchecked,
            'browser' => $browser,
            'current_batch' => $currentBatch,
        ];
    }

    protected function bytesForHumans(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, '.', ' ').' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, '.', ' ').' KB';
        }

        return $bytes.' B';
    }
}
