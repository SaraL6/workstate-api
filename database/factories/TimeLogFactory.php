<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\TimeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeLog>
 */
class TimeLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => '2026-10-12',
            'start_time' => '2026-10-12 08:00:00',
            'end_time' => '2026-10-12 16:30:00',
            'break_minutes' => 30,
            'raw_payload' => [],
        ];
    }
}
