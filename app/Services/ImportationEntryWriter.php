<?php

namespace App\Services;

use App\Models\ImportationEntry;
use App\Models\VatInput;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ImportationEntryWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data, ?int $ignoreId = null, bool $allowExistingDuplicate = false): array
    {
        $validated = validator($data, [
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
            'or_number' => ['required', 'string', 'max:100'],
            'payment_date' => ['required', 'date'],
        ])->validate();

        $landed = $this->amount($validated, 'total_landed_cost');

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

        if (! $allowExistingDuplicate) {
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
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ImportationEntry
    {
        $payload = $this->payload($this->validate($data));
        $payload['sequence_number'] = $this->nextSequenceNumber($payload['tax_month']);

        $entry = ImportationEntry::create($payload);
        $this->syncVatInput($entry);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ImportationEntry $entry, array $data): ImportationEntry
    {
        $payload = $this->payload($this->validate($data, $entry->id));

        $entry->update($payload);
        $this->syncVatInput($entry->fresh());

        return $entry->fresh();
    }

    public function normalizeTaxMonth(?string $value): ?string
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

    public function birText(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = str_replace(',', ' ', $value);
        $value = preg_replace('/[^A-Z0-9 .#\/\-\(\)]/', ' ', $value);

        return preg_replace('/\s+/', ' ', trim($value));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        $landed = $this->amount($validated, 'total_landed_cost');
        $dutiable = $this->amount($validated, 'dutiable_value');
        $exempt = $this->amount($validated, 'exempt');
        $taxableGoods = round($landed - $exempt, 2);
        $vatRate = $this->amount($validated, 'vat_rate');

        return [
            'tax_month' => Carbon::parse($validated['tax_month'])->startOfMonth()->toDateString(),
            'import_entry_no' => $this->birText($validated['import_entry_no']),
            'assessment_date' => Carbon::parse($validated['assessment_date'])->toDateString(),
            'supplier' => $this->birText($validated['supplier']),
            'importation_date' => Carbon::parse($validated['importation_date'])->toDateString(),
            'country' => $this->birText($validated['country']),
            'total_landed_cost' => $landed,
            'dutiable_value' => $dutiable,
            'charges' => round($landed - $dutiable, 2),
            'exempt' => $exempt,
            'taxable_goods' => $taxableGoods,
            'vat_rate' => $vatRate,
            'vat_payable' => round($taxableGoods * ($vatRate / 100), 2),
            'or_number' => $this->birText($validated['or_number']),
            'payment_date' => Carbon::parse($validated['payment_date'])->toDateString(),
        ];
    }

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

    /**
     * @param  array<string, mixed>  $validated
     */
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
            return substr($digits, 0, 3).'-'.
                substr($digits, 3, 3).'-'.
                substr($digits, 6, 3).'-'.
                substr($digits, 9, 3);
        }

        if (strlen($digits) === 9) {
            return substr($digits, 0, 3).'-'.
                substr($digits, 3, 3).'-'.
                substr($digits, 6, 3);
        }

        return $digits;
    }
}
