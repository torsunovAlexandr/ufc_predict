<?php

namespace Tests\Unit;

use App\Services\Scraping\Dom;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

class DomTest extends TestCase
{
    public function test_missing_selector_returns_null_instead_of_throwing(): void
    {
        $crawler = new Crawler('<div class="a">текст</div>');

        $this->assertNull(Dom::text($crawler, '.does-not-exist'));
        $this->assertSame('текст', Dom::text($crawler, ['.nope', '.a']));
    }

    public function test_percent_parsing(): void
    {
        $this->assertEqualsWithDelta(0.53, Dom::percent('53%'), 1e-9);
        $this->assertEqualsWithDelta(0.53, Dom::percent('0,53'), 1e-9);
        $this->assertNull(Dom::percent('нет данных'));
    }

    public function test_height_conversion(): void
    {
        $this->assertSame(180, Dom::heightToCm("5' 11\""));
        $this->assertSame(185, Dom::heightToCm('185 cm'));
        $this->assertSame(193, Dom::heightToCm('76'));  // дюймы
        $this->assertSame(185, Dom::heightToCm('185')); // сантиметры
    }

    public function test_time_conversion(): void
    {
        $this->assertSame(275, Dom::timeToSeconds('4:35'));
        $this->assertNull(Dom::timeToSeconds('—'));
    }

    public function test_record_parsing(): void
    {
        $record = Dom::parseRecord('18-3-1 (1 NC)');

        $this->assertSame(18, $record['wins']);
        $this->assertSame(3, $record['losses']);
        $this->assertSame(1, $record['draws']);
        $this->assertSame(1, $record['no_contests']);
    }

    public function test_method_normalisation(): void
    {
        $this->assertSame('ko_tko', Dom::normalizeMethod('KO/TKO'));
        $this->assertSame('submission', Dom::normalizeMethod('Submission (RNC)'));
        $this->assertSame('decision', Dom::normalizeMethod('Decision - Unanimous'));
        $this->assertSame('decision', Dom::normalizeMethod('Решение судей'));
    }

    public function test_stance_normalisation(): void
    {
        $this->assertSame('southpaw', Dom::normalizeStance('Southpaw'));
        $this->assertSame('orthodox', Dom::normalizeStance('Orthodox'));
        $this->assertSame('unknown', Dom::normalizeStance(null));
    }
}
