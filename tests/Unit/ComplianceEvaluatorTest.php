<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\TimeLog;
use App\Services\ComplianceEvaluator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComplianceEvaluatorTest extends TestCase
{
    private ComplianceEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = app(ComplianceEvaluator::class);
    }

    public function test_it_flags_a_day_over_ten_hours_and_warns_between_eight_and_ten(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->employee(),
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-18'),
            collect([
                $this->shift('2026-10-12', '08:00:00', '19:00:00', 30),
                $this->shift('2026-10-13', '08:00:00', '18:30:00', 30),
            ]),
        );

        $this->assertSame(['max_daily_hours'], $this->codes($violations, '2026-10-12'));
        $this->assertSame(['daily_hours_extension'], $this->codes($violations, '2026-10-13'));
        $this->assertSame('error', $violations[0]['severity']);
    }

    public function test_it_flags_a_rest_gap_under_eleven_hours(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->employee(),
            Carbon::parse('2026-10-13'),
            Carbon::parse('2026-10-14'),
            collect([
                $this->shift('2026-10-13', '08:00:00', '17:00:00', 30),
                $this->shift('2026-10-14', '02:00:00', '10:00:00', 30),
            ]),
        );

        $rest = $this->find($violations, 'minimum_rest_period');

        $this->assertSame('error', $rest['severity']);
        $this->assertSame('2026-10-14', $rest['work_date']);
        $this->assertSame(9.0, $rest['context']['gap_hours']);
    }

    public function test_it_warns_when_only_the_contract_rest_period_is_missed(): void
    {
        $employee = $this->employee([
            'raw_rules' => ['custom_rest_period_hours' => 12],
        ]);

        $violations = $this->evaluator->evaluate(
            $employee,
            Carbon::parse('2026-10-13'),
            Carbon::parse('2026-10-14'),
            collect([
                $this->shift('2026-10-13', '08:00:00', '16:30:00', 30),
                $this->shift('2026-10-14', '04:00:00', '12:00:00', 30),
            ]),
        );

        $rest = $this->find($violations, 'contractual_rest_period');

        $this->assertSame('warning', $rest['severity']);
        $this->assertSame(11.5, $rest['context']['gap_hours']);
    }

    public function test_it_flags_sunday_and_state_holidays_unless_exempted(): void
    {
        $employee = $this->employee(['federal_state' => 'BY']);
        $logs = collect([
            $this->shift('2026-10-18', '10:00:00', '14:00:00', 0),
            $this->shift('2026-01-06', '10:00:00', '14:00:00', 0),
        ]);

        $violations = $this->evaluator->evaluate(
            $employee,
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-10-18'),
            $logs,
        );

        $this->assertNotNull($this->find($violations, 'sunday_work'));
        $this->assertSame('2026-01-06', $this->find($violations, 'public_holiday_work')['work_date']);

        $exempt = $this->employee([
            'federal_state' => 'BY',
            'raw_rules' => ['sunday_holiday_exempt' => true],
        ]);

        $exemptViolations = $this->evaluator->evaluate(
            $exempt,
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-10-18'),
            $logs,
        );

        $this->assertNull($this->find($exemptViolations, 'sunday_work'));
        $this->assertNull($this->find($exemptViolations, 'public_holiday_work'));
    }

    public function test_it_flags_an_overnight_shift_that_crosses_into_sunday(): void
    {
        $violations = $this->evaluator->evaluate(
            $this->employee(),
            Carbon::parse('2026-10-17'),
            Carbon::parse('2026-10-18'),
            collect([
                new TimeLog([
                    'work_date' => '2026-10-17',
                    'start_time' => '2026-10-17 20:00:00',
                    'end_time' => '2026-10-18 04:00:00',
                    'break_minutes' => 0,
                    'raw_payload' => [],
                ]),
            ]),
        );

        $this->assertSame('2026-10-18', $this->find($violations, 'sunday_work')['work_date']);
    }

    public function test_it_flags_a_twenty_four_week_average_above_eight_hours(): void
    {
        $end = Carbon::parse('2026-10-18')->startOfDay();
        $start = $end->copy()->subDays(167);
        $logs = collect();

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ($day->isWeekday()) {
                $logs->push($this->shift($day->toDateString(), '08:00:00', '18:30:00', 30));
            }
        }

        $violations = $this->evaluator->evaluate(
            $this->employee(),
            $end->copy()->startOfWeek(Carbon::MONDAY),
            $end,
            $logs,
        );

        $average = $this->find($violations, 'averaging_period_exceeded');

        $this->assertSame('error', $average['severity']);
        $this->assertGreaterThan(8, $average['context']['average_hours']);
    }

    public function test_it_flags_weekly_hours_above_the_contract_limits(): void
    {
        $logs = collect(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'])
            ->map(fn (string $date): TimeLog => $this->shift($date, '08:00:00', '18:30:00', 30));

        $violations = $this->evaluator->evaluate(
            $this->employee(),
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-18'),
            $logs,
        );

        $this->assertSame('error', $this->find($violations, 'max_weekly_hours_exceeded')['severity']);
        $this->assertSame('error', $this->find($violations, 'overtime_cap_exceeded')['severity']);
        $this->assertSame(50.0, $this->find($violations, 'max_weekly_hours_exceeded')['context']['hours']);
    }

    public function test_it_warns_when_recorded_work_country_differs_from_the_contract(): void
    {
        $log = new TimeLog([
            'work_date' => '2026-10-12',
            'start_time' => '2026-10-12 08:00:00',
            'end_time' => '2026-10-12 16:30:00',
            'break_minutes' => 30,
            'raw_payload' => ['work_country' => 'FR'],
        ]);

        $violations = $this->evaluator->evaluate(
            $this->employee(),
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-18'),
            collect([$log]),
        );

        $mismatch = $this->find($violations, 'remote_work_country_mismatch');

        $this->assertSame('warning', $mismatch['severity']);
        $this->assertSame('FR', $mismatch['context']['work_country']);
        $this->assertSame('DE', $mismatch['context']['remote_work_country']);
    }

    public function test_a_regular_week_has_no_violations(): void
    {
        $logs = collect([
            '2026-10-12',
            '2026-10-13',
            '2026-10-14',
            '2026-10-15',
            '2026-10-16',
        ])->map(fn (string $date) => $this->shift($date, '08:00:00', '16:30:00', 30));

        $violations = $this->evaluator->evaluate(
            $this->employee(),
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-18'),
            $logs,
        );

        $this->assertSame([], $violations);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function employee(array $overrides = []): Employee
    {
        $employee = new Employee(array_merge([
            'name' => 'Ada Berger',
            'federal_state' => 'NW',
            'contract_hours_per_week' => 40,
            'raw_rules' => [
                'max_weekly_hours' => 48,
                'allowed_overtime_cap' => 8,
                'custom_rest_period_hours' => 11,
                'remote_work_country' => 'DE',
            ],
        ], $overrides));
        $employee->id = 5;

        return $employee;
    }

    private function shift(string $date, string $start, string $end, int $break = 30): TimeLog
    {
        return new TimeLog([
            'work_date' => $date,
            'start_time' => $date.' '.$start,
            'end_time' => $date.' '.$end,
            'break_minutes' => $break,
            'raw_payload' => [],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @return list<string>
     */
    private function codes(array $violations, string $workDate): array
    {
        return array_values(array_map(
            fn (array $violation): string => $violation['code'],
            array_filter($violations, fn (array $violation): bool => $violation['work_date'] === $workDate),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, mixed>|null
     */
    private function find(array $violations, string $code): ?array
    {
        foreach ($violations as $violation) {
            if ($violation['code'] === $code) {
                return $violation;
            }
        }

        return null;
    }
}
