<?php

namespace Tests\Feature;

use App\Models\Brokers;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTinUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_supplier_create_rejects_duplicate_base_tin(): void
    {
        Supplier::create([
            'tin' => '123-456-789-000',
            'name' => 'FIRST SUPPLIER',
            'addr' => 'ADDRESS ONE',
            'city' => 'CITY ONE',
        ]);

        $this->post('/suppliers', [
            'tin' => '123456789999',
            'name' => 'SECOND SUPPLIER',
            'addr' => 'ADDRESS TWO',
            'city' => 'CITY TWO',
        ])->assertSessionHasErrors(['tin' => 'TIN already exists for another supplier.']);

        $this->assertSame(1, Supplier::count());
    }

    public function test_supplier_update_rejects_duplicate_base_tin_but_allows_own_tin(): void
    {
        $supplier = Supplier::create([
            'tin' => '123-456-789-000',
            'name' => 'FIRST SUPPLIER',
            'addr' => 'ADDRESS ONE',
            'city' => 'CITY ONE',
        ]);
        Supplier::create([
            'tin' => '222-333-444-000',
            'name' => 'SECOND SUPPLIER',
            'addr' => 'ADDRESS TWO',
            'city' => 'CITY TWO',
        ]);

        $this->put('/suppliers/' . $supplier->id, [
            'tin' => '123456789',
            'name' => 'FIRST SUPPLIER UPDATED',
            'addr' => 'ADDRESS ONE',
            'city' => 'CITY ONE',
        ])->assertSessionHasNoErrors();

        $this->put('/suppliers/' . $supplier->id, [
            'tin' => '222-333-444-999',
            'name' => 'FIRST SUPPLIER UPDATED',
            'addr' => 'ADDRESS ONE',
            'city' => 'CITY ONE',
        ])->assertSessionHasErrors(['tin' => 'TIN already exists for another supplier.']);
    }

    public function test_customer_create_and_update_reject_duplicate_base_tin(): void
    {
        $customer = Customer::create([
            'tin' => '123-456-789-000',
            'name' => 'FIRST CUSTOMER',
            'name_key' => Customer::normalizeName('FIRST CUSTOMER'),
            'addr' => 'ADDRESS ONE',
            'city' => 'CITY ONE',
        ]);
        Customer::create([
            'tin' => '222-333-444-000',
            'name' => 'SECOND CUSTOMER',
            'name_key' => Customer::normalizeName('SECOND CUSTOMER'),
            'addr' => 'ADDRESS TWO',
            'city' => 'CITY TWO',
        ]);

        $this->post('/customers', [
            'tin' => '123456789999',
            'name' => 'THIRD CUSTOMER',
            'addr' => 'ADDRESS THREE',
            'city' => 'CITY THREE',
        ])->assertSessionHasErrors(['tin' => 'TIN already exists for another customer.']);

        $this->put('/customers/' . $customer->id, [
            'tin' => '222-333-444-999',
            'name' => 'FIRST CUSTOMER',
            'addr' => 'ADDRESS ONE',
            'city' => 'CITY ONE',
        ])->assertSessionHasErrors(['tin' => 'TIN already exists for another customer.']);

        $this->assertSame(2, Customer::count());
    }

    public function test_broker_create_and_update_reject_duplicate_base_tin(): void
    {
        $broker = Brokers::create([
            'broker_name' => 'FIRST BROKER',
            'tin_number' => '123-456-789-000',
        ]);
        Brokers::create([
            'broker_name' => 'SECOND BROKER',
            'tin_number' => '222-333-444-000',
        ]);

        $this->post('/create', [
            'tin' => '123456789999',
            'broker_name' => 'THIRD BROKER',
        ])->assertSessionHasErrors(['tin' => 'TIN already exists for another broker.']);

        $this->put('/brokers/' . $broker->id, [
            'tin' => '222-333-444-999',
            'broker_name' => 'FIRST BROKER',
        ])->assertSessionHasErrors(['tin' => 'TIN already exists for another broker.']);

        $this->assertSame(2, Brokers::count());
    }

    public function test_zero_base_tin_is_rejected_for_master_data_entries(): void
    {
        $this->post('/suppliers', [
            'tin' => '000-000-000-000',
            'name' => 'ZERO SUPPLIER',
            'addr' => 'ADDRESS',
            'city' => 'CITY',
        ])->assertSessionHasErrors(['tin' => 'Supplier TIN must contain a valid first 9 digits and cannot be 000000000.']);

        $this->post('/customers', [
            'tin' => '000-000-000-000',
            'name' => 'ZERO CUSTOMER',
            'addr' => 'ADDRESS',
            'city' => 'CITY',
        ])->assertSessionHasErrors(['tin' => 'Customer TIN must contain a valid first 9 digits and cannot be 000000000.']);

        $this->post('/create', [
            'tin' => '000-000-000-000',
            'broker_name' => 'ZERO BROKER',
        ])->assertSessionHasErrors(['tin' => 'Broker TIN must contain a valid first 9 digits and cannot be 000000000.']);

        $this->post('/withholding-companies', [
            'tin' => '000-000-000',
            'branch_code' => '0000',
            'registered_name' => 'ZERO COMPANY',
            'trade_name' => '',
            'rdo_code' => '049',
            'address1' => 'ADDRESS',
            'address2' => 'CITY',
            'is_active' => true,
        ])->assertSessionHasErrors(['tin' => 'Company TIN must contain a valid first 9 digits and cannot be 000000000.']);
    }
}
