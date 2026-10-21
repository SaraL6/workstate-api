<?php

namespace App\Services;

use App\Exceptions\ContractParseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OpenAiContractParser
{
    /**
     * The contract text is sent only as the user message. The system message
     * stays fixed, and the JSON is rejected unless it matches the schema below.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You extract working-time terms from an employment contract. Respond with a JSON object and no other text.
Use exactly these keys:
- max_weekly_hours: number. The maximum weekly working hours stated in the contract.
- allowed_overtime_cap: number. Extra overtime hours allowed per week beyond the agreed hours. Use 0 when overtime is forbidden or not mentioned.
- custom_rest_period_hours: number. The minimum uninterrupted rest between shifts. Use 11 when the contract is silent.
- remote_work_country: string. ISO 3166-1 alpha-2 country code where remote work is permitted. Use "DE" when the contract is silent and German law governs it.
Choose the most restrictive figure when the contract states more than one. Do not add keys.
PROMPT;

    /**
     * @return array{max_weekly_hours: float, allowed_overtime_cap: float, custom_rest_period_hours: float, remote_work_country: string}
     */
    public function parse(string $contractText): array
    {
        $key = (string) config('services.openai.key');
        $url = (string) config('services.openai.url');

        if ($key === '' || $url === '') {
            throw new ContractParseException('The contract parser is not configured.', 503);
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->withOptions(['allow_redirects' => false])
                ->post($url, [
                    'model' => (string) config('services.openai.model'),
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => $contractText],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('OpenAI contract parse connection failed.', [
                'exception' => $exception::class,
            ]);

            throw new ContractParseException('The contract parser is unavailable.', 503);
        }

        if ($response->failed()) {
            Log::warning('OpenAI contract parse request failed.', [
                'status' => $response->status(),
            ]);

            throw new ContractParseException('The contract parser is unavailable.', 502);
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new ContractParseException('The contract parser returned an unreadable response.', 502);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ContractParseException('The contract parser returned an unreadable response.', 502);
        }

        return $this->validate($decoded);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array{max_weekly_hours: float, allowed_overtime_cap: float, custom_rest_period_hours: float, remote_work_country: string}
     */
    private function validate(array $payload): array
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
