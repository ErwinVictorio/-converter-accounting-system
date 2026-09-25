<?php

namespace Tests\Feature;

use App\Models\SalesVatInput;
use App\Models\User;
use App\Rules\NonWhitespaceLength;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterDataCharacterCountTest extends TestCase
{
    use RefreshDatabase;

    public static function endpoints(): array
    {
        return [['customers', 'post'], ['customers', 'put'], ['suppliers', 'post'], ['suppliers', 'put']];
    }

    #[DataProvider('endpoints')]
    public function test_create_and_update_count_non_space_characters(string $table, string $method): void
    {
        $this->actingAs(User::factory()->create());
        $payload = ['tin' => '123-456-789-000', 'name' => 'INITIAL NAME', 'addr' => 'ADDRESS', 'city' => 'CITY'];
        $url = '/'.$table;
        if ($method === 'put') {
            $this->post($url, $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
            $url .= '/'.DB::table($table)->value('id');
        }

        $payload['name'] = implode(' ', array_fill(0, 50, 'A'));
        $payload['addr'] = implode(' ', array_fill(0, 30, 'B'));
        $payload['city'] = implode(' ', array_fill(0, 30, 'C'));
        if ($table === 'customers') {
            SalesVatInput::create(['customer_name' => $payload['name'], 'reporting_period' => '2026-09-01']);
        }
        $this->{$method}($url, $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas($table, $payload);
        if ($table === 'customers') {
            $this->assertDatabaseHas('sales_vatsinputs', [
                'company_name' => $payload['name'], 'address1' => $payload['addr'], 'address2' => $payload['city'],
            ]);
        }

        foreach (['name', 'addr', 'city'] as $field) {
            $invalid = $payload;
            $invalid[$field] .= 'Z';
            $this->{$method}($url, $invalid)->assertSessionHasErrors($field);
            $invalid[$field] = ['invalid type'];
            $this->{$method}($url, $invalid)->assertSessionHasErrors($field);
            $invalid[$field] = " \t\n\u{00A0}\u{2003}";
            $this->{$method}($url, $invalid)->assertSessionHasErrors($field);
        }
        $this->assertSame(1, DB::table($table)->count());
        $this->assertDatabaseHas($table, $payload);
    }

    public function test_unicode_whitespace_and_storage_guards(): void
    {
        foreach (['A B', "A\t\nB", "A\u{00A0}B", "A\u{0085}B", "A\u{3000}B", 'É😀', 'A-'] as $value) {
            $this->assertTrue(Validator::make(['name' => $value], ['name' => [new NonWhitespaceLength(2, 100)]])->passes(), $value);
            $this->assertFalse(Validator::make(['name' => $value.'Z'], ['name' => [new NonWhitespaceLength(2, 100)]])->passes());
        }
        $this->assertFalse(Validator::make(['name' => 'A'.str_repeat(' ', 300)], ['name' => [new NonWhitespaceLength(50, 300)]])->passes());
    }
}
