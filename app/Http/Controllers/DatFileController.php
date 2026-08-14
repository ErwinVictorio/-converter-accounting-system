<?php

namespace App\Http\Controllers;

use App\Models\VatInput;
use App\Models\Supplier;
use App\Services\BIR\BirPurchaseRowValidator;
use App\Services\BIR\ReliefPurchaseDatGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DatFileController extends Controller
{
    public function index(BirPurchaseRowValidator $validator)
    {
        $availablePeriods = VatInput::query()
            ->selectRaw("DATE_FORMAT(date_uploaded, '%Y-%m') as value")
            ->selectRaw("DATE_FORMAT(date_uploaded, '%M %Y') as label")
            ->selectRaw('COUNT(*) as records_count')
            ->groupBy('value', 'label')
            ->orderByDesc('value')
            ->get();
        $periodIssues = [];

        VatInput::query()
            ->orderBy('date_uploaded')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (VatInput $record) => $record->date_uploaded->format('Y-m'))
            ->each(function ($records, string $period) use (&$periodIssues, $validator) {
                $errors = [];

                foreach ($records->values() as $index => $record) {
                    foreach ($validator->validate($record->toBirPurchaseRow(), $index + 2) as $error) {
                        $errors[] = "Record #{$record->id} {$record->supplier_name}: {$error}";
                    }
                }

                $periodIssues[$period] = [
                    'invalid_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ];
            });

        return Inertia::render('GenerateDatFile', [
            'defaultCompany' => config('bir.companies.008791976'),
            'availablePeriods' => $availablePeriods,
            'periodIssues' => $periodIssues,
        ]);
    }

    public function companyLookup(string $tin)
    {
        $tin = preg_replace('/\D/', '', $tin);
        $company = config("bir.companies.{$tin}");

        if ($company) {
            return response()->json($company);
        }

        $vatInput = VatInput::query()
            ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 9) = ?", [$tin])
            ->latest('id')
            ->first();

        if ($vatInput) {
            return response()->json([
                'tin' => $tin,
                'name' => $vatInput->company_name ?: $vatInput->supplier_name,
                'registered_name' => $vatInput->company_name ?: $vatInput->supplier_name,
                'address1' => $vatInput->address1 ?: '',
                'address2' => $vatInput->address2 ?: '',
                'rdo_code' => '',
                'source' => 'vat_inputs',
            ]);
        }

        $supplier = Supplier::query()
            ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin, '-', ''), ' ', ''), '.', ''), 9) = ?", [$tin])
            ->first();

        if ($supplier) {
            return response()->json([
                'tin' => $tin,
                'name' => $supplier->payee ?: $supplier->name,
                'registered_name' => $supplier->payee ?: $supplier->name,
                'address1' => $supplier->addr ?: '',
                'address2' => '',
                'rdo_code' => '',
                'source' => 'suppliers',
            ]);
        }

        return response()->json([
            'message' => 'TIN not found.',
        ], 404);
    }

    public function download(
        Request $request,
        ReliefPurchaseDatGenerator $generator,
        BirPurchaseRowValidator $validator
    )
    {
        $validated = $request->validate([
            'period' => ['required', 'date'],
        ]);

        $period = Carbon::parse($validated['period'])->endOfMonth();

        $records = VatInput::query()
            ->whereBetween('date_uploaded', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'No VAT input records found for the selected reporting month.');
        }

        $rowErrors = [];
        foreach ($records as $index => $record) {
            foreach ($validator->validate($record->toBirPurchaseRow(), $index + 2) as $error) {
                $rowErrors[] = "Record #{$record->id} {$record->supplier_name}: {$error}";
            }
        }

        if ($rowErrors !== []) {
            return back()->with('error', 'Cannot generate DAT. Fix these VAT input rows first: ' . implode(' ', array_slice($rowErrors, 0, 5)));
        }

        $defaultCompany = config('bir.companies.008791976');

        $company = [
            'tin' => $defaultCompany['tin'],
            'name' => $defaultCompany['name'],
            'registered_name' => $defaultCompany['registered_name'],
            'address1' => $defaultCompany['address1'],
            'address2' => $defaultCompany['address2'],
            'rdo_code' => $defaultCompany['rdo_code'],
            'final_header_field' => '12',
        ];

        $content = $generator->generate(
            $company,
            $records->map(fn (VatInput $record) => $record->toBirPurchaseRow()),
            $period,
            0
        );

        $fileName = $generator->filename($company, $period);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }
}
