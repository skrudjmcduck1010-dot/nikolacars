<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Support\TextEncodingNormalizer;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class StoCashbookImporter
{
    public function rows(string $path): array
    {
        return str_ends_with(strtolower($path), '.xlsx')
            ? $this->xlsxRows($path)
            : $this->csvRows($path);
    }

    protected function csvRows(string $path): array
    {
        if (($handle = fopen($path, 'r')) === false) {
            throw new RuntimeException("Cannot open file: {$path}");
        }

        $rows = [];
        $lastDate = null;
        $rowNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $row = $this->normalizeRow(array_pad($row, 11, ''));
            $firstCell = trim((string) $row[0]);

            if ($rowNumber <= 3 || in_array($firstCell, ['Итого', 'Остаток'], true)) {
                if (in_array($firstCell, ['Итого', 'Остаток'], true)) {
                    break;
                }

                continue;
            }

            $payload = $this->payloadFromRow($row, $lastDate, 'csv');

            if ($payload) {
                $rows[] = $payload;
            }
        }

        fclose($handle);

        return $rows;
    }

    protected function xlsxRows(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open workbook: {$path}");
        }

        $sharedStrings = $this->sharedStrings($zip);
        $sheets = $this->sheets($zip);
        $rows = [];

        foreach ($sheets as $sheet) {
            $worksheetXml = $zip->getFromName($sheet['path']);

            if ($worksheetXml === false) {
                continue;
            }

            $sheetRows = $this->worksheetRows($worksheetXml, $sharedStrings);
            $headerIndex = $this->headerIndex($sheetRows);

            if ($headerIndex === null) {
                continue;
            }

            $exchangeRate = $this->exchangeRate($sheetRows);
            $lastDate = $this->dateFromSheetName($sheet['name']);

            foreach (array_slice($sheetRows, $headerIndex + 1) as $row) {
                $row = $this->normalizeRow($row);
                $firstCell = trim((string) ($row[0] ?? ''));

                if (in_array($firstCell, ['Итого', 'Остаток'], true)) {
                    break;
                }

                $payload = $this->payloadFromRow(array_pad($row, 11, ''), $lastDate, 'xlsx', $sheet['name'], $exchangeRate);

                if ($payload) {
                    $rows[] = $payload;
                }
            }
        }

        $zip->close();

        return $rows;
    }

    protected function payloadFromRow(array $row, ?string &$lastDate, string $source, ?string $sheet = null, ?float $exchangeRate = null): ?array
    {
        $firstCell = trim((string) ($row[0] ?? ''));

        if ($firstCell !== '') {
            $date = $this->parseDate($firstCell);

            if ($date) {
                $lastDate = $date;
            } elseif (! $lastDate) {
                return null;
            }
        }

        if (! $lastDate || ! collect($row)->contains(fn ($cell) => trim((string) $cell) !== '')) {
            return null;
        }

        $payload = [
            'operation_date' => $lastDate,
            'income_bank_uah' => $this->money($row[1] ?? null),
            'income_cash_uah' => $this->money($row[2] ?? null),
            'income_cash_usd' => $this->money($row[3] ?? null),
            'expense_bank_uah' => $this->money($row[4] ?? null),
            'expense_cash_uah' => $this->money($row[5] ?? null),
            'expense_cash_usd' => $this->money($row[6] ?? null),
            'label' => $this->text($row[7] ?? null),
            'employee' => CashTransaction::normalizeEmployeeName($this->text($row[8] ?? null)),
            'vehicle_vin' => $this->text($row[9] ?? null),
            'comment' => $this->text($row[10] ?? null),
            'source' => $source,
            'source_sheet' => $sheet,
            'exchange_rate' => $exchangeRate,
        ];

        $hasMoney = collect([
            'income_bank_uah',
            'income_cash_uah',
            'income_cash_usd',
            'expense_bank_uah',
            'expense_cash_uah',
            'expense_cash_usd',
        ])->contains(fn ($field) => (float) $payload[$field] > 0);

        if (! $hasMoney && ! $payload['label'] && ! $payload['employee'] && ! $payload['vehicle_vin'] && ! $payload['comment']) {
            return null;
        }

        return $payload;
    }

    protected function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = new SimpleXMLElement($xml);
        $strings = [];

        foreach ($document->si as $string) {
            $text = '';

            if (isset($string->t)) {
                $text = (string) $string->t;
            } else {
                foreach ($string->r as $run) {
                    $text .= (string) $run->t;
                }
            }

            $strings[] = TextEncodingNormalizer::normalize($text) ?? '';
        }

        return $strings;
    }

    protected function sheets(ZipArchive $zip): array
    {
        $workbook = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
        $rels = new SimpleXMLElement($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $namespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $targets = [];

        foreach ($rels->Relationship as $relationship) {
            $targets[(string) $relationship['Id']] = 'xl/'.ltrim((string) $relationship['Target'], '/');
        }

        $sheets = [];

        foreach ($workbook->sheets->sheet as $sheet) {
            $attributes = $sheet->attributes($namespace);
            $relationshipId = (string) $attributes['id'];
            $path = $targets[$relationshipId] ?? null;

            if ($path) {
                $sheets[] = [
                    'name' => (string) $sheet['name'],
                    'path' => $path,
                ];
            }
        }

        return $sheets;
    }

    protected function worksheetRows(string $xml, array $sharedStrings): array
    {
        $document = new SimpleXMLElement($xml);
        $rows = [];

        foreach ($document->sheetData->row as $row) {
            $values = [];

            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                $column = $this->columnIndex($reference);
                $type = (string) $cell['t'];
                $raw = isset($cell->v) ? (string) $cell->v : '';

                $values[$column] = match ($type) {
                    's' => $sharedStrings[(int) $raw] ?? '',
                    'inlineStr' => (string) ($cell->is->t ?? ''),
                    default => $raw,
                };
            }

            if ($values !== []) {
                ksort($values);
                $max = max(array_keys($values));
                $rows[] = array_map(fn ($index) => $values[$index] ?? '', range(0, $max));
            }
        }

        return $rows;
    }

    protected function headerIndex(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $row = $this->normalizeRow($row);
            $first = trim((string) ($row[0] ?? ''));
            $income = trim((string) ($row[1] ?? ''));
            $label = trim((string) ($row[7] ?? ''));

            if ($first === 'Дата' && str_starts_with($income, 'Прих') && $label === 'Метка') {
                return $index;
            }
        }

        return null;
    }

    protected function exchangeRate(array $rows): ?float
    {
        foreach (array_slice($rows, 0, 5) as $row) {
            foreach ($row as $index => $cell) {
                if (trim((string) $cell) === 'Курс доллара') {
                    return $this->money($row[$index + 1] ?? null) ?: null;
                }
            }
        }

        return null;
    }

    protected function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/', $reference, $matches);
        $letters = $matches[0] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 30000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd.m.Y', 'd.m.y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (\Throwable) {
                //
            }
        }

        return null;
    }

    protected function dateFromSheetName(string $sheetName): ?string
    {
        $sheetName = TextEncodingNormalizer::normalize($sheetName) ?? $sheetName;

        $months = [
            'січень' => 1,
            'январь' => 1,
            'лютий' => 2,
            'февраль' => 2,
            'березень' => 3,
            'март' => 3,
            'квітень' => 4,
            'апрель' => 4,
            'травень' => 5,
            'травеень' => 5,
            'май' => 5,
            'червень' => 6,
            'июнь' => 6,
            'липень' => 7,
            'июль' => 7,
            'серпень' => 8,
            'август' => 8,
            'вересень' => 9,
            'сентябрь' => 9,
            'жовтень' => 10,
            'октябрь' => 10,
            'листопад' => 11,
            'ноябрь' => 11,
            'грудень' => 12,
            'декабрь' => 12,
        ];

        $normalized = mb_strtolower($sheetName);

        if (! preg_match('/(20\d{2})/', $normalized, $yearMatch)) {
            return null;
        }

        foreach ($months as $name => $month) {
            if (str_contains($normalized, $name)) {
                return Carbon::create((int) $yearMatch[1], $month, 1)->toDateString();
            }
        }

        return null;
    }

    protected function money(mixed $value): float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function text(mixed $value): ?string
    {
        $value = TextEncodingNormalizer::normalize((string) $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }

    protected function normalizeRow(array $row): array
    {
        return array_map(
            fn (mixed $value): mixed => is_string($value) ? TextEncodingNormalizer::normalize($value) : $value,
            $row
        );
    }
}
