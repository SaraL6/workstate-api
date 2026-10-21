<?php

namespace App\Services\Compliance;

use InvalidArgumentException;

class ComplianceViolation
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $message,
        public readonly ?int $employeeId,
        public readonly ?string $workDate,
        public readonly array $context = [],
    ) {
        if (! in_array($severity, [self::ERROR, self::WARNING], true)) {
            throw new InvalidArgumentException('Unknown violation severity.');
        }
    }

    /**
     * @return array{code: string, severity: string, message: string, employee_id: int|null, work_date: string|null, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'employee_id' => $this->employeeId,
            'work_date' => $this->workDate,
            'context' => $this->context,
        ];
    }
}
