<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiStatusTest extends TestCase
{
    public function test_versioned_api_status_endpoint_returns_json(): void
    {
        $this->getJson('/api/v1/status')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
