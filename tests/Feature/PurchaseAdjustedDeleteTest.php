<?php

namespace Tests\Feature;

use App\Models\Brokers;
use App\Models\ImportationEntry;
use App\Models\PurchaseAdjustment;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseAdjustedDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-04-30';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function purchase(array $overrides = []): VatInput
    {
        return VatInput::create(array_merge([
            'supplier_name' => 'LOCAL HARDWARE INC.',
            'tin_number' => '222-222-222-000',
            'vendor_type' => 'company',
            'company_name' => 'LOCAL HARDWARE INC.',
            'address1' => 'MAIN STREET',
            'address2' => 'MAKATI CITY',
            'is_imported' => false,
            'exempt' => 0.00,
            'zero_rated' => 0.00,
            'purchase_imported' => 0.00,
            'purchase_local' => 0.00,
            'services' => 0.00,
            'capital_goods' => 0.00,
            'other_than_capital_goods' => 0.00,
            'taxable_net_of_vat' => 0.00,
            'vat_rate' => 12.00,
            'input_vat' => 0.00,
            'total_purchases' => 0.00,
            'others' => 0.00,
            'total' => 0.00,
            'date_uploaded' => self::PERIOD,
            'is_broker' => false,
            'is_adjusted' => false,
        ], $overrides));
    }

    private function brokerRow(string $tin, float $services, array $overrides = []): VatInput
    {
        Brokers::create([
            'broker_name' => 'BROKER '.$tin,
            'tin_number' => $tin,
        ]);

        return $this->purchase(array_merge([
            'supplier_name' => 'BROKER '.$tin,
            'tin_number' => $tin,
            'company_name' => 'BROKER '.$tin,
            'services' => $services,
            'taxable_net_of_vat' => $services,
            'input_vat' => round($services * 0.12, 2),
            'total_purchases' => $services,
            'total' => $services,
            'is_broker' => true,
        ], $overrides));
    }

    private function transfer(VatInput $source, float $services, string $targetTin = '999-999-999-000')
    {
        return $this->put("/records/{$source->id}", [
            'supplier_name' => 'TARGET SERVICES INC.',
            'tin_number' => $targetTin,
            'vendor_type' => 'company',
            'company_name' => 'TARGET SERVICES INC.',
            'address1' => 'TARGET STREET',
            'address2' => 'PASIG CITY',
            'is_imported' => false,
            'purchase_imported' => 0,
            'purchase_local' => 0,
            'services' => $services,
            'others' => 0,
        ]);
    }

    public function test_a_new_adjusted_row_is_tracked_restored_and_deleted(): void
    {
        $source = $this->brokerRow('111-111-111-000', 1000.00);

        $this->transfer($source, 400.00)->assertRedirect("/records/{$source->id}/edit");

        $target = VatInput::query()->where('is_adjusted', true)->sole();
        $history = PurchaseAdjustment::query()->sole();

        $this->assertSame($source->id, $history->source_vat_input_id);
        $this->assertSame($target->id, $history->target_vat_input_id);
        $this->assertSame('400.00', $history->services);
        $this->assertSame('600.00', $source->fresh()->services);

        $this->delete("/records/{$target->id}")
            ->assertSessionHas('success');

        $this->assertModelMissing($target);
        $this->assertSame(0, PurchaseAdjustment::count());
        $this->assertSame('1000.00', $source->fresh()->services);
        $this->assertSame('1000.00', $source->fresh()->total);
    }

    public function test_purchase_listing_marks_tracked_and_legacy_adjusted_rows(): void
    {
        $source = $this->brokerRow('111-111-111-000', 1000.00);
        $this->transfer($source, 400.00)->assertSessionHasNoErrors();

        $trackedTarget = VatInput::query()->where('is_adjusted', true)->sole();
        $legacyTarget = $this->purchase([
            'supplier_name' => 'LEGACY TARGET INC.',
            'tin_number' => '888-888-888-000',
            'company_name' => 'LEGACY TARGET INC.',
            'services' => 100.00,
            'total' => 100.00,
            'is_adjusted' => true,
        ]);

        $this->get('/records/purchases')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->where('vatInputs.data.1.id', $legacyTarget->id)
                ->where('vatInputs.data.1.adjustment_history_complete', false)
                ->where('vatInputs.data.2.id', $trackedTarget->id)
                ->where('vatInputs.data.2.adjustment_history_complete', true)
        );
    }

    public function test_a_combined_adjusted_row_restores_each_original_broker_once(): void
    {
        $firstSource = $this->brokerRow('111-111-111-000', 1000.00);
        $secondSource = $this->brokerRow('333-333-333-000', 500.00);

        $this->transfer($firstSource, 200.00)->assertSessionHasNoErrors();
        $this->transfer($secondSource, 150.00)->assertSessionHasNoErrors();

        $target = VatInput::query()->where('is_adjusted', true)->sole();

        $this->assertSame('350.00', $target->services);
        $this->assertSame(2, PurchaseAdjustment::query()->where('target_vat_input_id', $target->id)->count());

        $this->delete("/records/{$target->id}")->assertSessionHas('success');

        $this->assertSame('1000.00', $firstSource->fresh()->services);
        $this->assertSame('500.00', $secondSource->fresh()->services);
        $this->assertModelMissing($target);
    }

    public function test_a_non_adjusted_purchase_cannot_be_deleted_directly(): void
    {
        $purchase = $this->purchase(['services' => 100.00, 'total' => 100.00]);

        $this->getJson("/records/{$purchase->id}/adjustment-delete-context")
            ->assertForbidden();

        $this->delete("/records/{$purchase->id}")
            ->assertSessionHas('error', 'Only adjusted Purchase records can be deleted.');

        $this->assertModelExists($purchase);
    }

    public function test_a_legacy_adjusted_row_requires_complete_source_allocation(): void
    {
        $source = $this->brokerRow('111-111-111-000', 600.00);
        $target = $this->purchase([
            'supplier_name' => 'LEGACY TARGET INC.',
            'tin_number' => '999-999-999-000',
            'company_name' => 'LEGACY TARGET INC.',
            'services' => 400.00,
            'taxable_net_of_vat' => 400.00,
            'input_vat' => 48.00,
            'total_purchases' => 400.00,
            'total' => 400.00,
            'is_adjusted' => true,
        ]);

        $this->getJson("/records/{$target->id}/adjustment-delete-context")
            ->assertOk()
            ->assertJsonPath('status', 'needs_link')
            ->assertJsonPath('unresolved_amounts.services', '400.00')
            ->assertJsonPath('candidates.0.id', $source->id);

        $this->delete("/records/{$target->id}")
            ->assertSessionHas('error', 'Link the complete untracked amount to its original broker record before deleting.');

        $this->assertModelExists($target);
        $this->assertSame('600.00', $source->fresh()->services);
    }

    public function test_a_legacy_adjusted_row_can_be_linked_restored_and_deleted_atomically(): void
    {
        $source = $this->brokerRow('111-111-111-000', 600.00);
        $target = $this->purchase([
            'supplier_name' => 'LEGACY TARGET INC.',
            'tin_number' => '999-999-999-000',
            'company_name' => 'LEGACY TARGET INC.',
            'purchase_local' => 100.00,
            'services' => 400.00,
            'other_than_capital_goods' => 100.00,
            'taxable_net_of_vat' => 500.00,
            'input_vat' => 60.00,
            'total_purchases' => 500.00,
            'total' => 500.00,
            'is_adjusted' => true,
        ]);

        $this->delete("/records/{$target->id}", [
            'allocations' => [[
                'source_vat_input_id' => $source->id,
                'purchase_imported' => 0,
                'purchase_local' => 100,
                'services' => 400,
                'others' => 0,
            ]],
        ])->assertSessionHas('success');

        $source->refresh();

        $this->assertSame('100.00', $source->purchase_local);
        $this->assertSame('1000.00', $source->services);
        $this->assertSame('1100.00', $source->total);
        $this->assertModelMissing($target);
        $this->assertSame(0, PurchaseAdjustment::count());
    }

    public function test_legacy_allocation_can_be_split_between_multiple_brokers(): void
    {
        $firstSource = $this->brokerRow('111-111-111-000', 700.00);
        $secondSource = $this->brokerRow('333-333-333-000', 350.00);
        $target = $this->purchase([
            'supplier_name' => 'LEGACY TARGET INC.',
            'tin_number' => '999-999-999-000',
            'company_name' => 'LEGACY TARGET INC.',
            'services' => 450.00,
            'taxable_net_of_vat' => 450.00,
            'input_vat' => 54.00,
            'total_purchases' => 450.00,
            'total' => 450.00,
            'is_adjusted' => true,
        ]);

        $this->delete("/records/{$target->id}", [
            'allocations' => [
                [
                    'source_vat_input_id' => $firstSource->id,
                    'purchase_imported' => 0,
                    'purchase_local' => 0,
                    'services' => 300,
                    'others' => 0,
                ],
                [
                    'source_vat_input_id' => $secondSource->id,
                    'purchase_imported' => 0,
                    'purchase_local' => 0,
                    'services' => 150,
                    'others' => 0,
                ],
            ],
        ])->assertSessionHas('success');

        $this->assertSame('1000.00', $firstSource->fresh()->services);
        $this->assertSame('500.00', $secondSource->fresh()->services);
        $this->assertModelMissing($target);
    }

    public function test_invalid_legacy_allocation_rolls_back_every_change(): void
    {
        $wrongMonthSource = $this->brokerRow('111-111-111-000', 600.00, [
            'date_uploaded' => '2026-05-31',
        ]);
        $target = $this->purchase([
            'supplier_name' => 'LEGACY TARGET INC.',
            'tin_number' => '999-999-999-000',
            'company_name' => 'LEGACY TARGET INC.',
            'services' => 400.00,
            'total' => 400.00,
            'is_adjusted' => true,
        ]);

        $this->delete("/records/{$target->id}", [
            'allocations' => [[
                'source_vat_input_id' => $wrongMonthSource->id,
                'purchase_imported' => 0,
                'purchase_local' => 0,
                'services' => 400,
                'others' => 0,
            ]],
        ])->assertSessionHas('error', 'Select only original broker records from the same month and Imported status.');

        $this->assertModelExists($target);
        $this->assertSame('600.00', $wrongMonthSource->fresh()->services);
        $this->assertSame(0, PurchaseAdjustment::count());
    }

    public function test_incomplete_legacy_amount_allocation_is_rejected_atomically(): void
    {
        $source = $this->brokerRow('111-111-111-000', 600.00);
        $target = $this->purchase([
            'supplier_name' => 'LEGACY TARGET INC.',
            'tin_number' => '999-999-999-000',
            'company_name' => 'LEGACY TARGET INC.',
            'services' => 400.00,
            'total' => 400.00,
            'is_adjusted' => true,
        ]);

        $this->delete("/records/{$target->id}", [
            'allocations' => [[
                'source_vat_input_id' => $source->id,
                'purchase_imported' => 0,
                'purchase_local' => 0,
                'services' => 399.99,
                'others' => 0,
            ]],
        ])->assertSessionHas('error', 'The linked amounts must exactly equal every untracked adjusted amount.');

        $this->assertModelExists($target);
        $this->assertSame('600.00', $source->fresh()->services);
        $this->assertSame(0, PurchaseAdjustment::count());
    }

    public function test_a_missing_original_source_prevents_tracked_deletion(): void
    {
        $source = $this->brokerRow('111-111-111-000', 1000.00);

        $this->transfer($source, 400.00)->assertSessionHasNoErrors();

        $target = VatInput::query()->where('is_adjusted', true)->sole();
        $source->delete();

        $this->delete("/records/{$target->id}")
            ->assertSessionHas('error', 'Link the complete untracked amount to its original broker record before deleting.');

        $this->assertModelExists($target);
        $this->assertDatabaseHas('purchase_adjustments', [
            'target_vat_input_id' => $target->id,
            'source_vat_input_id' => null,
        ]);
    }

    public function test_an_importation_mirror_cannot_use_the_adjusted_delete_route(): void
    {
        $target = $this->purchase([
            'services' => 100.00,
            'total' => 100.00,
            'is_adjusted' => true,
        ]);

        ImportationEntry::create([
            'sequence_number' => 1,
            'tax_month' => '2026-04-01',
            'import_entry_no' => 'C-12345',
            'assessment_date' => '2026-04-10',
            'supplier' => 'SHENZHEN METALS CO.',
            'importation_date' => '2026-04-05',
            'country' => 'CHINA',
            'total_landed_cost' => 100.00,
            'dutiable_value' => 100.00,
            'charges' => 0.00,
            'exempt' => 0.00,
            'taxable_goods' => 100.00,
            'vat_rate' => 12.00,
            'vat_payable' => 12.00,
            'or_number' => '987654',
            'payment_date' => '2026-04-12',
            'vat_input_id' => $target->id,
        ]);

        $this->delete("/records/{$target->id}")
            ->assertSessionHas('error', 'Importation-linked Purchase records cannot be deleted here.');

        $this->assertModelExists($target);
    }

    public function test_an_unauthenticated_user_cannot_delete_an_adjusted_purchase(): void
    {
        $target = $this->purchase(['is_adjusted' => true]);
        Auth::logout();

        $this->delete("/records/{$target->id}")->assertRedirect('/login');

        $this->assertModelExists($target);
    }
}
