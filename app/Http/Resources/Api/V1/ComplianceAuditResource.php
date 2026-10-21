<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplianceAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $violations = $this->resource['violations'];
        $errors = count(array_filter($violations, fn (array $violation): bool => $violation['severity'] === 'error'));
        $warnings = count(array_filter($violations, fn (array $violation): bool => $violation['severity'] === 'warning'));

        return [
            'employee_id' => $this->resource['employee_id'],
            'week_start' => $this->resource['week_start'],
            'week_end' => $this->resource['week_end'],
            'compliant' => $errors === 0,
            'summary' => [
                'violations' => count($violations),
                'errors' => $errors,
                'warnings' => $warnings,
            ],
            'violations' => $violations,
        ];
    }
}
