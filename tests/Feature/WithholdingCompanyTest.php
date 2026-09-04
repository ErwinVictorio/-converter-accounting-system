<?php

namespace Tests\Feature;

use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use App\Models\WithholdingCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settings > Manage Companies, and the two Known Company dropdowns it feeds.
 *
 * The point of the module is that the withholding agent an Expanded WTAX month is
 * filed under stops being hard-coded in config/bir.php. So the tests here are as
 * much about the seam as about the CRUD: a company added on this screen must show
 * up in both dropdowns, must reach the 1601EQ header, and -- once a month has been
 * filed under it -- must stop being editable or deletable without that month
 * becoming unregenerable.
 *
 * Nothing here asserts a DAT layout; ExpandedWtaxDatFileTest and
 * ReliefExpandedWtaxDatGeneratorTest own that.
 */
class WithholdingCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function company(array $overrides = []): WithholdingCompany
    {
        return WithholdingCompany::create(array_merge([
            'tin' => '123456789',
            'branch_code' => '0000',
            'registered_name' => 'OTHER COMPANY INC',
            'rdo_code' => '049',
            'address1' => 'UNIT 5 SOME BUILDING',
            'address2' => 'QUEZON CITY',
            'is_active' => true,
        ], $overrides));
    }

    /** The payload the add/edit form posts. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tin' => '123456789',
            'branch_code' => '0000',
            'registered_name' => 'OTHER COMPANY INC',
            'trade_name' => 'OtherCo',
            'rdo_code' => '049',
            'address1' => 'UNIT 5 SOME BUILDING',
            'address2' => 'QUEZON CITY',
            'is_active' => true,
        ], $overrides);
    }

    private function expandedEntry(array $overrides = []): ExpandedWtaxEntry
    {
        return ExpandedWtaxEntry::create(array_merge([
            'reporting_period' => '2026-07-31',
            'withholding_agent_tin' => '123456789',
            'withholding_agent_branch_code' => '0000',
            'withholding_agent_name' => 'OTHER COMPANY INC',
            'payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'payee_type' => 'company',
            'payee_tin' => '007-086-184-000',
            'payee_branch_code' => '0000',
            'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 100000.00,
            'tax_withheld' => 1000.00,
        ], $overrides));
    }

    public function test_it_lists_companies(): void
    {
        $this->company();
        $this->company([
            'tin' => '987654321',
            'registered_name' => 'ZEBRA TRADING CORP',
            'is_active' => false,
        ]);

        $this->get('/withholding-companies')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('WithholdingCompanies')
                ->has('companies.data', 2)
                // Active first, then by name -- the list reads like the dropdown.
                ->where('companies.data.0.registered_name', 'OTHER COMPANY INC')
                ->where('companies.data.0.is_active', true)
                ->where('companies.data.1.registered_name', 'ZEBRA TRADING CORP')
                ->where('companies.data.1.is_active', false)
        );
    }

    public function test_it_searches_by_name_and_by_tin(): void
    {
        $this->company();
        $this->company(['tin' => '987654321', 'registered_name' => 'ZEBRA TRADING CORP']);

        $this->get('/withholding-companies?search=zebra')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('companies.data', 1)
                ->where('companies.data.0.tin', '987654321')
        );

        // Typed with dashes: the search strips them the way the model does.
        $this->get('/withholding-companies?search=123-456-789')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('companies.data', 1)
                ->where('companies.data.0.tin', '123456789')
        );
    }

    public function test_it_adds_a_company(): void
    {
        $this->post('/withholding-companies', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('withholding_companies', [
            'tin' => '123456789',
            'branch_code' => '0000',
            'registered_name' => 'OTHER COMPANY INC',
            'rdo_code' => '049',
            'is_active' => true,
        ]);
    }

    public function test_it_normalises_the_tin_to_nine_digits(): void
    {
        $this->post('/withholding-companies', $this->payload([
            'tin' => '123-456-789-000',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('withholding_companies', ['tin' => '123456789']);
    }

    public function test_it_pads_the_branch_code_to_four_digits(): void
    {
        $this->post('/withholding-companies', $this->payload([
            'branch_code' => '2',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('withholding_companies', [
            'tin' => '123456789',
            'branch_code' => '0002',
        ]);
    }

    public function test_it_rejects_a_duplicate_tin_and_branch(): void
    {
        $this->company();

        // Same company typed with dashes: it must still collide.
        $this->post('/withholding-companies', $this->payload([
            'tin' => '123-456-789',
        ]))->assertSessionHasErrors('tin');

        $this->assertSame(1, WithholdingCompany::count());
    }

    public function test_the_same_tin_at_a_different_branch_is_rejected(): void
    {
        $this->company();

        $this->post('/withholding-companies', $this->payload([
            'branch_code' => '0002',
        ]))->assertSessionHasErrors(['tin' => 'TIN already exists for another company.']);

        $this->assertSame(1, WithholdingCompany::count());
    }

    public function test_it_updates_a_company(): void
    {
        $company = $this->company();

        $this->put('/withholding-companies/' . $company->id, $this->payload([
            'registered_name' => 'OTHER COMPANY INCORPORATED',
            'rdo_code' => '050',
        ]))->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('withholding_companies', [
            'id' => $company->id,
            'registered_name' => 'OTHER COMPANY INCORPORATED',
            'rdo_code' => '050',
        ]);
    }

    public function test_it_refuses_to_change_the_tin_once_a_month_has_been_filed_under_it(): void
    {
        $company = $this->company();
        $this->expandedEntry();

        $this->put('/withholding-companies/' . $company->id, $this->payload([
            'tin' => '111222333',
        ]))->assertSessionHasErrors('tin');

        $this->assertDatabaseHas('withholding_companies', [
            'id' => $company->id,
            'tin' => '123456789',
        ]);

        // The rest of the record is still editable -- only the identity is frozen.
        $this->put('/withholding-companies/' . $company->id, $this->payload([
            'registered_name' => 'OTHER COMPANY INCORPORATED',
        ]))->assertSessionHasNoErrors();
    }

    public function test_it_flags_a_company_that_already_has_filed_rows(): void
    {
        $this->company();
        $this->company(['tin' => '987654321', 'registered_name' => 'ZEBRA TRADING CORP']);
        $this->expandedEntry();

        $this->get('/withholding-companies')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('companies.data.0.has_filed_rows', true)
                ->where('companies.data.1.has_filed_rows', false)
        );
    }

    public function test_it_deactivates_and_reactivates_a_company(): void
    {
        $company = $this->company();

        $this->patch('/withholding-companies/' . $company->id . '/deactivate')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($company->fresh()->is_active);

        $this->patch('/withholding-companies/' . $company->id . '/activate')
            ->assertRedirect();

        $this->assertTrue($company->fresh()->is_active);
    }

    public function test_it_deletes_a_company_that_was_never_filed_under(): void
    {
        $company = $this->company();

        $this->delete('/withholding-companies/' . $company->id)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('withholding_companies', ['id' => $company->id]);
    }

    public function test_it_refuses_to_delete_a_company_that_has_filed_rows(): void
    {
        $company = $this->company();
        $this->expandedEntry();

        $this->delete('/withholding-companies/' . $company->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('withholding_companies', ['id' => $company->id]);
    }

    public function test_the_upload_page_receives_managed_companies(): void
    {
        $this->company();

        $this->get('/records')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('birCompanies.0.tin', '123456789')
                ->where('birCompanies.0.branch_code', '0000')
                ->where('birCompanies.0.name', 'OTHER COMPANY INC')
                ->where('birCompanies.0.rdo_code', '049')
        );
    }

    public function test_the_generate_page_receives_managed_companies(): void
    {
        $this->company();

        $this->get('/generate-datfile?record_type=expanded')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('birCompanies.0.tin', '123456789')
                ->where('birCompanies.0.name', 'OTHER COMPANY INC')
                ->where('selectedWithholdingAgent.tin', '123456789')
                ->where('selectedWithholdingAgent.branch_code', '0000')
        );
    }

    public function test_an_inactive_company_is_not_offered_in_the_dropdowns(): void
    {
        $this->company(['is_active' => false]);

        foreach (['/records', '/generate-datfile?record_type=expanded'] as $url) {
            $companies = $this->get($url)->assertOk()->viewData('page')['props']['birCompanies'];

            $this->assertNotContains(
                '123456789',
                array_column($companies, 'tin'),
                "Deactivated company still offered on {$url}"
            );
        }
    }

    /**
     * config/bir.php stays the fallback while the table is empty, which is what
     * keeps a fresh install (and every existing Expanded WTAX test) working.
     */
    public function test_the_config_company_is_offered_when_the_table_is_empty(): void
    {
        $this->assertSame(0, WithholdingCompany::count());

        $this->get('/records')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('birCompanies.0.tin', '008791976')
                ->where('birCompanies.0.branch_code', '0000')
                ->where('birCompanies.0.rdo_code', '045')
        );
    }

    public function test_the_generate_page_lists_periods_for_the_selected_company_only(): void
    {
        $this->company();
        $this->company(['tin' => '987654321', 'registered_name' => 'ZEBRA TRADING CORP']);

        $this->expandedEntry();
        $this->expandedEntry([
            'reporting_period' => '2026-06-30',
            'withholding_agent_tin' => '987654321',
            'withholding_agent_name' => 'ZEBRA TRADING CORP',
        ]);

        $this->get('/generate-datfile?record_type=expanded&withholding_agent_tin=123456789&withholding_agent_branch_code=0000')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('availablePeriods', 1)
                    ->where('availablePeriods.0.value', '2026-07')
            );

        $this->get('/generate-datfile?record_type=expanded&withholding_agent_tin=987654321&withholding_agent_branch_code=0000')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('availablePeriods', 1)
                    ->where('availablePeriods.0.value', '2026-06')
            );
    }

    /**
     * The header and the filename are built from the managed company, not config --
     * this is the whole point of the module. The layout itself is asserted in
     * ExpandedWtaxDatFileTest; here only the company fields matter.
     */
    public function test_the_dat_header_uses_the_managed_company_details(): void
    {
        $this->company();
        $this->expandedEntry();

        $response = $this->get(
            '/download-datfile?period=2026-07-31&record_type=expanded&withholding_agent_tin=123456789&withholding_agent_branch_code=0000'
        );

        $response->assertOk()->assertHeader(
            'content-disposition',
            'attachment; filename="12345678900000720261601EQ.DAT"'
        );

        $header = explode("\r\n", $response->getContent())[0];

        $this->assertSame('HQAP,H1601EQ,123456789,0000,"OTHER COMPANY INC",07/2026,049', $header);
    }

    /**
     * Deactivating must hide a company from the dropdown without stranding a month
     * already filed under it -- the reason companyForDat() looks past is_active.
     */
    public function test_a_deactivated_company_can_still_regenerate_a_filed_month(): void
    {
        $company = $this->company();
        $this->expandedEntry();

        $company->update(['is_active' => false]);

        $response = $this->get(
            '/download-datfile?period=2026-07-31&record_type=expanded&withholding_agent_tin=123456789&withholding_agent_branch_code=0000'
        );

        $response->assertOk();

        $this->assertSame(
            'HQAP,H1601EQ,123456789,0000,"OTHER COMPANY INC",07/2026,049',
            explode("\r\n", $response->getContent())[0]
        );
    }

    public function test_a_renamed_company_is_used_over_the_name_stored_on_the_uploaded_rows(): void
    {
        $company = $this->company();
        $this->expandedEntry();

        $company->update(['registered_name' => 'OTHER COMPANY INCORPORATED']);

        $response = $this->get(
            '/download-datfile?period=2026-07-31&record_type=expanded&withholding_agent_tin=123456789&withholding_agent_branch_code=0000'
        );

        $this->assertStringContainsString(
            '"OTHER COMPANY INCORPORATED"',
            explode("\r\n", $response->getContent())[0]
        );
    }

    public function test_validation_rejects_a_short_tin_a_bad_branch_and_a_bad_rdo(): void
    {
        $this->post('/withholding-companies', $this->payload([
            'tin' => '12345',
            'rdo_code' => '12345',
            'registered_name' => '',
        ]))->assertSessionHasErrors(['tin', 'rdo_code', 'registered_name']);

        $this->assertSame(0, WithholdingCompany::count());
    }

    public function test_the_module_is_behind_auth(): void
    {
        Auth::logout();

        $this->get('/withholding-companies')->assertRedirect('/login');
        $this->post('/withholding-companies', $this->payload())->assertRedirect('/login');
    }
}
