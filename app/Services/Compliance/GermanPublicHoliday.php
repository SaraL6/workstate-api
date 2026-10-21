<?php

namespace App\Services\Compliance;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Statewide German public holidays by federal state (Bundesland).
 *
 * Municipal holidays are omitted: Augsburg's Friedensfest, Mariä Himmelfahrt
 * in parts of Bavaria, and Fronleichnam in parts of Saxony and Thuringia.
 * Those dates are not holidays for the whole state, and this API only knows
 * the employee's federal_state.
 */
class GermanPublicHoliday
{
    public const TIMEZONE = 'Europe/Berlin';

    /**
     * ISO 3166-2:DE codes without the DE- prefix.
     *
     * @var array<string, list<string>>
     */
    private const STATE_HOLIDAYS = [
        'BW' => ['epiphany', 'corpus_christi', 'all_saints'],
        'BY' => ['epiphany', 'corpus_christi', 'all_saints'],
        'BE' => ['womens_day'],
        'BB' => ['easter_sunday', 'whit_sunday', 'reformation'],
        'HB' => ['reformation'],
        'HH' => ['reformation'],
        'HE' => ['corpus_christi'],
        'MV' => ['womens_day', 'reformation'],
        'NI' => ['reformation'],
        'NW' => ['corpus_christi', 'all_saints'],
        'RP' => ['corpus_christi', 'all_saints'],
        'SL' => ['corpus_christi', 'assumption', 'all_saints'],
        'SN' => ['reformation', 'repentance'],
        'ST' => ['epiphany', 'reformation'],
        'SH' => ['reformation'],
        'TH' => ['world_childrens_day', 'reformation'],
    ];

    /**
     * @var list<string>
     */
    private const NATIONWIDE = [
        'new_year',
        'good_friday',
        'easter_monday',
        'labour_day',
        'ascension',
        'whit_monday',
        'german_unity',
        'christmas',
        'boxing_day',
    ];

    public function isPublicHoliday(Carbon $date, string $federalState): bool
    {
        $day = $date->copy()->timezone(self::TIMEZONE)->toDateString();

        return isset($this->holidayDates((int) $date->copy()->timezone(self::TIMEZONE)->year, $federalState)[$day]);
    }

    /**
     * @return array<string, string> Map of Y-m-d => holiday key
     */
    public function holidayDates(int $year, string $federalState): array
    {
        $state = $this->normalizeState($federalState);
        $definitions = $this->definitions($year);
        $keys = array_merge(self::NATIONWIDE, self::STATE_HOLIDAYS[$state]);
        $dates = [];

        foreach ($keys as $key) {
            $dates[$definitions[$key]->toDateString()] = $key;
        }

        return $dates;
    }

    public function isKnownState(string $federalState): bool
    {
        return isset(self::STATE_HOLIDAYS[strtoupper($federalState)]);
    }

    private function normalizeState(string $federalState): string
    {
        $state = strtoupper($federalState);

        if (! isset(self::STATE_HOLIDAYS[$state])) {
            throw new InvalidArgumentException('Unknown German federal state.');
        }

        return $state;
    }

    /**
     * @return array<string, Carbon>
     */
    private function definitions(int $year): array
    {
        $easter = $this->easterSunday($year);

        return [
            'new_year' => $this->date($year, 1, 1),
            'epiphany' => $this->date($year, 1, 6),
            'womens_day' => $this->date($year, 3, 8),
            'good_friday' => $easter->copy()->subDays(2),
            'easter_sunday' => $easter->copy(),
            'easter_monday' => $easter->copy()->addDay(),
            'labour_day' => $this->date($year, 5, 1),
            'ascension' => $easter->copy()->addDays(39),
            'whit_sunday' => $easter->copy()->addDays(49),
            'whit_monday' => $easter->copy()->addDays(50),
            'corpus_christi' => $easter->copy()->addDays(60),
            'assumption' => $this->date($year, 8, 15),
            'world_childrens_day' => $this->date($year, 9, 20),
            'german_unity' => $this->date($year, 10, 3),
            'reformation' => $this->date($year, 10, 31),
            'all_saints' => $this->date($year, 11, 1),
            'repentance' => $this->date($year, 11, 23)->previous(Carbon::WEDNESDAY),
            'christmas' => $this->date($year, 12, 25),
            'boxing_day' => $this->date($year, 12, 26),
        ];
    }

    private function date(int $year, int $month, int $day): Carbon
    {
        return Carbon::create($year, $month, $day, 0, 0, 0, self::TIMEZONE);
    }

    /**
     * Anonymous Gregorian computus.
     */
    private function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return $this->date($year, $month, $day);
    }
}
