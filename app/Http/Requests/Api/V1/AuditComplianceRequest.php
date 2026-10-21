<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class AuditComplianceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'week_start' => ['required', 'date'],
            'week_end' => ['required', 'date', 'after_or_equal:week_start'],
            'time_logs' => ['required', 'array', 'min:1', 'max:500'],
            'time_logs.*.work_date' => ['required', 'date'],
            'time_logs.*.start_time' => ['required', 'date'],
            'time_logs.*.end_time' => ['required', 'date', 'after:time_logs.*.start_time'],
            'time_logs.*.break_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'time_logs.*.raw_payload' => ['sometimes', 'array'],
            'time_logs.*.raw_payload.work_country' => ['sometimes', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = Carbon::parse($this->input('week_start'))->startOfDay();
            $end = Carbon::parse($this->input('week_end'))->startOfDay();

            if ($start->diffInDays($end, absolute: true) > 167) {
                $validator->errors()->add('week_end', 'The week range may not exceed 24 weeks.');
            }

            foreach ($this->input('time_logs', []) as $index => $log) {
                $day = Carbon::parse($log['work_date'])->startOfDay();

                if ($day->lt($start) || $day->gt($end)) {
                    $validator->errors()->add(
                        "time_logs.$index.work_date",
                        'The work date must fall inside the requested week range.',
                    );
                }

                $shiftMinutes = (int) round((Carbon::parse($log['end_time'])->getTimestamp() - Carbon::parse($log['start_time'])->getTimestamp()) / 60);

                if ((int) $log['break_minutes'] >= $shiftMinutes) {
                    $validator->errors()->add(
                        "time_logs.$index.break_minutes",
                        'The break must be shorter than the shift.',
                    );
                }
            }
        });
    }
}
