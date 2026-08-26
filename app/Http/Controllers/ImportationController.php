<?php

namespace App\Http\Controllers;

use App\Models\ImportationEntry;
use App\Models\VatInput;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ImportationController extends Controller
{
    /**
     * The manual-entry screen. Keying a new importation only, so it carries no
     * listing -- the stored rows live on Record > Importation Records below.
     */
    public function index(Request $request)
    {
        return Inertia::render('Importation');
    }

    /**
     * Record > Importation Records: the stored manual entries, optionally
     * filtered by tax month. Kept on this controller rather than RecordController
     * so it sits beside the store/update/destroy rules its table calls.
     */
    public function records(Request $request)
    {
        $taxMonth = $request->input('tax_month');
        $normalizedMonth = $taxMonth ? $this->normalizeTaxMonth($taxMonth) : null;

        $entries = ImportationEntry::query()
            ->when($normalizedMonth, function ($query, string $month) {
                $query->whereDate('tax_month', $month);
            })
            ->orderByDesc('tax_month')
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        /*
         * Grouped in PHP rather than with DATE_FORMAT so the listing runs on any
         * driver. These are only the labels for the filter above the table, and
         * there is one row per keyed importation, so the set stays small.
         */
        $months = ImportationEntry::query()
            ->orderByDesc('tax_month')
            ->pluck('tax_month')
            ->groupBy(fn ($month) => Carbon::parse($month)->format('Y-m'))
            ->map(fn ($group, string $value) => [
                'value' => $value,
                'label' => Carbon::parse($value . '-01')->format('F Y'),
                'records_count' => $group->count(),
            ])
            ->values();

        return Inertia::render('Records/ImportationRecords', [
            'entries' => $entries,
            'months' => $months,
            'filters' => [
                'tax_month' => $taxMonth ? Carbon::parse($normalizedMonth)->format('Y-m') : '',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateEntry($request);

        try {
            DB::transaction(function () use ($validated) {
                $payload = $this->payload($validated);
                $payload['sequence_number'] = $this->nextSequenceNumber($payload['tax_month']);

                $entry = ImportationEntry::create($payload);
                $this->syncVatInput($entry);
            });

            return redirect()->back()->with('success', 'Importation entry created successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function update(Request $request, ImportationEntry $importationEntry)
    {
        $validated = $this->validateEntry($request, $importationEntry->id);

        try {
            DB::transaction(function () use ($validated, $importationEntry) {
                $payload = $this->payload($validated);
                // Preserve the original display order for an edited row.
                unset($payload['sequence_number']);

                $importationEntry->update($payload);
                $this->syncVatInput($importationEntry->fresh());
            });

            return redirect()->back()->with('success', 'Importation entry updated successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function destroy(ImportationEntry $importationEntry)
    {
        try {
            DB::transaction(function () use ($importationEntry) {
                if ($importationEntry->vat_input_id) {
                    VatInput::query()->whereKey($importationEntry->vat_input_id)->delete();
                }

                $importationEntry->delete();
            });

            return redirect()->back()->with('success', 'Importation entry deleted successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    /**
     * Mirrors the customs paperwork the users key from: they type total landed
     * cost, and charges + taxable goods are derived (see payload()). Those two
     * are still written to the DB and the DAT unchanged.
     */
    private function validateEntry(Request $request, ?int $ignoreId = null): array
    {
        $request->merge([
            'tax_month' => $this->normalizeTaxMonth($request->input('tax_month')),
        ]);

        $validated = $request->validate([
            'tax_month' => ['required', 'date'],
            'import_entry_no' => ['required', 'string', 'max:100'],
            'assessment_date' => ['required', 'date'],
            'supplier' => ['required', 'string', 'max:255'],
            'importation_date' => ['required', 'date'],
            'country' => ['required', 'string', 'max:100'],
            'total_landed_cost' => ['required', 'numeric', 'min:0'],
            'dutiable_value' => ['required', 'numeric', 'min:0'],
            'exempt' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['required', 'numeric', 'min:0'],
            'vat_payable' => ['required', 'numeric', 'min:0'],
            'or_number' => ['required', 'string', 'max:100'],
            'payment_date' => ['required', 'date'],
        ]);

        $landed = $this->amount($validated, 'total_landed_cost');

        // Both derived amounts must stay >= 0 or the DAT would carry negatives.
        if ($this->amount($validated, 'dutiable_value') > $landed) {
            throw ValidationException::withMessages([
                'dutiable_value' => 'Dutiable value cannot be more than the total landed cost.',
            ]);
        }

        if ($this->amount($validated, 'exempt') > $landed) {
            throw ValidationException::withMessages([
                'exempt' => 'Exempt cannot be more than the total landed cost.',
            ]);
        }

        $taxMonth = Carbon::parse($validated['tax_month'])->startOfMonth()->toDateString();
        $importEntryNo = $this->birText($validated['import_entry_no']);

        $duplicate = ImportationEntry::query()
            ->whereDate('tax_month', $taxMonth)
            ->where('import_entry_no', $importEntryNo)
            ->when($ignoreId, fn ($query, int $id) => $query->where('id', '!=', $id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'import_entry_no' => 'This import entry no. already exists for the selected tax month.',
            ]);
        }

        return $validated;
    }

    /**
     * charges and taxable_goods are derived, not keyed. The entry screen shows
     * them read-only; these are the same two formulas.
     */
    private function payload(array $validated): array
    {
        $landed = $this->amount($validated, 'total_landed_cost');
        $dutiable = $this->amount($validated, 'dutiable_value');
        $exempt = $this->amount($validated, 'exempt');

        return [
            'tax_month' => Carbon::parse($validated['tax_month'])->startOfMonth()->toDateString(),
            'import_entry_no' => $this->birText($validated['import_entry_no']),
            'assessment_date' => Carbon::parse($validated['assessment_date'])->toDateString(),
            'supplier' => $this->birText($validated['supplier']),
            'importation_date' => Carbon::parse($validated['importation_date'])->toDateString(),
            'country' => $this->birText($validated['country']),
            'total_landed_cost' => $landed,
            'dutiable_value' => $dutiable,
            // All charges before release from customs' custody.
            'charges' => round($landed - $dutiable, 2),
            'exempt' => $exempt,
            'taxable_goods' => round($landed - $exempt, 2),
            'vat_rate' => $this->amount($validated, 'vat_rate'),
            'vat_payable' => $this->amount($validated, 'vat_payable'),
            'or_number' => $this->birText($validated['or_number']),
            'payment_date' => Carbon::parse($validated['payment_date'])->toDateString(),
        ];
    }

    /**
     * Create/update the matching vat_inputs row so the existing purchase DAT
     * generator includes this importation without a second DAT engine.
     */
    private function syncVatInput(ImportationEntry $entry): void
    {
        $taxableGoods = round((float) $entry->taxable_goods, 2);
        $exempt = round((float) $entry->exempt, 2);
        $total = round($exempt + $taxableGoods, 2);

        $vatInputPayload = [
            'supplier_name' => $entry->supplier,
            'tin_number' => $this->formatTin((string) config('bir.importation.tin')),
            'vendor_type' => 'company',
            'company_name' => $entry->supplier,
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
            // Chosen approach: importation vendor TIN is fixed, country maps to the address.
            'address1' => $entry->country ?: $this->birText((string) config('bir.importation.address2')),
            'address2' => $this->birText((string) config('bir.importation.address2')) ?: null,
            'is_imported' => true,
            'exempt' => $exempt,
            'zero_rated' => 0,
            'purchase_imported' => $taxableGoods,
            'purchase_local' => 0,
            'services' => 0,
            'capital_goods' => $taxableGoods,
            'other_than_capital_goods' => 0,
            'taxable_net_of_vat' => $taxableGoods,
            'vat_rate' => round((float) $entry->vat_rate, 2),
            'input_vat' => round((float) $entry->vat_payable, 2),
            'total_purchases' => $total,
            'others' => 0,
            'total' => $total,
            'date_uploaded' => Carbon::parse($entry->tax_month)->endOfMonth()->toDateString(),
            'is_broker' => false,
            'is_adjusted' => false,
        ];

        if ($entry->vat_input_id && $vatInput = VatInput::find($entry->vat_input_id)) {
            $vatInput->update($vatInputPayload);

            return;
        }

        $vatInput = VatInput::create($vatInputPayload);
        $entry->forceFill(['vat_input_id' => $vatInput->id])->save();
    }

    private function nextSequenceNumber(string $taxMonth): int
    {
        return (int) ImportationEntry::query()
            ->whereDate('tax_month', $taxMonth)
            ->max('sequence_number') + 1;
    }

    private function normalizeTaxMonth(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $value .= '-01';
        }

        return Carbon::parse($value)->startOfMonth()->toDateString();
    }

    private function amount(array $validated, string $key): float
    {
        return round((float) ($validated[$key] ?? 0), 2);
    }

    private function formatTin(?string $value): string
    {
        $digits = substr(preg_replace('/\D/', '', (string) $value), 0, 12);

        if (strlen($digits) > 9 && strlen($digits) < 12) {
            $digits = str_pad($digits, 12, '0');
        }

        if (strlen($digits) === 12) {
            return substr($digits, 0, 3) . '-' .
                substr($digits, 3, 3) . '-' .
                substr($digits, 6, 3) . '-' .
                substr($digits, 9, 3);
        }

        if (strlen($digits) === 9) {
            return substr($digits, 0, 3) . '-' .
                substr($digits, 3, 3) . '-' .
                substr($digits, 6, 3);
        }

        return $digits;
    }

    private function birText(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = str_replace(',', ' ', $value);
        $value = preg_replace('/[^A-Z0-9 .#\/\-\(\)]/', ' ', $value);

        return preg_replace('/\s+/', ' ', trim($value));
    }
}
