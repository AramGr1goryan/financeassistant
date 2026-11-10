<?php

namespace Tests\Feature;

use App\Models\IncomeExpectation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncomeExpectationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_list_and_idempotently_complete_an_income_assumption(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = (string) Str::uuid();
        $payload = [
            'id' => $id,
            'description' => 'Expected salary',
            'amount_minor_units' => '9007199254740993',
            'expected_date' => '2025-03-08',
        ];

        $this->actingAs($owner)->postJson('/api/v1/income-expectations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.amount.minor_units', '9007199254740993')
            ->assertJsonPath('data.status', 'PLANNED');
        $this->postJson('/api/v1/income-expectations', $payload)->assertOk()->assertJsonPath('was_created', false);
        $this->getJson('/api/v1/income-expectations')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/v1/income-expectations')->assertJsonCount(0, 'data');

        $this->actingAs($owner)->postJson("/api/v1/income-expectations/{$id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->postJson("/api/v1/income-expectations/{$id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->actingAs($other)->postJson("/api/v1/income-expectations/{$id}/complete")->assertNotFound();
        $this->assertDatabaseCount('income_expectations', 1);
    }

    public function test_invalid_or_conflicting_expectation_inputs_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = [
            'id' => (string) Str::uuid(),
            'description' => 'Salary',
            'amount_minor_units' => '0',
            'expected_date' => '2025-03-08',
        ];
        $this->postJson('/api/v1/income-expectations', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('amount_minor_units');

        $payload['amount_minor_units'] = '1000';
        $payload['expected_date'] = '2025-02-30';
        $this->postJson('/api/v1/income-expectations', $payload)->assertUnprocessable();
        $payload['expected_date'] = '2025-03-08';
        $this->postJson('/api/v1/income-expectations', $payload)->assertCreated();
        $payload['description'] = 'Different description';
        $this->postJson('/api/v1/income-expectations', $payload)->assertStatus(409);
        $this->assertDatabaseCount('income_expectations', 1);
    }

    public function test_guest_cannot_read_or_write_income_expectations(): void
    {
        $this->getJson('/api/v1/income-expectations')->assertUnauthorized();
        $this->postJson('/api/v1/income-expectations', [])->assertUnauthorized();
        $this->postJson('/api/v1/income-expectations/'.Str::uuid().'/complete')->assertUnauthorized();
        $this->assertDatabaseCount('income_expectations', 0);
    }

    public function test_creation_and_completion_do_not_create_transactions(): void
    {
        $owner = User::factory()->create();
        $id = (string) Str::uuid();
        $this->actingAs($owner)->postJson('/api/v1/income-expectations', [
            'id' => $id,
            'description' => 'Assumed income',
            'amount_minor_units' => '30000',
            'expected_date' => '2025-03-08',
        ])->assertCreated();
        $this->postJson("/api/v1/income-expectations/{$id}/complete")->assertOk();

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(IncomeExpectation::query()->firstOrFail()->id, $id);
    }
}
