<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParsedContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'max_weekly_hours' => $this->resource['max_weekly_hours'],
            'allowed_overtime_cap' => $this->resource['allowed_overtime_cap'],
            'custom_rest_period_hours' => $this->resource['custom_rest_period_hours'],
            'remote_work_country' => $this->resource['remote_work_country'],
        ];
    }
}
