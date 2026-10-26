<?php

namespace App\Services;

use App\Exceptions\ContractParseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ParsedContractValidator
{
    /**
     * @param  array<mixed>  $payload
     * @return array{max_weekly_hours: float, allowed_overtime_cap: float, custom_rest_period_hours: float, remote_work_country: string}
     */
    public function validate(array $payload): array
    {
        $allowed = [
            'max_weekly_hours',
            'allowed_overtime_cap',
            'custom_rest_period_hours',
            'remote_work_country',
        ];
        $unknown = array_diff(array_map('strval', array_keys($payload)), $allowed);

        if ($unknown !== []) {
            throw new ContractParseException(
                'The model response did not match the contract rule schema.',
                422,
                ['response' => ['The response contained unexpected fields.']],
            );
        }

        if (isset($payload['remote_work_country']) && is_string($payload['remote_work_country'])) {
            $payload['remote_work_country'] = strtoupper($payload['remote_work_country']);
        }

        $validator = Validator::make($payload, [
            'max_weekly_hours' => ['required', 'numeric', 'between:1,80'],
            'allowed_overtime_cap' => ['required', 'numeric', 'between:0,40'],
            'custom_rest_period_hours' => ['required', 'numeric', 'between:11,24'],
            'remote_work_country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
        ]);

        try {
            $validated = $validator->validate();
        } catch (ValidationException $exception) {
            Log::warning('OpenAI contract parse failed schema validation.', [
                'fields' => array_keys($exception->errors()),
            ]);

            throw new ContractParseException(
                'The model response did not match the contract rule schema.',
                422,
                $exception->errors(),
            );
        }

        return [
            'max_weekly_hours' => round((float) $validated['max_weekly_hours'], 2),
            'allowed_overtime_cap' => round((float) $validated['allowed_overtime_cap'], 2),
            'custom_rest_period_hours' => round((float) $validated['custom_rest_period_hours'], 2),
            'remote_work_country' => $validated['remote_work_country'],
        ];
    }
}
