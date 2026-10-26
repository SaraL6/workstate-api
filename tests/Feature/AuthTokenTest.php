<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_issue_a_scoped_sanctum_token(): void
    {
        User::factory()->create([
            'email' => 'demo@workstate.test',
            'password' => 'password',
        ]);
        $employee = Employee::factory()->create();

        $token = $this->postJson('/api/v1/auth/token', [
            'email' => 'demo@workstate.test',
            'password' => 'password',
            'device_name' => 'laptop',
        ])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.abilities', ['compliance:audit', 'contracts:parse'])
            ->json('data.token');

        $this->assertIsString($token);

        $this->withToken($token)
            ->postJson('/api/v1/compliance/audit', [
                'employee_id' => $employee->id,
                'week_start' => '2026-10-12',
                'week_end' => '2026-10-18',
                'time_logs' => [[
                    'work_date' => '2026-10-12',
                    'start_time' => '2026-10-12 08:00:00',
                    'end_time' => '2026-10-12 16:30:00',
                    'break_minutes' => 30,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.compliant', true);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'demo@workstate.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'demo@workstate.test',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'missing@workstate.test',
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_a_token_without_the_audit_ability_is_forbidden(): void
    {
        $this->withToken($this->apiToken(['contracts:parse']))
            ->postJson('/api/v1/compliance/audit', [])
            ->assertForbidden();
    }
}
