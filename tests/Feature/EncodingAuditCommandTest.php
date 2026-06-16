<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EncodingAuditCommandTest extends TestCase
{
    protected string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = base_path('tests/.encoding-audit-fixture.txt');
    }

    protected function tearDown(): void
    {
        File::delete($this->fixturePath);

        parent::tearDown();
    }

    public function test_audit_fails_by_default_when_mojibake_is_found(): void
    {
        File::put($this->fixturePath, $this->mojibake('Выгрузка Prom'));

        $this->artisan('encoding:audit', [
            '--path' => 'tests/.encoding-audit-fixture.txt',
        ])->assertFailed();
    }

    public function test_audit_can_be_run_in_report_only_mode(): void
    {
        File::put($this->fixturePath, $this->mojibake('Выгрузка Prom'));

        $this->artisan('encoding:audit', [
            '--path' => 'tests/.encoding-audit-fixture.txt',
            '--report-only' => true,
        ])->assertSuccessful();
    }
    public function test_audit_fails_on_undetermined_category_mojibake(): void
    {
        $undetermined = "\u{041D}\u{0435} \u{043E}\u{043F}\u{0440}\u{0435}\u{0434}\u{0435}\u{043B}\u{0435}\u{043D}\u{043E}";

        File::put($this->fixturePath, $this->mojibake($undetermined));

        $this->artisan('encoding:audit', [
            '--path' => 'tests/.encoding-audit-fixture.txt',
        ])->assertFailed();
    }

    public function test_audit_fails_on_short_save_button_mojibake(): void
    {
        File::put($this->fixturePath, $this->mojibake('Сохр.'));

        $this->artisan('encoding:audit', [
            '--path' => 'tests/.encoding-audit-fixture.txt',
        ])->assertFailed();
    }

    protected function mojibake(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
    }
}
