<?php

namespace Tests\Unit;

use App\Support\SearchText;
use PHPUnit\Framework\TestCase;

class SearchTextTest extends TestCase
{
    public function test_arabic_letter_variants_are_unified(): void
    {
        $this->assertSame('اسراء هديه مستشفي', SearchText::normalize('إسراء  هديّة، مستشفى!'));
        $this->assertSame('امل الاحمد', SearchText::normalize('آمل الأحمـــد'));
        $this->assertSame('roses 12', SearchText::normalize('ROSES ١٢'));
    }

    public function test_tokens_are_distinct_words(): void
    {
        $this->assertSame(['ورد', 'احمر'], SearchText::tokens('ورد أحمر ورد'));
        $this->assertSame([], SearchText::tokens(' %_ '));
    }

    public function test_distance_counts_letters_not_bytes(): void
    {
        $this->assertSame(1, SearchText::distance('شوكلاته', 'شوكولاته'));
        $this->assertSame(0, SearchText::distance('ورد', 'ورد'));
        $this->assertSame(3, SearchText::distance('kitten', 'sitting'));
        $this->assertSame(2, SearchText::distance('abc', 'xyzw', 1)); // stops early above the max
    }
}
