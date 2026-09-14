<?php

namespace Tests\Feature;

use App\Models\Brokers;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WithholdingCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MasterDataMissingAddressCityFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_customer_address_status_filters_classify_empty_and_whitespace_values(): void
    {
        $this->customer('ALPHA COMPLETE', '111-111-111-000', 'ADDRESS', 'CITY');
        $this->customer('BRAVO NO ADDRESS', '222-222-222-000', '', 'CITY');
        $this->customer('CHARLIE NO CITY', '333-333-333-000', 'ADDRESS', '');
        $this->customer('DELTA NO BOTH', '444-444-444-000', '', '');
        $this->customer('ECHO WHITESPACE ADDRESS', '555-555-555-000', '   ', 'CITY');
        $this->customer('FOXTROT LITERAL NA', '666-666-666-000', 'N/A', 'N/A');

        $this->assertSame([
            'BRAVO NO ADDRESS',
            'CHARLIE NO CITY',
            'DELTA NO BOTH',
            'ECHO WHITESPACE ADDRESS',
        ], $this->names('/customers?address_status=missing_any', 'customerList'));

        $this->assertSame([
            'BRAVO NO ADDRESS',
            'DELTA NO BOTH',
            'ECHO WHITESPACE ADDRESS',
        ], $this->names('/customers?address_status=missing_address', 'customerList'));

        $this->assertSame([
            'CHARLIE NO CITY',
            'DELTA NO BOTH',
        ], $this->names('/customers?address_status=missing_city', 'customerList'));

        $this->assertSame([
            'DELTA NO BOTH',
        ], $this->names('/customers?address_status=missing_both', 'customerList'));

        $this->assertSame([
            'ALPHA COMPLETE',
            'FOXTROT LITERAL NA',
        ], $this->names('/customers?address_status=complete', 'customerList'));
    }

    public function test_supplier_address_status_filters_classify_empty_and_whitespace_values(): void
    {
        $this->supplier('ALPHA COMPLETE', '111-111-111-000', 'ADDRESS', 'CITY');
        $this->supplier('BRAVO NO ADDRESS', '222-222-222-000', '', 'CITY');
        $this->supplier('CHARLIE NO CITY', '333-333-333-000', 'ADDRESS', '');
        $this->supplier('DELTA NO BOTH', '444-444-444-000', '', '');
        $this->supplier('ECHO WHITESPACE CITY', '555-555-555-000', 'ADDRESS', '   ');
        $this->supplier('FOXTROT LITERAL NA', '666-666-666-000', 'N/A', 'N/A');

        $this->assertSame([
            'BRAVO NO ADDRESS',
            'CHARLIE NO CITY',
            'DELTA NO BOTH',
            'ECHO WHITESPACE CITY',
        ], $this->names('/suppliers?address_status=missing_any', 'supplierList'));

        $this->assertSame([
            'BRAVO NO ADDRESS',
            'DELTA NO BOTH',
        ], $this->names('/suppliers?address_status=missing_address', 'supplierList'));

        $this->assertSame([
            'CHARLIE NO CITY',
            'DELTA NO BOTH',
            'ECHO WHITESPACE CITY',
        ], $this->names('/suppliers?address_status=missing_city', 'supplierList'));

        $this->assertSame([
            'DELTA NO BOTH',
        ], $this->names('/suppliers?address_status=missing_both', 'supplierList'));

        $this->assertSame([
            'ALPHA COMPLETE',
            'FOXTROT LITERAL NA',
        ], $this->names('/suppliers?address_status=complete', 'supplierList'));
    }

    public function test_broker_filters_find_missing_tins_and_combine_with_name_search(): void
    {
        Brokers::create(['broker_name' => 'ALPHA COMPLETE', 'tin_number' => '111-111-111-000']);
        Brokers::create(['broker_name' => 'BRAVO NO TIN', 'tin_number' => '']);
        Brokers::create(['broker_name' => 'CHARLIE WHITESPACE TIN', 'tin_number' => '   ']);
        Brokers::create(['broker_name' => 'DELTA LITERAL NA', 'tin_number' => 'N/A']);

        $this->assertSame([
            'BRAVO NO TIN',
            'CHARLIE WHITESPACE TIN',
        ], $this->names('/brokers?information_status=missing_tin', 'brokerList', 'broker_name'));

        $this->assertSame([
            'ALPHA COMPLETE',
            'DELTA LITERAL NA',
        ], $this->names('/brokers?information_status=complete', 'brokerList', 'broker_name'));

        $props = $this->props('/brokers?name=BRAVO&information_status=missing_tin');

        $this->assertSame(['BRAVO NO TIN'], data_get($props, 'brokerList.*.broker_name'));
        $this->assertSame('missing_tin', data_get($props, 'filters.information_status'));
    }

    public function test_company_address_status_filters_use_address_one_and_address_two(): void
    {
        $this->company('ALPHA COMPLETE', '111111111', 'ADDRESS', 'CITY');
        $this->company('BRAVO NO ADDRESS', '222222222', '', 'CITY');
        $this->company('CHARLIE NO CITY', '333333333', 'ADDRESS', '');
        $this->company('DELTA NO BOTH', '444444444', '', '');
        $this->company('ECHO WHITESPACE CITY', '555555555', 'ADDRESS', '   ');
        $this->company('FOXTROT LITERAL NA', '666666666', 'N/A', 'N/A');

        $this->assertSame([
            'BRAVO NO ADDRESS',
            'CHARLIE NO CITY',
            'DELTA NO BOTH',
            'ECHO WHITESPACE CITY',
        ], $this->names('/withholding-companies?address_status=missing_any', 'companies', 'registered_name'));

        $this->assertSame([
            'DELTA NO BOTH',
        ], $this->names('/withholding-companies?address_status=missing_both', 'companies', 'registered_name'));

        $this->assertSame([
            'ALPHA COMPLETE',
            'FOXTROT LITERAL NA',
        ], $this->names('/withholding-companies?address_status=complete', 'companies', 'registered_name'));

        $props = $this->props('/withholding-companies?search=CHARLIE&address_status=missing_city');

        $this->assertSame(['CHARLIE NO CITY'], data_get($props, 'companies.data.*.registered_name'));
        $this->assertSame('missing_city', data_get($props, 'filters.address_status'));
    }

    public function test_address_status_combines_with_existing_filters_and_invalid_values_fall_back_to_all(): void
    {
        $this->customer('ALPHA TARGET', '111-111-111-000', 'ADDRESS', '');
        $this->customer('BRAVO TARGET', '222-222-222-000', 'ADDRESS', '');
        $this->customer('CHARLIE COMPLETE', '333-333-333-000', 'ADDRESS', 'CITY');

        $props = $this->props('/customers?name=BRAVO&tin=222&address_status=missing_city');

        $this->assertSame(['BRAVO TARGET'], data_get($props, 'customerList.data.*.name'));
        $this->assertSame('BRAVO', data_get($props, 'filters.name'));
        $this->assertSame('222', data_get($props, 'filters.tin'));
        $this->assertSame('missing_city', data_get($props, 'filters.address_status'));

        $invalidProps = $this->props('/customers?address_status=not-supported');

        $this->assertSame(3, data_get($invalidProps, 'customerList.total'));
        $this->assertSame('all', data_get($invalidProps, 'filters.address_status'));
    }

    public function test_pagination_links_preserve_address_status_tin_and_name_filters(): void
    {
        foreach (range(1, 11) as $index) {
            $this->supplier(
                sprintf('FILTERED SUPPLIER %02d', $index),
                sprintf('%03d-111-111-000', $index),
                'ADDRESS',
                ''
            );
        }

        $props = $this->props('/suppliers?tin=111&name=FILTERED&address_status=missing_city');
        $nextPageUrl = data_get($props, 'supplierList.next_page_url');

        $this->assertNotNull($nextPageUrl);
        parse_str((string) parse_url($nextPageUrl, PHP_URL_QUERY), $query);

        $this->assertSame('111', $query['tin'] ?? null);
        $this->assertSame('FILTERED', $query['name'] ?? null);
        $this->assertSame('missing_city', $query['address_status'] ?? null);
        $this->assertSame('2', $query['page'] ?? null);
    }

    private function names(string $url, string $listProp, string $nameField = 'name'): array
    {
        $list = data_get($this->props($url), $listProp, []);
        $rows = array_key_exists('data', $list) ? $list['data'] : $list;

        return array_column($rows, $nameField);
    }

    private function props(string $url): array
    {
        $props = [];

        $this->get($url)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function customer(string $name, string $tin, string $address, string $city): Customer
    {
        return Customer::create([
            'name' => $name,
            'name_key' => Customer::normalizeName($name),
            'tin' => $tin,
            'addr' => $address,
            'city' => $city,
        ]);
    }

    private function supplier(string $name, string $tin, string $address, string $city): Supplier
    {
        return Supplier::create([
            'name' => $name,
            'tin' => $tin,
            'addr' => $address,
            'city' => $city,
        ]);
    }

    private function company(string $name, string $tin, string $address, string $city): WithholdingCompany
    {
        return WithholdingCompany::create([
            'registered_name' => $name,
            'tin' => $tin,
            'branch_code' => '0000',
            'rdo_code' => '049',
            'address1' => $address,
            'address2' => $city,
            'is_active' => true,
        ]);
    }
}
