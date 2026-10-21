<?php

namespace App\Models;

use Database\Factories\TimeLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'work_date', 'start_time', 'end_time', 'break_minutes', 'raw_payload'])]
class TimeLog extends Model
{
    /** @use HasFactory<TimeLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'break_minutes' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
