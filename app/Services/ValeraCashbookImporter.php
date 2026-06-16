<?php

namespace App\Services;

use App\Support\TextEncodingNormalizer;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ValeraCashbookImporter
{
    public function rows(string $path): array
    {
        if (! str_ends_with(strtolower($path), '.xlsx')) {
            throw new RuntimeException('Valera cashbook import supports XLSX files only.');
        }

        return $this->xlsxRows($path);
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

            foreach ($this->worksheetRows($worksheetXml, $sharedStrings) as $row) {
                if (($row['source_row'] ?? 0) === 1) {
                    continue;
                }

                $payload = $this->payloadFromRow($this->normalizeRow($row['values']), $sheet['name'], (int) $row['source_row']);

                if ($payload) {
                    $rows[] = $payload;
                }
            }
        }

        $zip->close();

        return $rows;
    }

    protected function payloadFromRow(array $row, string $sheet, int $sourceRow): ?array
    {
        $row = array_pad($row, 11, '');

        if (! collect($row)->contains(fn ($cell) => trim((string) $cell) !== '')) {
            return null;
        }

        $date = $this->parseDate($row[0] ?? null);

        if (! $date) {
            return null;
        }

        $amountUsd = $this->money($row[2] ?? null);
        $amountUah = $this->money($row[3] ?? null);
        $purpose = $this->text($row[4] ?? null);
        $project = $this->text($row[5] ?? null);
        $person = $this->text($row[8] ?? null);

        return [
            'operation_date' => $date,
            'operation_type' => $this->text($row[1] ?? null),
            'amount_usd' => $amountUsd,
            'amount_uah' => $amountUah,
            'income_usd' => max($amountUsd, 0),
            'income_uah' => max($amountUah, 0),
            'expense_usd' => abs(min($amountUsd, 0)),
            'expense_uah' => abs(min($amountUah, 0)),
            'purpose' => $purpose,
            'project' => $project,
            'category' => $this->text($row[6] ?? null),
            'operation' => $this->text($row[7] ?? null),
            'person' => $person,
            'comment' => collect([$purpose, $project])->filter()->implode(' - ') ?: null,
            'balance_usd' => $this->nullableMoney($row[9] ?? null),
            'balance_uah' => $this->nullableMoney($row[10] ?? null),
            'source' => 'xlsx',
            'source_sheet' => $sheet,
            'source_row' => $sourceRow,
        ];
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
                $rows[] = [
                    'source_row' => (int) $row['r'],
                    'values' => array_map(fn ($index) => $values[$index] ?? '', range(0, $max)),
                ];
            }
        }

        return $rows;
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

    protected function nullableMoney(mixed $value): ?float
    {
        $value = trim((string) $value);

        return $value === '' ? null : $this->money($value);
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
