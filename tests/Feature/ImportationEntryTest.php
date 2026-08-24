<?php

namespace Tests\Feature;

use App\Models\ImportationEntry;
use App\Models\User;
use App\Models\VatInput;
use App\Services\BIR\BirPurchaseRowValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportationEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The whole app sits behind the "auth" middleware now.
        $this->actingAs(User::factory()->create());
    }

    /**
     * The form posts total_landed_cost; charges and taxable_goods are derived.
     * Landed 1,512,000 - dutiable 1,500,000 = 12,000 charges.
     * Landed 1,512,000 - exempt 0 = 1,512,000 taxable, at 12% = 181,440 VAT.
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tax_month' => '2026-04',
            'import_entry_no' => 'C-12345',
            'assessment_date' => '2026-04-10',
            'supplier' => 'Shenzhen Metals Co.',
            'importation_date' => '2026-04-05',
            'country' => 'China',
            'total_landed_cost' => '1512000.00',
            'dutiable_value' => '1500000.00',
            'exempt' => '0.00',
            'vat_rate' => '12.00',
            'vat_payable' => '181440.00',
            'or_number' => '987654',
            'payment_date' => '2026-04-12',
        ], $overrides);
    }

    public function test_it_creates_an_importation_entry(): void
    {
        $response = $this->post('/importation', $this->payload());

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $entry = ImportationEntry::firstOrFail();

        $this->assertSame('C-12345', $entry->import_entry_no);
        $this->assertSame('SHENZHEN METALS CO.', $entry->supplier); // BIR-safe text
        $this->assertSame('CHINA', $entry->country);
        $this->assertSame('2026-04-01', $entry->tax_month->toDateString());
        $this->assertSame(1, $entry->sequence_number);
        $this->assertEqualsWithDelta(1512000.00, (float) $entry->total_landed_cost, 0.001);
        // Derived from the landed cost, not keyed by the user.
        $this->assertEqualsWithDelta(12000.00, (float) $entry->charges, 0.001);
        $this->assertEqualsWithDelta(1512000.00, (float) $entry->taxable_goods, 0.001);
    }

    public function test_it_updates_an_importation_entry_and_reuses_the_synced_row(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();
        $entry = ImportationEntry::firstOrFail();

        $response = $this->put("/importation/{$entry->id}", $this->payload([
            'supplier' => 'Guangzhou Steel Ltd.',
            'total_landed_cost' => '2000000.00',
            'vat_payable' => '240000.00',
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('GUANGZHOU STEEL LTD.', $entry->supplier);
        $this->assertEqualsWithDelta(2000000.00, (float) $entry->taxable_goods, 0.001);
        $this->assertEqualsWithDelta(500000.00, (float) $entry->charges, 0.001);

        // Update must reuse the same vat_inputs row, not create a second one.
        $this->assertSame(1, VatInput::count());
        $this->assertEqualsWithDelta(240000.00, (float) $entry->vatInput->input_vat, 0.001);
        $this->assertEqualsWithDelta(2000000.00, (float) $entry->vatInput->capital_goods, 0.001);
    }

    public function test_it_rejects_duplicate_import_entry_no_for_same_tax_month(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();

        $response = $this->post('/importation', $this->payload([
            'supplier' => 'Another Supplier',
        ]));

        $response->assertSessionHasErrors('import_entry_no');
        $this->assertSame(1, ImportationEntry::count());

        // Same import entry no. in a different tax month is allowed.
        $this->post('/importation', $this->payload([
            'tax_month' => '2026-05',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, ImportationEntry::count());
    }

    public function test_it_rejects_inputs_that_would_derive_a_negative_amount(): void
    {
        // charges = total landed cost - dutiable value, so dutiable cannot exceed it.
        $this->post('/importation', $this->payload([
            'dutiable_value' => '1600000.00',
        ]))->assertSessionHasErrors('dutiable_value');

        // taxable goods = total landed cost - exempt, so exempt cannot exceed it either.
        $this->post('/importation', $this->payload([
            'exempt' => '1600000.00',
        ]))->assertSessionHasErrors('exempt');

        $this->assertSame(0, ImportationEntry::count());
    }

    public function test_saved_entry_syncs_to_vat_inputs_and_passes_the_purchase_dat_validator(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();

        $entry = ImportationEntry::firstOrFail();
        $vatInput = $entry->vatInput;

        $this->assertNotNull($vatInput);
        $this->assertTrue((bool) $vatInput->is_imported);
        $this->assertFalse((bool) $vatInput->is_adjusted);
        $this->assertSame('SHENZHEN METALS CO.', $vatInput->supplier_name);
        $this->assertSame('CHINA', $vatInput->address1); // country mapped into the address
        $this->assertEqualsWithDelta(1512000.00, (float) $vatInput->purchase_imported, 0.001);
        $this->assertEqualsWithDelta(1512000.00, (float) $vatInput->capital_goods, 0.001);
        $this->assertEqualsWithDelta(181440.00, (float) $vatInput->input_vat, 0.001);
        // date_uploaded is normalized to the end of the tax month for DAT filtering.
        $this->assertSame('2026-04-30', $vatInput->date_uploaded->toDateString());

        // The core goal: the synced row must clear the existing purchase DAT validator.
        $errors = app(BirPurchaseRowValidator::class)->validate($vatInput->toBirPurchaseRow(), 2);
        $this->assertSame([], $errors);
    }

    public function test_synced_entry_is_routed_to_the_importation_dat_not_the_purchase_dat(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();

        // The sync happened: the row exists for internal VAT input reporting.
        $this->assertSame(1, VatInput::count());

        // But importations belong in the "I" schedule only. Reporting them in the
        // "P" schedule as well would double-count the same input VAT at BIR.
        $purchase = $this->get('/download-datfile?period=2026-04-30&record_type=purchase');
        $purchase->assertRedirect();
        $purchase->assertSessionHas('error');

        $importation = $this->get('/download-datfile?period=2026-04-30&record_type=importation');

        $importation->assertOk();
        $importation->assertHeader('content-disposition', 'attachment; filename="008791976I042026.DAT"');

        $content = $importation->getContent();
        [$header, $detail] = explode("\r\n", trim($content));

        $this->assertCount(18, str_getcsv($header));
        $this->assertCount(16, str_getcsv($detail));

        $detailFields = str_getcsv($detail);
        $this->assertSame('C-12345', $detailFields[2]);
        $this->assertSame('SHENZHEN METALS CO.', $detailFields[4]);
        $this->assertSame('1512000.00', $detailFields[10]); // taxable goods
        $this->assertSame('181440.00', $detailFields[11]);  // VAT payable

        // Header totals include the importation amounts.
        $this->assertSame('181440.00', str_getcsv($header)[14]);
    }

    public function test_deleting_an_entry_removes_its_synced_dat_row(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();

        $entry = ImportationEntry::firstOrFail();
        $this->assertSame(1, VatInput::count());

        $this->delete("/importation/{$entry->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, ImportationEntry::count());
        $this->assertSame(0, VatInput::count());
    }
}
