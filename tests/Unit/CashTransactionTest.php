<?php

namespace Tests\Unit;

use App\Models\CashTransaction;
use PHPUnit\Framework\TestCase;

class CashTransactionTest extends TestCase
{
    public function test_normalizes_windows_1251_mojibake_employee_names(): void
    {
        $this->assertSame('Леха', CashTransaction::normalizeEmployeeName('Р›РµС…Р°'));
        $this->assertSame('Раздорин Влад', CashTransaction::normalizeEmployeeName('Р Р°Р·РґРѕСЂРёРЅ Р’Р»Р°Рґ'));
    }

    public function test_keeps_normal_employee_names_unchanged(): void
    {
        $this->assertSame('Раздорин Влад', CashTransaction::normalizeEmployeeName('Раздорин Влад'));
    }

    public function test_normalizes_obmanshchikov_alias(): void
    {
        $this->assertSame('Обманщиков Евгений', CashTransaction::normalizeEmployeeName('Обманщиков'));
        $this->assertSame('Обманщиков Евгений', CashTransaction::normalizeEmployeeName('Обманщиков Евгений'));
    }

    public function test_normalizes_razdorin_alias(): void
    {
        $this->assertSame('Раздорин Влад', CashTransaction::normalizeEmployeeName('Раздорин'));
        $this->assertSame('Раздорин Влад', CashTransaction::normalizeEmployeeName('Раздорин Влад'));
    }

    public function test_normalizes_zinchenko_eugene_alias(): void
    {
        $this->assertSame('Зинченко Евгений', CashTransaction::normalizeEmployeeName('Зинченко'));
        $this->assertSame('Зинченко Евгений', CashTransaction::normalizeEmployeeName('Зинченко Евгений'));
        $this->assertSame('Зинченко Антон', CashTransaction::normalizeEmployeeName('Зинченко Антон'));
    }
}
