<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'federal_state' => 'NW',
            'contract_hours_per_week' => 40,
            'raw_rules' => [
                'max_weekly_hours' => 48,
                'allowed_overtime_cap' => 8,
                'custom_rest_period_hours' => 11,
                'remote_work_country' => 'DE',
                'sunday_holiday_exempt' => false,
            ],
        ];
    }
}
