<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TimeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ComplianceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_requires_the_api_token(): void
    {
        $this->postJson('/api/v1/compliance/audit', [])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_audit_validates_the_payload(): void
    {
        $this->withToken($this->apiToken())
            ->postJson('/api/v1/compliance/audit', [
                'employee_id' => 999,
                'week_start' => '2026-10-18',
                'week_end' => '2026-10-12',
                'time_logs' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id', 'week_end', 'time_logs']);
    }

    public function test_audit_returns_a_violation_summary(): void
    {
        $employee = Employee::factory()->create(['federal_state' => 'NW']);

        $response = $this->withToken($this->apiToken())->postJson('/api/v1/compliance/audit', [
            'employee_id' => $employee->id,
            'week_start' => '2026-10-12',
            'week_end' => '2026-10-18',
            'time_logs' => [
                $this->log('2026-10-12', '08:00:00', '19:00:00', 30),
                $this->log('2026-10-18', '10:00:00', '14:00:00', 0),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.compliant', false)
            ->assertJsonPath('data.week_start', '2026-10-12')
            ->assertJsonPath('data.week_end', '2026-10-18');

        $codes = collect($response->json('data.violations'))->pluck('code');

        $this->assertTrue($codes->contains('max_daily_hours'));
        $this->assertTrue($codes->contains('sunday_work'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.summary.errors'));
    }

    public function test_audit_uses_stored_history_for_the_averaging_window(): void
    {
        $employee = Employee::factory()->create([
            'federal_state' => 'BE',
            'contract_hours_per_week' => 60,
            'raw_rules' => [
                'max_weekly_hours' => 60,
                'allowed_overtime_cap' => 0,
                'custom_rest_period_hours' => 11,
                'remote_work_country' => 'DE',
            ],
        ]);

        $weekEnd = Carbon::parse('2026-10-18')->startOfDay();

        for ($day = $weekEnd->copy()->subDays(167); $day->lte($weekEnd); $day->addDay()) {
            if ($day->isSunday()) {
                continue;
            }

            TimeLog::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
                'start_time' => $day->toDateString().' 08:00:00',
                'end_time' => $day->toDateString().' 18:30:00',
                'break_minutes' => 30,
            ]);
        }

        $response = $this->withToken($this->apiToken())->postJson('/api/v1/compliance/audit', [
            'employee_id' => $employee->id,
            'week_start' => '2026-10-12',
            'week_end' => '2026-10-18',
            'time_logs' => [
                $this->log('2026-10-14', '08:00:00', '18:30:00', 30),
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.compliant', false);

        $this->assertTrue(
            collect($response->json('data.violations'))->pluck('code')->contains('averaging_period_exceeded'),
        );
    }

    public function test_a_regular_week_is_compliant(): void
    {
        $employee = Employee::factory()->create();

        $logs = collect(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'])
            ->map(fn (string $date): array => $this->log($date, '08:00:00', '16:30:00', 30))
            ->all();

        $this->withToken($this->apiToken())
            ->postJson('/api/v1/compliance/audit', [
                'employee_id' => $employee->id,
                'week_start' => '2026-10-12',
                'week_end' => '2026-10-18',
                'time_logs' => $logs,
            ])
            ->assertOk()
            ->assertJsonPath('data.compliant', true)
            ->assertJsonPath('data.summary.errors', 0)
            ->assertJsonPath('data.violations', []);
    }

    public function test_compliance_tables_expose_the_requested_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('companies', ['id', 'name', 'default_country', 'settings']));
        $this->assertTrue(Schema::hasColumns('employees', ['id', 'company_id', 'name', 'federal_state', 'contract_hours_per_week', 'raw_rules']));
        $this->assertTrue(Schema::hasColumns('time_logs', ['id', 'employee_id', 'work_date', 'start_time', 'end_time', 'break_minutes', 'raw_payload']));

        $columns = collect(Schema::getIndexes('time_logs'))
            ->pluck('columns')
            ->flatten();

        $this->assertTrue($columns->contains('employee_id'));
        $this->assertTrue($columns->contains('work_date'));
    }

    /**
     * @return array{work_date: string, start_time: string, end_time: string, break_minutes: int}
     */
    private function log(string $date, string $start, string $end, int $break): array
    {
        return [
            'work_date' => $date,
            'start_time' => $date.' '.$start,
            'end_time' => $date.' '.$end,
            'break_minutes' => $break,
        ];
    }
}
