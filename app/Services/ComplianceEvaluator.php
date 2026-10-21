<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\TimeLog;
use App\Services\Compliance\ComplianceViolation;
use App\Services\Compliance\GermanPublicHoliday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ComplianceEvaluator
{
    public const MAX_DAILY_MINUTES = 600;

    public const STANDARD_DAILY_MINUTES = 480;

    public const MIN_REST_MINUTES = 660;

    public const AVERAGING_DAYS = 168;

    public function __construct(private GermanPublicHoliday $holidays) {}

    /**
     * Check ArbZG and the employee's contract rules for a week range.
     *
     * Submitted logs replace stored logs on the same work dates. Stored logs
     * still fill the 24-week averaging window and the rest gap into the first shift.
     *
     * @param  Collection<int, TimeLog>|null  $timeLogs
     * @return list<array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}>
     */
    public function evaluate(Employee $employee, Carbon $weekStart, Carbon $weekEnd, ?Collection $timeLogs = null): array
    {
        $from = $weekStart->copy()->timezone(GermanPublicHoliday::TIMEZONE)->startOfDay();
        $to = $weekEnd->copy()->timezone(GermanPublicHoliday::TIMEZONE)->startOfDay();
        $rangeLogs = ($timeLogs ?? $this->storedLogs($employee, $from, $to))
            ->filter(fn (TimeLog $log) => $this->inRange($log, $from, $to))
            ->values();

        $violations = array_merge(
            $this->dailyHourViolations($employee, $rangeLogs),
            $this->restViolations($employee, $rangeLogs, $from),
            $this->sundayHolidayViolations($employee, $rangeLogs),
            $this->weeklyContractViolations($employee, $rangeLogs),
            $this->remoteWorkViolations($employee, $rangeLogs),
            $this->averagingViolations($employee, $from, $to, $timeLogs ?? $rangeLogs),
        );

        usort($violations, function (array $left, array $right): int {
            return [$left['work_date'] ?? '', $left['severity'], $left['code']]
                <=> [$right['work_date'] ?? '', $right['severity'], $right['code']];
        });

        return array_values($violations);
    }

    /**
     * @param  Collection<int, TimeLog>  $logs
     * @return list<array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}>
     */
    private function dailyHourViolations(Employee $employee, Collection $logs): array
    {
        $violations = [];

        foreach ($logs->groupBy(fn (TimeLog $log) => $this->workDate($log)) as $workDate => $dayLogs) {
            $minutes = 0;
            $invalid = false;

            foreach ($dayLogs as $log) {
                $span = $this->spanMinutes($log);

                if ($span === null || $span > 36 * 60) {
                    $invalid = true;

                    continue;
                }

                if ((int) $log->break_minutes > $span) {
                    $violations[] = $this->violation(
                        $employee,
                        'break_exceeds_shift',
                        ComplianceViolation::WARNING,
                        'A break is longer than the shift it belongs to, so that shift was counted as zero hours.',
                        (string) $workDate,
                        ['break_minutes' => (int) $log->break_minutes, 'shift_minutes' => $span],
                    )->toArray();

                    continue;
                }

                $minutes += $span - (int) $log->break_minutes;
            }

            if ($invalid) {
                $violations[] = $this->violation(
                    $employee,
                    'invalid_time_log',
                    ComplianceViolation::ERROR,
                    'A time log has an end time before its start, or a shift longer than 36 hours.',
                    (string) $workDate,
                )->toArray();
            }

            if ($minutes > self::MAX_DAILY_MINUTES) {
                $violations[] = $this->violation(
                    $employee,
                    'max_daily_hours',
                    ComplianceViolation::ERROR,
                    'Daily working time exceeds the 10 hour Arbeitszeitgesetz limit.',
                    (string) $workDate,
                    ['hours' => $this->hours($minutes), 'limit_hours' => 10],
                )->toArray();
            } elseif ($minutes > self::STANDARD_DAILY_MINUTES) {
                $violations[] = $this->violation(
                    $employee,
                    'daily_hours_extension',
                    ComplianceViolation::WARNING,
                    'Daily working time is above 8 hours and must be averaged back to 8 hours within 24 weeks.',
                    (string) $workDate,
                    ['hours' => $this->hours($minutes), 'standard_hours' => 8, 'limit_hours' => 10],
                )->toArray();
            }
        }

        return $violations;
    }

    /**
     * @param  Collection<int, TimeLog>  $logs
     * @return list<array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}>
     */
    private function restViolations(Employee $employee, Collection $logs, Carbon $from): array
    {
        $shifts = $logs->values();
        $previous = $this->previousShift($employee, $from);

        if ($previous instanceof TimeLog) {
            $shifts->prepend($previous);
        }

        $sorted = $shifts->sortBy(fn (TimeLog $log) => $this->moment($log->start_time)->getTimestamp())->values();
        $required = $this->requiredRestMinutes($employee);
        $violations = [];

        for ($index = 1; $index < $sorted->count(); $index++) {
            $current = $sorted[$index - 1];
            $next = $sorted[$index];
            $currentEnd = $this->moment($current->end_time);
            $nextStart = $this->moment($next->start_time);
            $gap = (int) round(($nextStart->getTimestamp() - $currentEnd->getTimestamp()) / 60);
            $workDate = $this->workDate($next);

            if ($gap < self::MIN_REST_MINUTES) {
                $violations[] = $this->violation(
                    $employee,
                    'minimum_rest_period',
                    ComplianceViolation::ERROR,
                    'The gap between the end of one shift and the start of the next is shorter than the 11 hour rest period.',
                    $workDate,
                    [
                        'gap_hours' => $this->hours(max(0, $gap)),
                        'required_hours' => 11,
                        'previous_end' => $currentEnd->toIso8601String(),
                        'next_start' => $nextStart->toIso8601String(),
                    ],
                )->toArray();

                continue;
            }

            if ($gap < $required) {
                $violations[] = $this->violation(
                    $employee,
                    'contractual_rest_period',
                    ComplianceViolation::WARNING,
                    'The gap between shifts meets the 11 hour statutory rest but is shorter than the contract rest period.',
                    $workDate,
                    [
                        'gap_hours' => $this->hours($gap),
                        'required_hours' => $this->hours($required),
                        'previous_end' => $currentEnd->toIso8601String(),
                        'next_start' => $nextStart->toIso8601String(),
                    ],
                )->toArray();
            }
        }

        return $violations;
    }

    /**
     * @param  Collection<int, TimeLog>  $logs
     * @return list<array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}>
     */
    private function sundayHolidayViolations(Employee $employee, Collection $logs): array
    {
        if ($this->isSundayHolidayExempt($employee)) {
            return [];
        }

        $state = strtoupper((string) $employee->federal_state);
        $unknownState = ! $this->holidays->isKnownState($state);
        $violations = [];
        $seen = [];

        if ($unknownState) {
            $violations[] = $this->violation(
                $employee,
                'unknown_federal_state',
                ComplianceViolation::ERROR,
                'The employee federal state is not a known German state, so regional public holidays could not be checked.',
                null,
                ['federal_state' => (string) $employee->federal_state],
            )->toArray();
        }

        foreach ($logs as $log) {
            $start = $this->moment($log->start_time)->timezone(GermanPublicHoliday::TIMEZONE);
            $end = $this->moment($log->end_time)->timezone(GermanPublicHoliday::TIMEZONE);

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $cursor = $start->copy()->startOfDay();
            $guard = 0;

            while ($cursor->lt($end) && $guard < 3) {
                $dayEnd = $cursor->copy()->addDay();
                $overlaps = $start->lt($dayEnd) && $end->gt($cursor);
                $day = $cursor->toDateString();

                if ($overlaps && $cursor->isSunday() && ! isset($seen['sunday:'.$day])) {
                    $seen['sunday:'.$day] = true;
                    $violations[] = $this->violation(
                        $employee,
                        'sunday_work',
                        ComplianceViolation::ERROR,
                        'Work overlaps a Sunday. Sunday work is prohibited unless the contract exempts it.',
                        $day,
                    )->toArray();
                }

                if ($overlaps && ! $unknownState && $this->holidays->isPublicHoliday($cursor, $state) && ! isset($seen['holiday:'.$day])) {
                    $seen['holiday:'.$day] = true;
                    $violations[] = $this->violation(
                        $employee,
                        'public_holiday_work',
                        ComplianceViolation::ERROR,
                        'Work overlaps a public holiday in the employee federal state.',
                        $day,
                        ['federal_state' => $state],
                    )->toArray();
                }

                $cursor = $dayEnd;
                $guard++;
            }
        }

        return $violations;
    }

    /**
     * @param  Collection<int, TimeLog>  $logs
     * @return list<array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}>
     */
    private function weeklyContractViolations(Employee $employee, Collection $logs): array
    {
        $rules = $this->rules($employee);
        $contractMinutes = (int) round(((float) $employee->contract_hours_per_week) * 60);
        $violations = [];

        foreach ($logs->groupBy(fn (TimeLog $log) => $this->moment($log->work_date)->startOfWeek(Carbon::MONDAY)->toDateString()) as $week => $weekLogs) {
            $minutes = $this->workedMinutes($weekLogs);
            $errored = false;

            if (isset($rules['max_weekly_hours']) && is_numeric($rules['max_weekly_hours'])) {
                $limit = (int) round(((float) $rules['max_weekly_hours']) * 60);

                if ($minutes > $limit) {
                    $errored = true;
                    $violations[] = $this->violation(
                        $employee,
                        'max_weekly_hours_exceeded',
                        ComplianceViolation::ERROR,
                        'Weekly working time exceeds the maximum weekly hours in the contract.',
                        (string) $week,
                        ['hours' => $this->hours($minutes), 'limit_hours' => $this->hours($limit)],
                    )->toArray();
                }
            }

            if (isset($rules['allowed_overtime_cap']) && is_numeric($rules['allowed_overtime_cap'])) {
                $ceiling = $contractMinutes + (int) round(((float) $rules['allowed_overtime_cap']) * 60);

                if ($minutes > $ceiling) {
                    $errored = true;
                    $violations[] = $this->violation(
                        $employee,
                        'overtime_cap_exceeded',
                        ComplianceViolation::ERROR,
                        'Weekly working time exceeds the contract hours plus the allowed overtime cap.',
                        (string) $week,
                        [
                            'hours' => $this->hours($minutes),
                            'contract_hours' => $this->hours($contractMinutes),
                            'overtime_cap_hours' => (float) $rules['allowed_overtime_cap'],
                        ],
                    )->toArray();
                }
            }

            if (! $errored && $contractMinutes > 0 && $minutes > $contractMinutes) {
                $violations[] = $this->violation(
                    $employee,
                    'contract_hours_exceeded',
                    ComplianceViolation::WARNING,
                    'Weekly working time exceeds the agreed contract hours.',
                    (string) $week,
                    ['hours' => $this->hours($minutes), 'contract_hours' => $this->hours($contractMinutes)],
                )->toArray();
            }
        }

        return $violations;
    }

    /**
     * @param  Collection<int, TimeLog>  $logs
     * @return list<array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}>
     */
    private function remoteWorkViolations(Employee $employee, Collection $logs): array
    {
        $allowed = strtoupper((string) ($this->rules($employee)['remote_work_country'] ?? ''));

        if ($allowed === '') {
            return [];
        }

        $violations = [];

        foreach ($logs as $log) {
            $payload = is_array($log->raw_payload) ? $log->raw_payload : [];
            $actual = strtoupper((string) ($payload['work_country'] ?? ''));

            if ($actual === '' || $actual === $allowed) {
                continue;
            }

            $violations[] = $this->violation(
                $employee,
                'remote_work_country_mismatch',
                ComplianceViolation::WARNING,
                'A time log records work in a country other than the contract remote-work country.',
                $this->workDate($log),
                ['work_country' => $actual, 'remote_work_country' => $allowed],
            )->toArray();
        }

        return $violations;
    }

    /**
     * @param  Collection<int, TimeLog>  $supplied
     * @return list<array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}>
     */
    private function averagingViolations(Employee $employee, Carbon $from, Carbon $to, Collection $supplied): array
    {
        $windowStart = $to->copy()->subDays(self::AVERAGING_DAYS - 1)->startOfDay();
        $logs = $this->logsForAverage($employee, $windowStart, $to, $supplied);
        $worked = $this->workedMinutes($logs);
        $workingDays = 0;

        for ($cursor = $windowStart->copy(); $cursor->lte($to); $cursor->addDay()) {
            if (! $cursor->isSunday()) {
                $workingDays++;
            }
        }

        if ($workingDays === 0 || $worked <= $workingDays * self::STANDARD_DAILY_MINUTES) {
            return [];
        }

        return [
            $this->violation(
                $employee,
                'averaging_period_exceeded',
                ComplianceViolation::ERROR,
                'Average working time across the 24-week window exceeds 8 hours per Monday-to-Saturday working day.',
                $to->toDateString(),
                [
                    'window_start' => $windowStart->toDateString(),
                    'window_end' => $to->toDateString(),
                    'average_hours' => $this->hours((int) round($worked / $workingDays)),
                    'limit_hours' => 8,
                    'total_hours' => $this->hours($worked),
                    'working_days' => $workingDays,
                ],
            )->toArray(),
        ];
    }

    /**
     * @param  Collection<int, TimeLog>  $supplied
     * @return Collection<int, TimeLog>
     */
    private function logsForAverage(Employee $employee, Carbon $windowStart, Carbon $windowEnd, Collection $supplied): Collection
    {
        $suppliedInWindow = $supplied
            ->filter(fn (TimeLog $log) => $this->inRange($log, $windowStart, $windowEnd))
            ->values();

        if (! $employee->exists) {
            return $suppliedInWindow;
        }

        $replacedDates = $suppliedInWindow
            ->map(fn (TimeLog $log) => $this->workDate($log))
            ->unique();

        $history = $this->storedLogs($employee, $windowStart, $windowEnd)
            ->reject(fn (TimeLog $log) => $replacedDates->contains($this->workDate($log)));

        return $history->concat($suppliedInWindow)->values();
    }

    /**
     * @return Collection<int, TimeLog>
     */
    private function storedLogs(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        if (! $employee->exists) {
            return collect();
        }

        return TimeLog::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', '>=', $from->toDateString())
            ->where('work_date', '<=', $to->toDateString())
            ->orderBy('start_time')
            ->get();
    }

    private function previousShift(Employee $employee, Carbon $from): ?TimeLog
    {
        if (! $employee->exists) {
            return null;
        }

        return TimeLog::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', '<', $from->toDateString())
            ->orderByDesc('end_time')
            ->first();
    }

    /**
     * @param  Collection<int, TimeLog>  $logs
     */
    private function workedMinutes(Collection $logs): int
    {
        $minutes = 0;

        foreach ($logs as $log) {
            $span = $this->spanMinutes($log);

            if ($span === null || (int) $log->break_minutes >= $span) {
                continue;
            }

            $minutes += $span - (int) $log->break_minutes;
        }

        return $minutes;
    }

    private function spanMinutes(TimeLog $log): ?int
    {
        $start = $this->moment($log->start_time);
        $end = $this->moment($log->end_time);
        $span = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);

        if ($span <= 0) {
            return null;
        }

        return $span;
    }

    private function requiredRestMinutes(Employee $employee): int
    {
        $custom = $this->rules($employee)['custom_rest_period_hours'] ?? null;

        if (! is_numeric($custom)) {
            return self::MIN_REST_MINUTES;
        }

        return max(self::MIN_REST_MINUTES, (int) round(((float) $custom) * 60));
    }

    private function isSundayHolidayExempt(Employee $employee): bool
    {
        return (bool) ($this->rules($employee)['sunday_holiday_exempt'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Employee $employee): array
    {
        return is_array($employee->raw_rules) ? $employee->raw_rules : [];
    }

    private function inRange(TimeLog $log, Carbon $from, Carbon $to): bool
    {
        $day = $this->workDate($log);

        return $day >= $from->toDateString() && $day <= $to->toDateString();
    }

    private function workDate(TimeLog $log): string
    {
        return $this->moment($log->work_date)->toDateString();
    }

    private function moment(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->timezone(GermanPublicHoliday::TIMEZONE);
        }

        try {
            return Carbon::parse($value, GermanPublicHoliday::TIMEZONE)->timezone(GermanPublicHoliday::TIMEZONE);
        } catch (InvalidArgumentException) {
            return Carbon::parse($value)->timezone(GermanPublicHoliday::TIMEZONE);
        }
    }

    private function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function violation(
        Employee $employee,
        string $code,
        string $severity,
        string $message,
        ?string $workDate,
        array $context = [],
    ): ComplianceViolation {
        return new ComplianceViolation(
            $code,
            $severity,
            $message,
            $employee->id,
            $workDate,
            $context,
        );
    }
}
