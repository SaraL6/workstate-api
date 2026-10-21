<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuditComplianceRequest;
use App\Http\Requests\Api\V1\ParseContractRequest;
use App\Http\Resources\Api\V1\ComplianceAuditResource;
use App\Http\Resources\Api\V1\ParsedContractResource;
use App\Models\Employee;
use App\Models\TimeLog;
use App\Services\ComplianceEvaluator;
use App\Services\OpenAiContractParser;
use Illuminate\Support\Carbon;

class ComplianceCheckController extends Controller
{
    public function audit(AuditComplianceRequest $request, ComplianceEvaluator $evaluator): ComplianceAuditResource
    {
        $employee = Employee::query()->findOrFail($request->integer('employee_id'));
        $weekStart = Carbon::parse($request->validated('week_start'))->toDateString();
        $weekEnd = Carbon::parse($request->validated('week_end'))->toDateString();

        $logs = collect($request->validated('time_logs'))->map(function (array $row) use ($employee): TimeLog {
            $log = new TimeLog([
                'work_date' => $row['work_date'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'break_minutes' => $row['break_minutes'],
                'raw_payload' => $row['raw_payload'] ?? [],
            ]);
            $log->employee_id = $employee->id;

            return $log;
        });

        return new ComplianceAuditResource([
            'employee_id' => $employee->id,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'violations' => $evaluator->evaluate(
                $employee,
                Carbon::parse($weekStart),
                Carbon::parse($weekEnd),
                $logs,
            ),
        ]);
    }

    public function parse(ParseContractRequest $request, OpenAiContractParser $parser): ParsedContractResource
    {
        return new ParsedContractResource(
            $parser->parse($request->validated('contract_text')),
        );
    }
}
