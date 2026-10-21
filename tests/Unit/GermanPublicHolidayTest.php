<?php

namespace Tests\Unit;

use App\Services\Compliance\GermanPublicHoliday;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class GermanPublicHolidayTest extends TestCase
{
    private GermanPublicHoliday $holidays;

    protected function setUp(): void
    {
        parent::setUp();

        $this->holidays = new GermanPublicHoliday;
    }

    public function test_it_knows_2026_easter_and_nationwide_holidays(): void
    {
        $dates = $this->holidays->holidayDates(2026, 'BE');

        $this->assertSame('easter_sunday', $this->holidays->holidayDates(2026, 'BB')['2026-04-05']);
        $this->assertSame('good_friday', $dates['2026-04-03']);
        $this->assertSame('easter_monday', $dates['2026-04-06']);
        $this->assertSame('ascension', $dates['2026-05-14']);
        $this->assertSame('whit_monday', $dates['2026-05-25']);
        $this->assertSame('german_unity', $dates['2026-10-03']);
        $this->assertSame('christmas', $dates['2026-12-25']);
    }

    public function test_it_applies_regional_holidays_by_federal_state(): void
    {
        $this->assertTrue($this->holidays->isPublicHoliday(Carbon::parse('2026-01-06'), 'BY'));
        $this->assertFalse($this->holidays->isPublicHoliday(Carbon::parse('2026-01-06'), 'NW'));

        $this->assertTrue($this->holidays->isPublicHoliday(Carbon::parse('2026-11-01'), 'NW'));
        $this->assertFalse($this->holidays->isPublicHoliday(Carbon::parse('2026-11-01'), 'BE'));

        $this->assertTrue($this->holidays->isPublicHoliday(Carbon::parse('2026-10-31'), 'SN'));
        $this->assertFalse($this->holidays->isPublicHoliday(Carbon::parse('2026-10-31'), 'BY'));

        $this->assertSame('repentance', $this->holidays->holidayDates(2026, 'SN')['2026-11-18']);
        $this->assertArrayNotHasKey('2026-11-18', $this->holidays->holidayDates(2026, 'NW'));

        $this->assertTrue($this->holidays->isPublicHoliday(Carbon::parse('2026-03-08'), 'BE'));
        $this->assertTrue($this->holidays->isPublicHoliday(Carbon::parse('2026-08-15'), 'SL'));
        $this->assertFalse($this->holidays->isPublicHoliday(Carbon::parse('2026-08-15'), 'BY'));
        $this->assertTrue($this->holidays->isPublicHoliday(Carbon::parse('2026-09-20'), 'TH'));
        $this->assertSame('corpus_christi', $this->holidays->holidayDates(2026, 'NW')['2026-06-04']);
    }

    public function test_it_rejects_an_unknown_federal_state(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->holidays->isPublicHoliday(Carbon::parse('2026-10-03'), 'XX');
    }
}
