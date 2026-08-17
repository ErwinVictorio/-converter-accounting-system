<?php

namespace App\Http\Controllers;

use App\Imports\VatInputImport;
use App\Models\Brokers;
use App\Models\VatInput;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class VatInputController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $vatInputs = VatInput::query()
            ->select('vat_inputs.*')
            ->selectRaw("case when vat_inputs.is_adjusted = 1 then 0 else exists(select 1 from brokers where LEFT(REPLACE(REPLACE(REPLACE(brokers.tin_number, '-', ''), ' ', ''), '.', ''), 9) = LEFT(REPLACE(REPLACE(REPLACE(vat_inputs.tin_number, '-', ''), ' ', ''), '.', ''), 9)) end as is_broker")
            // Search Filter (Supplier Name or TIN)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('supplier_name', 'LIKE', "%{$search}%")
                        ->orWhere('tin_number', 'LIKE', "%{$search}%");
                });
            })
            // I-sort mula sa pinakahuling na-update o id
            ->orderBy('id', 'asc')
            // Standard Pagination
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('RecordEntry', [
            'vatInputs' => $vatInputs,
            'filters'   => [
                'search' => $search,
            ],
        ]);
    }
    public function import(Request $request)
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'reporting_month' => ['required', 'date'],
        ]);

        try {
            $file = $request->file('excel_file');
            $reportingPeriod = Carbon::parse($request->input('reporting_month'))->endOfMonth()->toDateString();

            Excel::import(new VatInputImport($reportingPeriod), $file, null, \Maatwebsite\Excel\Excel::XLSX);
            // O mas simple:
            // Excel::import(new VatInputImport, $file);

            return back()->with('success', 'VAT Input Report successfully imported!');
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    public function edit(VatInput $vatInput)
    {
        if (!$this->isBrokerRecord($vatInput)) {
            return redirect('/records')->with('error', 'Only broker records can be edited.');
        }

        return Inertia::render('EditVatInputRecord', [
            'vatInput' => $vatInput,
        ]);
    }

    public function update(Request $request, VatInput $vatInput)
    {
        if (!$this->isBrokerRecord($vatInput)) {
            return redirect('/records')->with('error', 'Only broker records can be edited.');
        }

        $request->merge([
            'tin_number' => $this->formatTin($request->input('tin_number')),
        ]);

        $validated = $request->validate([
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'tin_number' => ['required', 'regex:/^(\d{9}|\d{12}|\d{3}-\d{3}-\d{3}|\d{3}-\d{3}-\d{3}-\d{3})$/'],
            'vendor_type' => ['required', 'in:company,individual'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,company'],
            'last_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'first_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'middle_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'is_imported' => ['required', 'boolean'],
            'purchase_imported' => ['nullable', 'numeric', 'min:0'],
            'purchase_local' => ['nullable', 'numeric', 'min:0'],
            'services' => ['nullable', 'numeric', 'min:0'],
            'others' => ['nullable', 'numeric', 'min:0'],
        ]);

        $amounts = [
            'purchase_imported' => round((float) ($validated['purchase_imported'] ?? 0), 2),
            'purchase_local' => round((float) ($validated['purchase_local'] ?? 0), 2),
            'services' => round((float) ($validated['services'] ?? 0), 2),
            'others' => round((float) ($validated['others'] ?? 0), 2),
        ];

        foreach ($amounts as $field => $amount) {
            if ($amount > (float) $vatInput->{$field}) {
                return back()
                    ->withErrors([$field => 'Amount cannot be greater than the original broker amount.'])
                    ->withInput();
            }
        }

        $newTotal = array_sum($amounts);

        if ($newTotal <= 0) {
            return back()
                ->withErrors(['total' => 'Please enter at least one amount to transfer.'])
                ->withInput();
        }

        if (substr(preg_replace('/\D/', '', $validated['tin_number']), 0, 9) === '000000000') {
            return back()
                ->withErrors(['tin_number' => 'TIN cannot start with 000000000.'])
                ->withInput();
        }

        DB::transaction(function () use ($vatInput, $validated, $amounts, $newTotal) {
            $supplierName = $validated['vendor_type'] === 'company'
                ? ($validated['company_name'] ?? $validated['supplier_name'] ?? '')
                : trim(($validated['last_name'] ?? '') . ' ' . ($validated['first_name'] ?? '') . ' ' . ($validated['middle_name'] ?? ''));
            $tinNumber = $this->formatTin($validated['tin_number']);
            $tinDigits = substr(preg_replace('/\D/', '', $tinNumber), 0, 9);
            $dateUploaded = $vatInput->getRawOriginal('date_uploaded');
            $isImported = (bool) $validated['is_imported'];

            $adjustedRecord = VatInput::query()
                ->where('is_adjusted', true)
                ->where('is_imported', $isImported)
                ->whereDate('date_uploaded', $dateUploaded)
                ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 9) = ?", [$tinDigits])
                ->lockForUpdate()
                ->first();

            $adjustedPayload = [
                'supplier_name' => strtoupper(trim($supplierName)),
                'tin_number' => $tinNumber,
                'vendor_type' => $validated['vendor_type'],
                'company_name' => $validated['vendor_type'] === 'company'
                    ? strtoupper(trim((string) ($validated['company_name'] ?? $validated['supplier_name'])))
                    : null,
                'last_name' => $validated['vendor_type'] === 'individual'
                    ? strtoupper(trim((string) ($validated['last_name'] ?? '')))
                    : null,
                'first_name' => $validated['vendor_type'] === 'individual'
                    ? strtoupper(trim((string) ($validated['first_name'] ?? '')))
                    : null,
                'middle_name' => $validated['vendor_type'] === 'individual'
                    ? strtoupper(trim((string) ($validated['middle_name'] ?? '')))
                    : null,
                'address1' => $validated['address1'] ?? null,
                'address2' => $validated['address2'] ?? null,
                'is_imported' => $isImported,
                'is_broker' => false,
                'is_adjusted' => true,
            ];

            if ($adjustedRecord) {
                $updatedAmounts = [
                    'purchase_imported' => round((float) $adjustedRecord->purchase_imported + $amounts['purchase_imported'], 2),
                    'purchase_local' => round((float) $adjustedRecord->purchase_local + $amounts['purchase_local'], 2),
                    'services' => round((float) $adjustedRecord->services + $amounts['services'], 2),
                    'others' => round((float) $adjustedRecord->others + $amounts['others'], 2),
                ];
                $updatedTotal = array_sum($updatedAmounts);

                $adjustedRecord->update([
                    ...$adjustedPayload,
                    ...$updatedAmounts,
                    'capital_goods' => 0,
                    'other_than_capital_goods' => round($updatedAmounts['purchase_local'] + $updatedAmounts['others'], 2),
                    'taxable_net_of_vat' => $updatedTotal,
                    'vat_rate' => 12,
                    'input_vat' => round($updatedTotal * 0.12, 2),
                    'total_purchases' => $updatedTotal,
                    'total' => $updatedTotal,
                ]);
            } else {
                VatInput::create([
                    ...$adjustedPayload,
                    'exempt' => 0,
                    'zero_rated' => 0,
                    'purchase_imported' => $amounts['purchase_imported'],
                    'purchase_local' => $amounts['purchase_local'],
                    'services' => $amounts['services'],
                    'capital_goods' => 0,
                    'other_than_capital_goods' => $amounts['purchase_local'] + $amounts['others'],
                    'taxable_net_of_vat' => $newTotal,
                    'vat_rate' => 12,
                    'input_vat' => round($newTotal * 0.12, 2),
                    'total_purchases' => $newTotal,
                    'others' => $amounts['others'],
                    'total' => $newTotal,
                    'date_uploaded' => $dateUploaded,
                ]);
            }

            $remaining = [
                'purchase_imported' => round((float) $vatInput->purchase_imported - $amounts['purchase_imported'], 2),
                'purchase_local' => round((float) $vatInput->purchase_local - $amounts['purchase_local'], 2),
                'services' => round((float) $vatInput->services - $amounts['services'], 2),
                'others' => round((float) $vatInput->others - $amounts['others'], 2),
            ];

            $vatInput->update([
                ...$remaining,
                'total' => array_sum($remaining),
                'is_broker' => true,
            ]);
        });

        return redirect('/records')->with('success', 'VAT input record adjusted successfully.');
    }

    public function updateBirInfo(Request $request, VatInput $vatInput)
    {
        $request->merge([
            'tin_number' => $this->formatTin($request->input('tin_number')),
        ]);

        $validated = $request->validate([
            'vendor_type' => ['required', 'in:company,individual'],
            'tin_number' => ['required', 'regex:/^(\d{9}|\d{12}|\d{3}-\d{3}-\d{3}|\d{3}-\d{3}-\d{3}-\d{3})$/'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,company'],
            'last_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'first_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'middle_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
        ]);

        if (substr(preg_replace('/\D/', '', $validated['tin_number']), 0, 9) === '000000000') {
            return back()
                ->withErrors(['tin_number' => 'TIN cannot start with 000000000.'])
                ->withInput();
        }

        $supplierName = $validated['vendor_type'] === 'company'
            ? $validated['company_name']
            : trim($validated['last_name'] . ' ' . $validated['first_name'] . ' ' . $validated['middle_name']);
        [$address1, $address2] = $this->splitAddress((string) ($validated['address1'] ?? ''));
        $address2 = $address2 ?: $this->birText((string) ($validated['address2'] ?? ''));

        $vatInput->update([
            'supplier_name' => $this->birText($supplierName),
            'tin_number' => $this->formatTin($validated['tin_number']),
            'vendor_type' => $validated['vendor_type'],
            'company_name' => $validated['vendor_type'] === 'company'
                ? $this->birText((string) $validated['company_name'])
                : null,
            'last_name' => $validated['vendor_type'] === 'individual'
                ? $this->birText((string) $validated['last_name'])
                : null,
            'first_name' => $validated['vendor_type'] === 'individual'
                ? $this->birText((string) $validated['first_name'])
                : null,
            'middle_name' => $validated['vendor_type'] === 'individual'
                ? $this->birText((string) $validated['middle_name'])
                : null,
            'address1' => $address1 ?: null,
            'address2' => $address2 ?: null,
        ]);

        return back()->with('success', 'BIR vendor information updated.');
    }

    private function isBrokerRecord(VatInput $vatInput): bool
    {
        if ($vatInput->is_adjusted) {
            return false;
        }

        if (!$vatInput->tin_number) {
            return false;
        }

        $tin = substr(preg_replace('/\D/', '', (string) $vatInput->tin_number), 0, 9);

        if ($tin === '') {
            return false;
        }

        return Brokers::query()
            ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 9) = ?", [$tin])
            ->exists();
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

    private function splitAddress(string $value): array
    {
        $parts = array_values(array_filter(array_map(
            fn (string $part) => $this->birText($part),
            explode(',', $value)
        )));

        if ($parts === []) {
            return ['', ''];
        }

        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        return [
            implode(' ', array_slice($parts, 0, -1)),
            $parts[count($parts) - 1],
        ];
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
