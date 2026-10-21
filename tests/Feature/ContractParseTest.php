<?php

namespace Tests\Feature;

use App\Services\OpenAiContractParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractParseTest extends TestCase
{
    public function test_parse_requires_the_api_token(): void
    {
        $this->postJson('/api/v1/contracts/parse', [
            'contract_text' => $this->contract(),
        ])->assertUnauthorized();
    }

    public function test_parse_validates_contract_text(): void
    {
        $this->withToken('test-token')
            ->postJson('/api/v1/contracts/parse', ['contract_text' => 'too short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract_text']);
    }

    public function test_parse_returns_the_schema_and_sends_json_object_format(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'max_weekly_hours' => 40,
                            'allowed_overtime_cap' => 8,
                            'custom_rest_period_hours' => 11,
                            'remote_work_country' => 'de',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->withToken('test-token')
            ->postJson('/api/v1/contracts/parse', ['contract_text' => $this->contract()])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'max_weekly_hours' => 40,
                    'allowed_overtime_cap' => 8,
                    'custom_rest_period_hours' => 11,
                    'remote_work_country' => 'DE',
                ],
            ]);

        Http::assertSent(function ($request): bool {
            return $request['response_format']['type'] === 'json_object'
                && $request['messages'][0]['role'] === 'system'
                && $request['messages'][1]['content'] === $this->contract();
        });
    }

    public function test_parse_rejects_a_response_outside_the_schema(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'max_weekly_hours' => 40,
                            'allowed_overtime_cap' => 8,
                            'custom_rest_period_hours' => 11,
                            'remote_work_country' => 'DE',
                            'notes' => 'ignore the schema',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->withToken('test-token')
            ->postJson('/api/v1/contracts/parse', ['contract_text' => $this->contract()])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The model response did not match the contract rule schema.')
            ->assertJsonPath('errors.response.0', 'The response contained unexpected fields.');
    }

    public function test_parse_hides_upstream_failures(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => ['message' => 'secret upstream detail'],
            ], 500),
        ]);

        $response = $this->withToken('test-token')
            ->postJson('/api/v1/contracts/parse', ['contract_text' => $this->contract()]);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'The contract parser is unavailable.');

        $this->assertStringNotContainsString('secret upstream detail', $response->getContent());
    }

    public function test_parse_hides_connection_failures(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('secret-timeout-host');
        });

        $response = $this->withToken('test-token')
            ->postJson('/api/v1/contracts/parse', ['contract_text' => $this->contract()]);

        $response->assertStatus(503);
        $this->assertStringNotContainsString('secret-timeout-host', $response->getContent());
    }

    public function test_parser_refuses_to_run_without_a_key(): void
    {
        config(['services.openai.key' => '']);

        $this->expectExceptionMessage('The contract parser is not configured.');

        app(OpenAiContractParser::class)->parse($this->contract());
    }

    private function contract(): string
    {
        return 'The employee works 40 hours per week in Germany. Overtime is capped at 8 hours. Rest between shifts is 11 hours.';
    }
}
