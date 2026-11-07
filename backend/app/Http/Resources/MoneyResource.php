<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoneyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'minor_units' => (string) $this->resource->minorUnits,
            'currency' => $this->resource->currency,
            'currency_exponent' => $this->resource->currencyExponent,
        ];
    }
}
