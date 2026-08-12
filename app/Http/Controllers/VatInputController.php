<?php

namespace App\Http\Controllers;

use App\Imports\VatInputImport;
use App\Models\Brokers;
use App\Models\VatInput;
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
            ->selectRaw('exists(select 1 from brokers where brokers.tin_number = vat_inputs.tin_number) as is_broker')
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
        ]);

        try {
            $file = $request->file('excel_file');

            // Ipasa ang $file at ang original filename nito para malaman ng package ang file extension
            Excel::import(new VatInputImport, $file, null, \Maatwebsite\Excel\Excel::XLSX);
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

        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:255'],
            'tin_number' => ['nullable', 'string', 'max:255'],
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

        DB::transaction(function () use ($vatInput, $validated, $amounts, $newTotal) {
            VatInput::create([
                'supplier_name' => strtoupper(trim($validated['supplier_name'])),
                'tin_number' => $validated['tin_number'] ? trim($validated['tin_number']) : null,
                'is_imported' => (bool) $validated['is_imported'],
                'purchase_imported' => $amounts['purchase_imported'],
                'purchase_local' => $amounts['purchase_local'],
                'services' => $amounts['services'],
                'others' => $amounts['others'],
                'total' => $newTotal,
                'date_uploaded' => $vatInput->getRawOriginal('date_uploaded'),
                'is_broker' => false,
            ]);

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

    private function isBrokerRecord(VatInput $vatInput): bool
    {
        if (!$vatInput->tin_number) {
            return false;
        }

        return Brokers::where('tin_number', $vatInput->tin_number)->exists();
    }
}
