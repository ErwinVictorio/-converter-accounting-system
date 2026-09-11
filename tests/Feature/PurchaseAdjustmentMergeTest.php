<?php

namespace Tests\Feature;

use App\Models\Brokers;
use App\Models\ImportationEntry;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a broker transfer lands.
 *
 * A broker row is split by moving amounts to the vendor that really earned them.
 * What these guard is the target that transfer merges into: the vendor's own
 * uploaded row when the month already has one, so the month keeps one row per
 * vendor instead of an uploaded row and an adjusted twin sitting side by side.
 *
 * The month, the imported bucket and the first nine TIN digits are what make two
 * rows the same vendor here; anything else is a different vendor and gets its own
 * row. VatInputController::transferTargetQuery() is the rule under test.
 */
class PurchaseAdjustmentMergeTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-04-30';

    private const BROKER_TIN = '111-111-111-000';

    private const VENDOR_TIN = '222-222-222-000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function purchase(array $overrides = []): VatInput
    {
        return VatInput::create(array_merge([
            'supplier_name' => 'LOCAL HARDWARE INC.',
            'tin_number' => self::VENDOR_TIN,
            'vendor_type' => 'company',
            'company_name' => 'LOCAL HARDWARE INC.',
            'address1' => 'UPLOADED STREET',
            'address2' => 'MAKATI CITY',
            'is_imported' => false,
            'exempt' => 0.00,
            'zero_rated' => 0.00,
            'purchase_imported' => 0.00,
            'purchase_local' => 5000.00,
            'services' => 0.00,
            'capital_goods' => 0.00,
            'other_than_capital_goods' => 5000.00,
            'taxable_net_of_vat' => 5000.00,
            'vat_rate' => 12.00,
            'input_vat' => 600.00,
            'total_purchases' => 5000.00,
            'others' => 0.00,
            'total' => 5000.00,
            'date_uploaded' => self::PERIOD,
            'is_broker' => false,
            'is_adjusted' => false,
        ], $overrides));
    }

    /**
     * The broker row being split, plus the brokers entry that makes the edit
     * screen accept it -- VatInputController::isBrokerRecord() matches on the
     * first nine TIN digits.
     */
    private function brokerRow(array $overrides = []): VatInput
    {
        Brokers::firstOrCreate([
            'tin_number' => self::BROKER_TIN,
        ], [
            'broker_name' => 'FAST LANE BROKERAGE INC.',
        ]);

        return $this->purchase(array_merge([
            'supplier_name' => 'FAST LANE BROKERAGE INC.',
            'tin_number' => self::BROKER_TIN,
            'company_name' => 'FAST LANE BROKERAGE INC.',
            'purchase_local' => 0.00,
            'services' => 1000.00,
            'other_than_capital_goods' => 0.00,
            'taxable_net_of_vat' => 1000.00,
            'input_vat' => 120.00,
            'total_purchases' => 1000.00,
            'total' => 1000.00,
            'is_broker' => true,
        ], $overrides));
    }

    /**
     * The adjust form as the edit screen submits it: the vendor the amounts belong
     * to, and how much of each bucket moves across.
     */
    private function transfer(VatInput $brokerRow, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->put("/records/{$brokerRow->id}", array_merge([
            'supplier_name' => 'LOCAL HARDWARE INC.',
            'tin_number' => self::VENDOR_TIN,
            'vendor_type' => 'company',
            'company_name' => 'LOCAL HARDWARE INC.',
            'address1' => 'TYPED STREET',
            'address2' => 'TYPED CITY',
            'is_imported' => false,
            'purchase_imported' => 0,
            'purchase_local' => 0,
            'services' => 400,
            'others' => 0,
        ], $overrides));
    }

    public function test_a_transfer_to_an_uploaded_vendor_row_adds_to_it_and_creates_no_adjusted_row(): void
    {
        $brokerRow = $this->brokerRow();
        $uploaded = $this->purchase();

        $this->transfer($brokerRow)->assertRedirect("/records/{$brokerRow->id}/edit");

        $this->assertSame(2, VatInput::count());
        $this->assertSame(0, VatInput::where('is_adjusted', true)->count());
        $this->assertSame('400.00', $uploaded->fresh()->services);
    }

    public function test_the_matched_uploaded_row_keeps_the_identity_it_was_uploaded_with(): void
    {
        $brokerRow = $this->brokerRow();
        $uploaded = $this->purchase();

        // A different name and address than the row on file carries.
        $this->transfer($brokerRow, [
            'supplier_name' => 'RETYPED HARDWARE',
            'company_name' => 'RETYPED HARDWARE',
        ])->assertRedirect("/records/{$brokerRow->id}/edit");

        $uploaded->refresh();

        $this->assertFalse($uploaded->is_adjusted);
        $this->assertSame('LOCAL HARDWARE INC.', $uploaded->supplier_name);
        $this->assertSame('LOCAL HARDWARE INC.', $uploaded->company_name);
        $this->assertSame('UPLOADED STREET', $uploaded->address1);
        $this->assertSame('MAKATI CITY', $uploaded->address2);
    }

    public function test_the_source_broker_row_is_reduced_by_the_transferred_amounts(): void
    {
        $brokerRow = $this->brokerRow([
            'purchase_local' => 2000.00,
            'services' => 1000.00,
            'others' => 500.00,
            'total' => 3500.00,
        ]);
        $this->purchase();

        $this->transfer($brokerRow, [
            'purchase_local' => 800,
            'services' => 400,
            'others' => 500,
        ])->assertRedirect("/records/{$brokerRow->id}/edit");

        $brokerRow->refresh();

        $this->assertSame('1200.00', $brokerRow->purchase_local);
        $this->assertSame('600.00', $brokerRow->services);
        $this->assertSame('0.00', $brokerRow->others);
        $this->assertSame('1800.00', $brokerRow->total);
        $this->assertTrue($brokerRow->is_broker);
    }

    public function test_the_matched_uploaded_row_recalculates_its_derived_totals(): void
    {
        $brokerRow = $this->brokerRow([
            'purchase_local' => 2000.00,
            'others' => 500.00,
            'total' => 3500.00,
        ]);
        $uploaded = $this->purchase();

        $this->transfer($brokerRow, [
            'purchase_local' => 1000,
            'services' => 400,
            'others' => 500,
        ])->assertRedirect("/records/{$brokerRow->id}/edit");

        $uploaded->refresh();

        // 6000 local + 400 services + 500 others.
        $this->assertSame('6000.00', $uploaded->purchase_local);
        $this->assertSame('400.00', $uploaded->services);
        $this->assertSame('500.00', $uploaded->others);
        $this->assertSame('6500.00', $uploaded->other_than_capital_goods);
        $this->assertSame('6900.00', $uploaded->taxable_net_of_vat);
        $this->assertSame('828.00', $uploaded->input_vat);
        $this->assertSame('6900.00', $uploaded->total_purchases);
        $this->assertSame('6900.00', $uploaded->total);
    }

    /**
     * An imported transfer has to reach capital_goods as well. The purchase DAT
     * sums that column, so an amount added to purchase_imported alone would be
     * missing from the return.
     */
    public function test_an_imported_transfer_lands_in_the_capital_goods_bucket(): void
    {
        $brokerRow = $this->brokerRow([
            'is_imported' => true,
            'purchase_imported' => 3000.00,
            'services' => 0.00,
            'capital_goods' => 3000.00,
            'taxable_net_of_vat' => 3000.00,
            'input_vat' => 360.00,
            'total' => 3000.00,
        ]);
        $uploaded = $this->purchase([
            'is_imported' => true,
            'purchase_imported' => 8000.00,
            'purchase_local' => 0.00,
            'capital_goods' => 8000.00,
            'other_than_capital_goods' => 0.00,
            'taxable_net_of_vat' => 8000.00,
            'input_vat' => 960.00,
            'total_purchases' => 8000.00,
            'total' => 8000.00,
        ]);

        $this->transfer($brokerRow, [
            'is_imported' => true,
            'purchase_imported' => 3000,
            'services' => 0,
        ])->assertRedirect("/records/{$brokerRow->id}/edit");

        $uploaded->refresh();

        $this->assertSame('11000.00', $uploaded->purchase_imported);
        $this->assertSame('11000.00', $uploaded->capital_goods);
        $this->assertSame('11000.00', $uploaded->taxable_net_of_vat);
        $this->assertSame('1320.00', $uploaded->input_vat);
        $this->assertSame('0.00', $brokerRow->fresh()->purchase_imported);
    }

    /**
     * Exempt and zero-rated purchases are outside the four transferable buckets,
     * so a merge must not quietly drop them out of the row total the dashboard
     * reads.
     */
    public function test_a_merge_keeps_the_uploaded_rows_exempt_and_zero_rated_amounts(): void
    {
        $brokerRow = $this->brokerRow();
        $uploaded = $this->purchase([
            'exempt' => 700.00,
            'zero_rated' => 300.00,
            'total_purchases' => 6000.00,
            'total' => 6000.00,
        ]);

        $this->transfer($brokerRow)->assertRedirect("/records/{$brokerRow->id}/edit");

        $uploaded->refresh();

        $this->assertSame('700.00', $uploaded->exempt);
        $this->assertSame('300.00', $uploaded->zero_rated);
        $this->assertSame('5400.00', $uploaded->taxable_net_of_vat);
        $this->assertSame('6400.00', $uploaded->total_purchases);
        $this->assertSame('6400.00', $uploaded->total);
    }

    public function test_an_uploaded_row_is_preferred_over_an_existing_adjusted_row(): void
    {
        $brokerRow = $this->brokerRow();
        $uploaded = $this->purchase();
        $adjusted = $this->purchase([
            'purchase_local' => 0.00,
            'services' => 100.00,
            'other_than_capital_goods' => 0.00,
            'taxable_net_of_vat' => 100.00,
            'input_vat' => 12.00,
            'total_purchases' => 100.00,
            'total' => 100.00,
            'is_adjusted' => true,
        ]);

        $this->transfer($brokerRow)->assertRedirect("/records/{$brokerRow->id}/edit");

        $this->assertSame('400.00', $uploaded->fresh()->services);
        $this->assertSame('100.00', $adjusted->fresh()->services);
    }

    public function test_an_existing_adjusted_row_is_incremented_when_no_uploaded_row_matches(): void
    {
        $brokerRow = $this->brokerRow();
        $adjusted = $this->purchase([
            'purchase_local' => 0.00,
            'services' => 100.00,
            'other_than_capital_goods' => 0.00,
            'taxable_net_of_vat' => 100.00,
            'input_vat' => 12.00,
            'total_purchases' => 100.00,
            'total' => 100.00,
            'is_adjusted' => true,
        ]);

        $this->transfer($brokerRow)->assertRedirect("/records/{$brokerRow->id}/edit");

        $adjusted->refresh();

        $this->assertSame(2, VatInput::count());
        $this->assertTrue($adjusted->is_adjusted);
        $this->assertSame('500.00', $adjusted->services);
        $this->assertSame('500.00', $adjusted->taxable_net_of_vat);
        $this->assertSame('60.00', $adjusted->input_vat);
        $this->assertSame('500.00', $adjusted->total);
        // An adjusted row carries no capital goods, as before.
        $this->assertSame('0.00', $adjusted->capital_goods);
    }

    public function test_a_new_adjusted_row_is_created_when_no_target_exists(): void
    {
        $brokerRow = $this->brokerRow();

        $this->transfer($brokerRow)->assertRedirect("/records/{$brokerRow->id}/edit");

        $created = VatInput::where('is_adjusted', true)->sole();

        $this->assertSame('LOCAL HARDWARE INC.', $created->supplier_name);
        $this->assertSame(self::VENDOR_TIN, $created->tin_number);
        $this->assertSame('TYPED STREET', $created->address1);
        $this->assertSame('400.00', $created->services);
        $this->assertSame('400.00', $created->total);
        $this->assertSame('48.00', $created->input_vat);
        $this->assertSame('600.00', $brokerRow->fresh()->services);
    }

    public function test_a_same_tin_row_from_another_month_is_not_used(): void
    {
        $brokerRow = $this->brokerRow();
        $otherMonth = $this->purchase(['date_uploaded' => '2026-03-31']);

        $this->transfer($brokerRow)->assertRedirect("/records/{$brokerRow->id}/edit");

        $this->assertSame('0.00', $otherMonth->fresh()->services);
        $this->assertSame(1, VatInput::where('is_adjusted', true)->count());
    }

    public function test_a_same_tin_row_with_a_different_imported_flag_is_not_used(): void
    {
        $brokerRow = $this->brokerRow();
        $importedRow = $this->purchase([
            'is_imported' => true,
            'purchase_imported' => 5000.00,
            'purchase_local' => 0.00,
            'capital_goods' => 5000.00,
            'other_than_capital_goods' => 0.00,
        ]);

        $this->transfer($brokerRow)->assertRedirect("/records/{$brokerRow->id}/edit");

        $this->assertSame('0.00', $importedRow->fresh()->services);

        $created = VatInput::where('is_adjusted', true)->sole();

        // is_imported carries no boolean cast, so it reads back as 0 or 1.
        $this->assertFalse((bool) $created->is_imported);
        $this->assertSame('400.00', $created->services);
    }

    public function test_the_source_broker_row_is_never_its_own_merge_target(): void
    {
        $brokerRow = $this->brokerRow();

        // The broker's own TIN entered as the destination.
        $this->transfer($brokerRow, [
            'supplier_name' => 'FAST LANE BROKERAGE INC.',
            'company_name' => 'FAST LANE BROKERAGE INC.',
            'tin_number' => self::BROKER_TIN,
        ])->assertRedirect("/records/{$brokerRow->id}/edit");

        $brokerRow->refresh();

        $this->assertSame('600.00', $brokerRow->services);
        $this->assertFalse($brokerRow->is_adjusted);
        $this->assertSame('400.00', VatInput::where('is_adjusted', true)->sole()->services);
    }

    /**
     * Importation entries are mirrored into vat_inputs under one shared TIN, and
     * ImportationEntryWriter rewrites those rows whole on the next edit. Merging a
     * transfer into one would lose it, and the purchase DAT skips them anyway.
     */
    public function test_an_importation_mirror_row_is_not_used_as_a_merge_target(): void
    {
        $brokerRow = $this->brokerRow();
        $mirror = $this->purchase([
            'is_imported' => true,
            'purchase_imported' => 9000.00,
            'purchase_local' => 0.00,
            'services' => 0.00,
            'capital_goods' => 9000.00,
            'other_than_capital_goods' => 0.00,
        ]);

        ImportationEntry::create([
            'sequence_number' => 1,
            'tax_month' => '2026-04-01',
            'import_entry_no' => 'C-12345',
            'assessment_date' => '2026-04-10',
            'supplier' => 'SHENZHEN METALS CO.',
            'importation_date' => '2026-04-05',
            'country' => 'CHINA',
            'total_landed_cost' => 9000.00,
            'dutiable_value' => 9000.00,
            'charges' => 0.00,
            'exempt' => 0.00,
            'taxable_goods' => 9000.00,
            'vat_rate' => 12.00,
            'vat_payable' => 1080.00,
            'or_number' => '987654',
            'payment_date' => '2026-04-12',
            'vat_input_id' => $mirror->id,
        ]);

        $this->transfer($brokerRow, [
            'is_imported' => true,
            'purchase_imported' => 0,
            'services' => 400,
        ])->assertRedirect("/records/{$brokerRow->id}/edit");

        $this->assertSame('0.00', $mirror->fresh()->services);
        $this->assertSame('400.00', VatInput::where('is_adjusted', true)->sole()->services);
    }

    /**
     * The autofill behind the TIN field answers with the row a transfer would
     * merge into, so the screen cannot show one vendor and save into another.
     */
    public function test_the_target_lookup_answers_with_the_uploaded_row(): void
    {
        $brokerRow = $this->brokerRow();
        $uploaded = $this->purchase();

        $this->getJson("/records/{$brokerRow->id}/adjusted-lookup?" . http_build_query([
            'tin_number' => self::VENDOR_TIN,
            'is_imported' => 0,
        ]))->assertOk()->assertJsonPath('adjustedRecord.id', $uploaded->id);

        // The first nine digits are the vendor, so a TIN typed without its branch
        // code still finds the row filed with one.
        $this->getJson("/records/{$brokerRow->id}/adjusted-lookup?" . http_build_query([
            'tin_number' => '222-222-222',
            'is_imported' => 0,
        ]))->assertOk()->assertJsonPath('adjustedRecord.id', $uploaded->id);

        // A vendor the month has no row for autofills nothing.
        $this->getJson("/records/{$brokerRow->id}/adjusted-lookup?" . http_build_query([
            'tin_number' => '999-999-999-000',
            'is_imported' => 0,
        ]))->assertOk()->assertJsonPath('adjustedRecord', null);
    }
}
