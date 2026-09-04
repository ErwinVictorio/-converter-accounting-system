<?php

namespace App\Http\Controllers;

use App\Imports\ExpandedWtaxImport;
use App\Imports\ExpandedWtaxUploadPreflight;
use App\Imports\UploadBirInfoPreflight;
use App\Imports\UploadWorkbookTypePreflight;
use App\Imports\VatInputImport;
use App\Imports\SalesVatInputImport;
use App\Models\Brokers;
use App\Models\ExpandedWtaxEntry;
use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\VatInput;
use App\Services\BIR\AnnualCoveredPeriodValidator;
use App\Services\BIR\WithholdingCompanyDirectory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class VatInputController extends Controller
{
    /**
     * The Known Company dropdown and the withholding agent an Expanded WTAX upload
     * is stored under both come from here, so this screen and the Generate DAT
     * screen cannot end up offering different companies.
     */
    public function __construct(
        private WithholdingCompanyDirectory $companies,
        private AnnualCoveredPeriodValidator $annualPeriod
    ) {
    }

    /**
     * Import Data: the upload workflow only.
     *
     * The stored rows moved to Record > Purchase / Sales / Expanded WTAX Records,
     * each with its own listing on RecordController, so this screen sends nothing
     * but the withholding agent companies its Expanded WTAX selector needs.
     */
    public function index(Request $request)
    {
        return Inertia::render('RecordEntry', [
            'birCompanies' => $this->companies->activeCompanies(),
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'record_type' => ['required', 'in:purchase,sales,expanded'],
            'report_type' => ['nullable', 'in:quarterly,annual'],
            'reporting_month' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('record_type') !== 'expanded'
                    || $request->input('report_type', 'quarterly') === 'quarterly'),
                'date',
            ],
            'start_date' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('record_type') === 'expanded'
                    && $request->input('report_type') === 'annual'),
                'date',
            ],
            'end_date' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('record_type') === 'expanded'
                    && $request->input('report_type') === 'annual'),
                'date',
                'after_or_equal:start_date',
            ],
            'withholding_agent_tin' => ['nullable', 'required_if:record_type,expanded', 'regex:/^(\d{9}|\d{3}-\d{3}-\d{3})$/'],
            'withholding_agent_branch_code' => ['nullable', 'required_if:record_type,expanded', 'regex:/^\d{1,4}$/'],
        ]);

        try {
            $file = $request->file('excel_file');

            if ($request->input('record_type') === 'sales') {
                $reportingPeriod = Carbon::parse($request->input('reporting_month'))->endOfMonth()->toDateString();
                $issues = (new UploadWorkbookTypePreflight)->check($file, 'sales', $reportingPeriod);

                if ($issues !== []) {
                    return back()->with('error', implode(' ', $issues));
                }

                $birIssues = (new UploadBirInfoPreflight)->checkSales($file, $reportingPeriod);

                if ($birIssues !== []) {
                    return back()
                        ->with('error', 'Sales upload rejected. Fix customer BIR info before importing.')
                        ->with('uploadIssueDialog', [
                            'title' => 'Sales upload needs BIR info fixes',
                            'message' => 'Fix customer BIR info before uploading this file.',
                            'summary' => count($birIssues) . ' issue(s) found. No records were imported or replaced.',
                            'record_type' => 'sales',
                            'issues' => $birIssues,
                        ]);
                }

                $import = new SalesVatInputImport($reportingPeriod);
                DB::transaction(function () use ($reportingPeriod, $import, $file) {
                    SalesVatInput::query()
                        ->whereDate('reporting_period', $reportingPeriod)
                        ->where('is_adjusted', false)
                        ->delete();

                    Excel::import($import, $file);

                    if ($import->importedRows() === 0 && $import->skippedDebitMemoRows() > 0) {
                        throw new \RuntimeException(
                            'Sales upload skipped ' . $import->skippedDebitMemoRows() . ' DM row(s). No importable SI/CM Sales rows were found.'
                        );
                    }
                });

                $response = back()->with('success', 'Sales VAT report for ' . Carbon::parse($reportingPeriod)->format('F Y') . ' was replaced successfully.');

                if ($import->skippedDebitMemoRows() > 0) {
                    $response->with('warning', 'Sales upload completed, but ' . $import->skippedDebitMemoRows() . ' DM row(s) were skipped because Debit Memo rows are not included in Sales VAT upload.');
                }

                return $response;
            }

            if ($request->input('record_type') === 'expanded') {
                $withholdingAgent = $this->withholdingAgentFromRequest($request);
                $reportType = $request->input('report_type', 'quarterly');

                if ($reportType === 'annual') {
                    $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                    $endDate = Carbon::parse($request->input('end_date'))->endOfDay();

                    /*
                     * A partial covered period is refused here, before checkRange reads
                     * the workbook and before the delete below clears the year. The 1604E
                     * those rows would be filed in is dated 12/31/YYYY whatever period
                     * was selected, so accepting 01/01 to 07/31 would file a full-year
                     * return five months short -- and a cross-year selection is not one
                     * taxable year at all. See AnnualCoveredPeriodValidator.
                     */
                    $periodErrors = $this->annualPeriod->errors($startDate, $endDate);

                    if ($periodErrors !== []) {
                        return back()->withErrors($periodErrors);
                    }

                    $issues = (new ExpandedWtaxUploadPreflight)->checkRange(
                        $file,
                        $startDate->toDateString(),
                        $endDate->toDateString()
                    );

                    if ($issues !== []) {
                        return back()->with(
                            'error',
                            'Expanded withholding tax annual upload rejected. ' . implode(' ', $issues)
                        );
                    }

                    DB::transaction(function () use ($startDate, $endDate, $file, $withholdingAgent) {
                        ExpandedWtaxEntry::query()
                            ->where('report_type', 'annual')
                            ->whereBetween('reporting_period', [
                                $startDate->copy()->startOfMonth()->toDateString(),
                                $endDate->copy()->endOfMonth()->toDateString(),
                            ])
                            ->where('withholding_agent_tin', $withholdingAgent['tin'])
                            ->where('withholding_agent_branch_code', $withholdingAgent['branch_code'])
                            ->delete();

                        Excel::import(
                            new ExpandedWtaxImport($endDate->toDateString(), $withholdingAgent, true, 'annual'),
                            $file
                        );
                    });

                    return back()->with('success', 'Expanded withholding tax annual report successfully imported!');
                }

                $reportingPeriod = Carbon::parse($request->input('reporting_month'))->endOfMonth()->toDateString();

                /*
                 * Checked before anything is deleted, so a workbook with a missing
                 * column or the wrong reporting month cannot cost the user the month
                 * already on file. The transaction below would roll the delete back
                 * regardless; checking here is what makes the message name the
                 * column or the row instead of reporting a bare failure.
                 */
                $issues = (new ExpandedWtaxUploadPreflight)->check($file, $reportingPeriod);

                if ($issues !== []) {
                    return back()->with(
                        'error',
                        'Expanded withholding tax upload rejected. ' . implode(' ', $issues)
                    );
                }

                /*
                 * The workbook covers a whole month, so re-uploading a month replaces
                 * it instead of adding to it. Appending would double the tax withheld
                 * and file twice the real figure, which is worse than losing a manual
                 * correction.
                 */
                DB::transaction(function () use ($reportingPeriod, $file, $withholdingAgent) {
                    ExpandedWtaxEntry::query()
                        ->where('report_type', 'quarterly')
                        ->where('reporting_period', $reportingPeriod)
                        ->where('withholding_agent_tin', $withholdingAgent['tin'])
                        ->where('withholding_agent_branch_code', $withholdingAgent['branch_code'])
                        ->delete();

                    Excel::import(new ExpandedWtaxImport($reportingPeriod, $withholdingAgent, false, 'quarterly'), $file);
                });

                return back()->with('success', 'Expanded withholding tax report successfully imported!');
            }

            $reportingPeriod = Carbon::parse($request->input('reporting_month'))->endOfMonth()->toDateString();
            $issues = (new UploadWorkbookTypePreflight)->check($file, 'purchase', $reportingPeriod);

            if ($issues !== []) {
                return back()->with('error', implode(' ', $issues));
            }

            $birIssues = (new UploadBirInfoPreflight)->checkPurchase($file, $reportingPeriod);

            if ($birIssues !== []) {
                return back()
                    ->with('error', 'Purchase upload rejected. Fix supplier BIR info before importing.')
                    ->with('uploadIssueDialog', [
                        'title' => 'Purchase upload needs BIR info fixes',
                        'message' => 'Fix supplier BIR info before uploading this file.',
                        'summary' => count($birIssues) . ' issue(s) found. No records were imported or replaced.',
                        'record_type' => 'purchase',
                        'issues' => $birIssues,
                    ]);
            }

            $import = new VatInputImport($reportingPeriod);
            DB::transaction(function () use ($reportingPeriod, $file, $import) {
                VatInput::query()
                    ->whereDate('date_uploaded', $reportingPeriod)
                    ->where('is_adjusted', false)
                    ->whereNotIn('id', ImportationEntry::query()
                        ->whereNotNull('vat_input_id')
                        ->select('vat_input_id'))
                    ->delete();

                Excel::import($import, $file);

                if ($import->importedRows() === 0 && $import->skippedExcludedSupplierRows() > 0) {
                    throw new \RuntimeException(
                        'Purchase upload skipped ' . $import->skippedExcludedSupplierRows() . ' BUREAU OF CUSTOMS row(s). No importable Purchase rows were found.'
                    );
                }
            });

            $response = back()->with('success', 'Purchase VAT report for ' . Carbon::parse($reportingPeriod)->format('F Y') . ' was replaced successfully.');

            if ($import->skippedExcludedSupplierRows() > 0) {
                $response->with('warning', 'Purchase upload completed, but ' . $import->skippedExcludedSupplierRows() . ' BUREAU OF CUSTOMS row(s) were skipped because they are not included in RELIEF Purchase DAT.');
            }

            return $response;
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * The company an Expanded WTAX upload is filed under. Every row of the month
     * stores it, and re-uploading is scoped to it, so a managed company's
     * registered name is preferred over the raw TIN the request carried.
     */
    private function withholdingAgentFromRequest(Request $request): array
    {
        $agent = $this->companies->resolve(
            $request->input('withholding_agent_tin'),
            $request->input('withholding_agent_branch_code', '0000')
        );

        return [
            'tin' => $agent['tin'],
            'branch_code' => $agent['branch_code'],
            'name' => $agent['name'],
            'registered_name' => $agent['registered_name'] ?? $agent['name'],
        ];
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

    public function adjustedLookup(Request $request, VatInput $vatInput)
    {
        if (!$this->isBrokerRecord($vatInput)) {
            abort(403, 'Only broker records can look up adjusted records.');
        }

        $tinNumber = $this->formatTin($request->input('tin_number'));
        $tinDigits = substr(preg_replace('/\D/', '', $tinNumber), 0, 9);

        if (strlen($tinDigits) < 9) {
            return response()->json([
                'adjustedRecord' => null,
            ]);
        }

        $adjustedRecord = VatInput::query()
            ->select([
                'id',
                'supplier_name',
                'tin_number',
                'vendor_type',
                'company_name',
                'last_name',
                'first_name',
                'middle_name',
                'address1',
                'address2',
                'is_imported',
            ])
            ->where('is_adjusted', true)
            ->where('is_imported', $request->boolean('is_imported'))
            ->whereDate('date_uploaded', $vatInput->getRawOriginal('date_uploaded'))
            ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 9) = ?", [$tinDigits])
            ->first();

        return response()->json([
            'adjustedRecord' => $adjustedRecord,
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
