<?php

namespace Tests\Unit;

use App\Support\PartNumberNormalizer;
use PHPUnit\Framework\TestCase;

class PartNumberNormalizerTest extends TestCase
{
    public function test_normalizes_cyrillic_confusable_letters(): void
    {
        $this->assertSame('1099297-00-A', PartNumberNormalizer::normalize('1099297-00-А'));
        $this->assertSame('1044681-00-B', PartNumberNormalizer::normalize('1044681-00-В'));
        $this->assertSame('146627-00-C', PartNumberNormalizer::normalize('146627-00-С'));
        $this->assertSame('1044461-02-E', PartNumberNormalizer::normalize('1044461-02-Е'));
    }

    public function test_normalizes_mojibake_cyrillic_confusable_letters(): void
    {
        $this->assertSame('1032146-00-C', PartNumberNormalizer::normalize('1032146-00-РЎ'));
        $this->assertSame('1027976-00-A', PartNumberNormalizer::normalize('1027976-00-Рђ'));
    }

    public function test_strips_trailing_ag_suffix(): void
    {
        $this->assertSame('1002254-07-G', PartNumberNormalizer::normalize('1002254-07-G AG'));
    }

    public function test_truncates_tail_only_for_standard_tesla_part_numbers(): void
    {
        $this->assertSame('1002294-00-B', PartNumberNormalizer::normalize('1002294-00-B Z'));
        $this->assertSame('1002294-00-F', PartNumberNormalizer::normalize('1002294-00-F Y'));
        $this->assertSame('1002294-00-B', PartNumberNormalizer::normalize('1002294-00-B, extra'));
        $this->assertSame('1004833-00-A', PartNumberNormalizer::normalize('1004833-00-AZ'));
        $this->assertSame('1774269-00-F', PartNumberNormalizer::normalize('1774269-00-F1'));

        $this->assertSame('106047027C 2L', PartNumberNormalizer::normalize('106047027C 2L'));
        $this->assertSame('1234567-00-A', PartNumberNormalizer::normalize('1234567-00-AB'));
        $this->assertSame('1007013-00-02', PartNumberNormalizer::normalize('1007013-00-02'));
    }

    public function test_removes_remaining_cyrillic_letters(): void
    {
        $this->assertSame('123-45', PartNumberNormalizer::normalize('123-жя-45'));
        $this->assertNull(PartNumberNormalizer::normalize('КРИЛО'));
    }
}
