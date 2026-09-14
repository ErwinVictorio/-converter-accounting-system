<?php

namespace App\Http\Controllers;

use App\Imports\ExpandedWtaxBirInfoPreflight;
use App\Imports\ExpandedWtaxImport;
use App\Imports\ExpandedWtaxUploadPreflight;
use App\Imports\SalesVatInputImport;
use App\Imports\UploadBirInfoPreflight;
use App\Imports\UploadWorkbookTypePreflight;
use App\Models\Brokers;
use App\Models\ExpandedWtaxEntry;
use App\Models\ImportationEntry;
use App\Models\PurchaseAdjustment;
use App\Models\SalesVatInput;
use App\Models\VatInput;
use App\Services\BIR\AnnualCoveredPeriodValidator;
use App\Services\BIR\WithholdingCompanyDirectory;
use App\Services\PendingPurchaseUploadService;
use App\Services\PurchaseUploadService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class VatInputController extends Controller
{
    private const PURCHASE_ADJUSTMENT_FIELDS = [
        'purchase_imported',
        'purchase_local',
        'services',
        'others',
    ];

    /**
     * The Known Company dropdown and the withholding agent an Expanded WTAX upload
     * is stored under both come from here, so this screen and the Generate DAT
     * screen cannot end up offering different companies.
     */
    public function __construct(
        private WithholdingCompanyDirectory $companies,
        private AnnualCoveredPeriodValidator $annualPeriod,
        private PurchaseUploadService $purchaseUploads,
        private PendingPurchaseUploadService $pendingPurchaseUploads
    ) {}

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
                        ->with('error', 'Sales upload rejected. Fix customer BIR info or Sales amounts before importing.')
                        ->with('uploadIssueDialog', [
                            'title' => 'Sales upload needs BIR info or amount fixes',
                            'message' => 'Fix the listed customer BIR info or workbook amounts before uploading this file.',
                            'summary' => count($birIssues).' issue(s) found. No records were imported or replaced.',
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
                            'Sales upload skipped '.$import->skippedDebitMemoRows().' DM row(s). No importable SI/CM Sales rows were found.'
                        );
                    }
                });

                $response = back()->with('success', 'Sales VAT report for '.Carbon::parse($reportingPeriod)->format('F Y').' was replaced successfully.');

                if ($import->skippedDebitMemoRows() > 0) {
                    $response->with('warning', 'Sales upload completed, but '.$import->skippedDebitMemoRows().' DM row(s) were skipped because Debit Memo rows are not included in Sales VAT upload.');
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
                            'Expanded withholding tax annual upload rejected. '.implode(' ', $issues)
                        );
                    }

                    /*
                     * Per-row BIR rules, run before the delete for the same reason:
                     * a payee TIN eight digits long is the workbook's problem, and
                     * saying so now beats importing the year and blocking Generate
                     * DAT later. Nothing is written by this check.
                     */
                    $birIssues = app(ExpandedWtaxBirInfoPreflight::class)->check(
                        $file,
                        $endDate->toDateString(),
                        $withholdingAgent,
                        true,
                        'annual'
                    );

                    if ($birIssues !== []) {
                        return $this->expandedBirIssueResponse($birIssues, 'annual');
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
                        'Expanded withholding tax upload rejected. '.implode(' ', $issues)
                    );
                }

                $birIssues = app(ExpandedWtaxBirInfoPreflight::class)->check(
                    $file,
                    $reportingPeriod,
                    $withholdingAgent,
                    false,
                    'quarterly'
                );

                if ($birIssues !== []) {
                    return $this->expandedBirIssueResponse($birIssues, 'quarterly');
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
            $retainedContents = file_get_contents($file->getRealPath());

            if ($retainedContents === false) {
                throw new \RuntimeException('Unable to read the uploaded Purchase workbook.');
            }

            $preflight = $this->purchaseUploads->preflight($file, $reportingPeriod);

            if ($preflight['type_issues'] !== []) {
                return back()->with('error', implode(' ', $preflight['type_issues']));
            }

            $birIssues = $preflight['bir_issues'];

            if ($birIssues !== []) {
                $pending = $this->pendingPurchaseUploads->create(
                    $request->user(),
                    $file,
                    $reportingPeriod,
                    $birIssues,
                    $retainedContents
                );

                return back()
                    ->with('error', 'Purchase upload rejected. Fix supplier BIR info before importing.')
                    ->with('uploadIssueDialog', [
                        'title' => 'Purchase upload needs BIR info fixes',
                        'message' => 'Fix supplier BIR info before uploading this file.',
                        'summary' => count($birIssues).' issue(s) found. No records were imported or replaced.',
                        'record_type' => 'purchase',
                        'issues' => $birIssues,
                        'pending_upload' => $this->pendingPurchaseUploads->queue($pending, $birIssues),
                    ]);
            }

            $result = $this->purchaseUploads->replace($file, $reportingPeriod);

            $response = back()->with('success', 'Purchase VAT report for '.Carbon::parse($reportingPeriod)->format('F Y').' was replaced successfully.');

            if ($result['skipped_supplier_count'] > 0) {
                $response->with('warning', 'Purchase upload completed, but '.$result['skipped_supplier_count'].' BUREAU OF CUSTOMS row(s) were skipped because they are not included in RELIEF Purchase DAT.');
            }

            return $response;
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: '.$e->getMessage());
        }
    }

    /**
     * The rejection an invalid Expanded workbook gets: the same dialog Sales and
     * Purchase use, with the workbook itself named as the place to fix -- an
     * Expanded payee comes from the file, not from Customers or Suppliers.
     *
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function expandedBirIssueResponse(array $issues, string $reportType)
    {
        $rows = ExpandedWtaxBirInfoPreflight::affectedRows($issues);
        $label = $reportType === 'annual' ? 'annual upload' : 'upload';

        return back()
            ->with('error', 'Expanded WTAX '.$label.' rejected. Correct the workbook before importing.')
            ->with('uploadIssueDialog', [
                'title' => 'Expanded WTAX upload needs BIR info fixes',
                'message' => 'Correct the listed fields in the workbook and upload again.',
                'summary' => count($issues).' issue(s) found across '.$rows.' worksheet row(s). '
                    .'No records were imported or replaced.',
                'record_type' => 'expanded',
                'issues' => $issues,
            ]);
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
        if (! $this->isBrokerRecord($vatInput)) {
            return redirect('/records')->with('error', 'Only broker records can be edited.');
        }

        return Inertia::render('EditVatInputRecord', [
            'vatInput' => $vatInput,
        ]);
    }

    /**
     * The purchase row a transfer would land on, answered as the TIN is typed.
     *
     * This runs the same target search update() does, so the vendor fields the
     * edit screen fills in belong to the row the transfer will actually be added
     * to -- an uploaded vendor row as readily as an adjusted one. The JSON key
     * stays adjustedRecord: the edit screen reads it by that name.
     */
    public function adjustedLookup(Request $request, VatInput $vatInput)
    {
        if (! $this->isBrokerRecord($vatInput)) {
            abort(403, 'Only broker records can look up adjusted records.');
        }

        $tinNumber = $this->formatTin($request->input('tin_number'));
        $tinDigits = substr(preg_replace('/\D/', '', $tinNumber), 0, 9);

        if (strlen($tinDigits) < 9) {
            return response()->json([
                'adjustedRecord' => null,
            ]);
        }

        $targetRecord = $this->transferTargetQuery($vatInput, $tinDigits, $request->boolean('is_imported'))
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
            ->first();

        return response()->json([
            'adjustedRecord' => $targetRecord,
        ]);
    }

    /**
     * The one purchase row a broker transfer merges into.
     *
     * Matching is by month, imported bucket and the first nine TIN digits -- the
     * nine BIR files a vendor under, so a 9-digit entry and the same TIN with a
     * branch code are the same vendor here.
     *
     * Uploaded rows are ordered ahead of adjusted ones so a transfer joins the
     * vendor's real row instead of opening a parallel adjusted one beside it; an
     * adjusted row is the fallback, and creating one is the last resort. The
     * broker row being adjusted is never its own target.
     *
     * Importation mirrors are left out. They all carry the same importation TIN,
     * they are is_adjusted = false, so they would outrank every real vendor row --
     * and a transfer merged into one would vanish twice over: the purchase DAT
     * skips them, and ImportationEntryWriter::syncVatInput() rewrites the whole
     * row on the next importation edit.
     */
    private function transferTargetQuery(VatInput $vatInput, string $tinDigits, bool $isImported): Builder
    {
        return VatInput::query()
            ->excludingImportationMirrors()
            ->whereKeyNot($vatInput->getKey())
            ->where('is_imported', $isImported)
            ->whereDate('date_uploaded', $vatInput->getRawOriginal('date_uploaded'))
            ->whereRaw("SUBSTR(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 1, 9) = ?", [$tinDigits])
            ->orderBy('is_adjusted')
            ->orderBy('id');
    }

    /**
     * Adds a transfer to an existing target row and rebuilds its derived purchase
     * columns, so an uploaded vendor row and an adjusted row are added up by the
     * same arithmetic.
     *
     * $identity carries the submitted vendor information, which only an adjusted
     * row takes; it is empty for an uploaded row, which keeps the name, address
     * and flags it was uploaded with.
     */
    private function mergeTransferInto(VatInput $target, array $amounts, array $identity): void
    {
        $isAdjusted = (bool) $target->is_adjusted;

        $merged = [
            'purchase_imported' => round((float) $target->purchase_imported + $amounts['purchase_imported'], 2),
            'purchase_local' => round((float) $target->purchase_local + $amounts['purchase_local'], 2),
            'services' => round((float) $target->services + $amounts['services'], 2),
            'others' => round((float) $target->others + $amounts['others'], 2),
        ];
        $mergedTotal = round(array_sum($merged), 2);

        /*
         * Exempt and zero-rated purchases sit outside the four transferable
         * buckets but are still part of what the row totals. Both are zero on an
         * adjusted row, so this is the same figure as before there.
         */
        $rowTotal = round($mergedTotal + (float) $target->exempt + (float) $target->zero_rated, 2);

        $target->update([
            ...$identity,
            ...$merged,
            /*
             * An adjusted row carries no capital goods, as before. An uploaded row
             * does -- VatInputImport files an imported amount there -- and the
             * purchase DAT sums that column, so a transferred imported amount has
             * to land there too or it would drop out of the return.
             */
            'capital_goods' => $isAdjusted
                ? 0
                : round((float) $target->capital_goods + $amounts['purchase_imported'], 2),
            'other_than_capital_goods' => round($merged['purchase_local'] + $merged['others'], 2),
            'taxable_net_of_vat' => $mergedTotal,
            'vat_rate' => 12,
            'input_vat' => round($mergedTotal * 0.12, 2),
            'total_purchases' => $rowTotal,
            'total' => $rowTotal,
        ]);
    }

    public function update(Request $request, VatInput $vatInput)
    {
        if (! $this->isBrokerRecord($vatInput)) {
            return redirect('/records')->with('error', 'Only broker records can be edited.');
        }

        $request->merge([
            'tin_number' => $this->formatTin($request->input('tin_number')),
        ]);

        $validated = $request->validate([
            'supplier_name' => ['nullable', 'string', 'max:'.config('bir.field_limits.company_name')],
            'tin_number' => ['required', 'regex:/^(\d{9}|\d{12}|\d{3}-\d{3}-\d{3}|\d{3}-\d{3}-\d{3}-\d{3})$/'],
            'vendor_type' => ['required', 'in:company,individual'],
            'company_name' => ['nullable', 'string', 'max:'.config('bir.field_limits.company_name'), 'required_if:vendor_type,company'],
            'last_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'first_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'middle_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'address1' => ['nullable', 'string', 'max:'.config('bir.field_limits.address1')],
            'address2' => ['nullable', 'string', 'max:'.config('bir.field_limits.city')],
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
            $sourceRecord = VatInput::query()->lockForUpdate()->findOrFail($vatInput->id);

            if (! $this->isBrokerRecord($sourceRecord)) {
                throw ValidationException::withMessages([
                    'total' => 'Only broker records can be adjusted.',
                ]);
            }

            foreach ($amounts as $field => $amount) {
                if ($amount > (float) $sourceRecord->{$field}) {
                    throw ValidationException::withMessages([
                        $field => 'The broker balance changed. Review the available amounts and try again.',
                    ]);
                }
            }

            $supplierName = $validated['vendor_type'] === 'company'
                ? ($validated['company_name'] ?? $validated['supplier_name'] ?? '')
                : trim(($validated['last_name'] ?? '').' '.($validated['first_name'] ?? '').' '.($validated['middle_name'] ?? ''));
            $tinNumber = $this->formatTin($validated['tin_number']);
            $tinDigits = substr(preg_replace('/\D/', '', $tinNumber), 0, 9);
            $dateUploaded = $sourceRecord->getRawOriginal('date_uploaded');
            $isImported = (bool) $validated['is_imported'];

            $targetRecord = $this->transferTargetQuery($sourceRecord, $tinDigits, $isImported)
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

            if ($targetRecord) {
                /*
                 * A row that came from an upload keeps the identity it was uploaded
                 * with -- its name, address, is_broker and is_adjusted = false.
                 * Only an adjusted row, which this controller created in the first
                 * place, takes the submitted vendor information.
                 */
                $this->mergeTransferInto(
                    $targetRecord,
                    $amounts,
                    $targetRecord->is_adjusted ? $adjustedPayload : []
                );
            } else {
                $targetRecord = VatInput::create([
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

            PurchaseAdjustment::create([
                'source_vat_input_id' => $sourceRecord->id,
                'target_vat_input_id' => $targetRecord->id,
                ...$amounts,
            ]);

            $remaining = [
                'purchase_imported' => round((float) $sourceRecord->purchase_imported - $amounts['purchase_imported'], 2),
                'purchase_local' => round((float) $sourceRecord->purchase_local - $amounts['purchase_local'], 2),
                'services' => round((float) $sourceRecord->services - $amounts['services'], 2),
                'others' => round((float) $sourceRecord->others - $amounts['others'], 2),
            ];

            $sourceRecord->update([
                ...$remaining,
                'total' => array_sum($remaining),
                'is_broker' => true,
            ]);
        });

        return redirect("/records/{$vatInput->id}/edit")->with('success', 'VAT input record adjusted successfully.');
    }

    /**
     * The information the Purchase Records confirmation dialog needs.
     *
     * A row created before purchase_adjustments existed is not guessed back to a
     * broker. Instead, its untracked amounts and valid same-period broker choices
     * are returned so the user can explicitly map every cent before deletion.
     */
    public function adjustmentDeleteContext(VatInput $vatInput)
    {
        $this->guardAdjustedDeleteTarget($vatInput);

        $histories = PurchaseAdjustment::query()
            ->where('target_vat_input_id', $vatInput->id)
            ->get();
        $coverage = $this->adjustmentCoverage($vatInput, $histories);

        return response()->json([
            'status' => $coverage['invalid']
                ? 'invalid'
                : ($coverage['complete'] ? 'ready' : 'needs_link'),
            'message' => $coverage['invalid']
                ? 'Adjustment history exceeds the stored adjusted amounts. The record was not changed.'
                : null,
            'target_amounts' => $coverage['target'],
            'unresolved_amounts' => $coverage['unresolved'],
            'candidates' => $coverage['complete'] || $coverage['invalid']
                ? []
                : $this->adjustmentSourceCandidates($vatInput)->get()->map(fn (VatInput $candidate) => [
                    'id' => $candidate->id,
                    'supplier_name' => $candidate->supplier_name,
                    'tin_number' => $candidate->tin_number,
                    'purchase_imported' => $candidate->purchase_imported,
                    'purchase_local' => $candidate->purchase_local,
                    'services' => $candidate->services,
                    'others' => $candidate->others,
                ])->values(),
        ]);
    }

    /**
     * Restore every recorded transfer, then remove its adjusted target.
     *
     * All validation, optional legacy linking, source restoration and deletion
     * share one transaction. A missing cent or source rolls everything back.
     */
    public function destroyAdjusted(Request $request, VatInput $vatInput)
    {
        $validator = Validator::make($request->all(), [
            'allocations' => ['nullable', 'array'],
            'allocations.*.source_vat_input_id' => ['required', 'integer', 'distinct'],
            'allocations.*.purchase_imported' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.purchase_local' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.services' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.others' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        try {
            DB::transaction(function () use ($vatInput, $validator) {
                $target = VatInput::query()->lockForUpdate()->findOrFail($vatInput->id);
                $this->guardAdjustedDeleteTarget($target, true);

                $histories = PurchaseAdjustment::query()
                    ->where('target_vat_input_id', $target->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $coverage = $this->adjustmentCoverage($target, $histories);

                if ($coverage['invalid']) {
                    throw new \DomainException('Adjustment history exceeds the stored adjusted amounts. Nothing was changed.');
                }

                $allocations = collect($validator->validated()['allocations'] ?? []);

                if (! $coverage['complete']) {
                    if ($allocations->isEmpty()) {
                        throw new \DomainException('Link the complete untracked amount to its original broker record before deleting.');
                    }

                    $this->storeLegacyAdjustmentLinks($target, $coverage['unresolved_cents'], $allocations);

                    // Orphaned links point to a source deleted by a later upload.
                    // Their amounts were included in the unresolved balance above;
                    // replace them with the explicit mappings just supplied.
                    PurchaseAdjustment::query()
                        ->where('target_vat_input_id', $target->id)
                        ->whereNull('source_vat_input_id')
                        ->delete();

                    $histories = PurchaseAdjustment::query()
                        ->where('target_vat_input_id', $target->id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $coverage = $this->adjustmentCoverage($target, $histories);
                } elseif ($allocations->isNotEmpty()) {
                    throw new \DomainException('This adjusted record is already fully linked. Review and confirm the deletion again.');
                }

                if (! $coverage['complete'] || $coverage['invalid']) {
                    throw new \DomainException('The linked amounts do not fully match the adjusted record. Nothing was changed.');
                }

                $sourceIds = $histories->pluck('source_vat_input_id')->unique()->sort()->values();
                $sources = VatInput::query()
                    ->whereKey($sourceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($sources->count() !== $sourceIds->count()) {
                    throw new \DomainException('An original broker record is missing. Link the adjustment to the correct current broker record first.');
                }

                foreach ($histories->groupBy('source_vat_input_id') as $sourceId => $sourceHistories) {
                    $source = $sources->get((int) $sourceId);
                    $restored = [];
                    $restoredTotalCents = 0;

                    foreach (self::PURCHASE_ADJUSTMENT_FIELDS as $field) {
                        $fieldCents = $sourceHistories->sum(fn (PurchaseAdjustment $history) => $this->moneyToCents($history->{$field}));
                        $restored[$field] = $this->centsToMoney($this->moneyToCents($source->{$field}) + $fieldCents);
                        $restoredTotalCents += $fieldCents;
                    }

                    $restored['total'] = $this->centsToMoney(
                        $this->moneyToCents($source->total) + $restoredTotalCents
                    );

                    $source->update($restored);
                }

                $target->delete();
            });
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Adjusted Purchase record was undone and deleted. The amounts were returned to the original broker record.');
    }

    private function guardAdjustedDeleteTarget(VatInput $vatInput, bool $throw = false): void
    {
        $message = null;

        if (! $vatInput->is_adjusted) {
            $message = 'Only adjusted Purchase records can be deleted.';
        } elseif (ImportationEntry::query()->where('vat_input_id', $vatInput->id)->exists()) {
            $message = 'Importation-linked Purchase records cannot be deleted here.';
        }

        if ($message === null) {
            return;
        }

        if ($throw) {
            throw new \DomainException($message);
        }

        abort(403, $message);
    }

    /**
     * @param  Collection<int, PurchaseAdjustment>  $histories
     * @return array<string, mixed>
     */
    private function adjustmentCoverage(VatInput $target, $histories): array
    {
        $validHistories = $histories->whereNotNull('source_vat_input_id');
        $targetAmounts = [];
        $unresolvedAmounts = [];
        $unresolvedCents = [];
        $invalid = false;

        foreach (self::PURCHASE_ADJUSTMENT_FIELDS as $field) {
            $targetCents = $this->moneyToCents($target->{$field});
            $trackedCents = $validHistories->sum(
                fn (PurchaseAdjustment $history) => $this->moneyToCents($history->{$field})
            );
            $remainingCents = $targetCents - $trackedCents;

            if ($remainingCents < 0) {
                $invalid = true;
            }

            $targetAmounts[$field] = $this->centsToMoney($targetCents);
            $unresolvedCents[$field] = max($remainingCents, 0);
            $unresolvedAmounts[$field] = $this->centsToMoney(max($remainingCents, 0));
        }

        $complete = ! $invalid
            && $validHistories->isNotEmpty()
            && collect($unresolvedCents)->every(fn (int $amount) => $amount === 0)
            && $histories->whereNull('source_vat_input_id')->isEmpty();

        return [
            'complete' => $complete,
            'invalid' => $invalid,
            'target' => $targetAmounts,
            'unresolved' => $unresolvedAmounts,
            'unresolved_cents' => $unresolvedCents,
        ];
    }

    private function adjustmentSourceCandidates(VatInput $target): Builder
    {
        return VatInput::query()
            ->excludingImportationMirrors()
            ->whereKeyNot($target->id)
            ->where('is_adjusted', false)
            ->where('is_imported', (bool) $target->is_imported)
            ->whereDate('date_uploaded', $target->getRawOriginal('date_uploaded'))
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('brokers')
                    ->whereRaw("SUBSTR(REPLACE(REPLACE(REPLACE(brokers.tin_number, '-', ''), ' ', ''), '.', ''), 1, 9) = SUBSTR(REPLACE(REPLACE(REPLACE(vat_inputs.tin_number, '-', ''), ' ', ''), '.', ''), 1, 9)");
            })
            ->orderBy('supplier_name')
            ->orderBy('id');
    }

    /**
     * @param  array<string, int>  $unresolvedCents
     * @param  Collection<int, array<string, mixed>>  $allocations
     */
    private function storeLegacyAdjustmentLinks(VatInput $target, array $unresolvedCents, $allocations): void
    {
        $sourceIds = $allocations->pluck('source_vat_input_id')->map(fn ($id) => (int) $id);
        $candidates = $this->adjustmentSourceCandidates($target)
            ->whereKey($sourceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($candidates->count() !== $sourceIds->unique()->count()) {
            throw new \DomainException('Select only original broker records from the same month and Imported status.');
        }

        $allocatedCents = array_fill_keys(self::PURCHASE_ADJUSTMENT_FIELDS, 0);
        $normalized = [];

        foreach ($allocations as $allocation) {
            $sourceId = (int) $allocation['source_vat_input_id'];
            $row = [
                'source_vat_input_id' => $sourceId,
                'target_vat_input_id' => $target->id,
            ];
            $rowTotalCents = 0;

            foreach (self::PURCHASE_ADJUSTMENT_FIELDS as $field) {
                $cents = $this->moneyToCents($allocation[$field] ?? 0);
                $allocatedCents[$field] += $cents;
                $rowTotalCents += $cents;
                $row[$field] = $this->centsToMoney($cents);
            }

            if ($rowTotalCents <= 0) {
                throw new \DomainException('Each selected broker must receive at least one adjustment amount.');
            }

            $normalized[] = $row;
        }

        foreach (self::PURCHASE_ADJUSTMENT_FIELDS as $field) {
            if ($allocatedCents[$field] !== $unresolvedCents[$field]) {
                throw new \DomainException('The linked amounts must exactly equal every untracked adjusted amount.');
            }
        }

        foreach ($normalized as $row) {
            PurchaseAdjustment::create($row);
        }
    }

    private function moneyToCents(mixed $amount): int
    {
        return (int) round((float) ($amount ?? 0) * 100);
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function updateBirInfo(Request $request, VatInput $vatInput)
    {
        $request->merge([
            'tin_number' => $this->formatTin($request->input('tin_number')),
        ]);

        $validated = $request->validate([
            'vendor_type' => ['required', 'in:company,individual'],
            'tin_number' => ['required', 'regex:/^(\d{9}|\d{12}|\d{3}-\d{3}-\d{3}|\d{3}-\d{3}-\d{3}-\d{3})$/'],
            'company_name' => ['nullable', 'string', 'max:'.config('bir.field_limits.company_name'), 'required_if:vendor_type,company'],
            'last_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'first_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'middle_name' => ['nullable', 'string', 'max:255', 'required_if:vendor_type,individual'],
            'address1' => ['nullable', 'string', 'max:'.config('bir.field_limits.address1')],
            'address2' => ['nullable', 'string', 'max:'.config('bir.field_limits.city')],
        ]);

        if (substr(preg_replace('/\D/', '', $validated['tin_number']), 0, 9) === '000000000') {
            return back()
                ->withErrors(['tin_number' => 'TIN cannot start with 000000000.'])
                ->withInput();
        }

        $supplierName = $validated['vendor_type'] === 'company'
            ? $validated['company_name']
            : trim($validated['last_name'].' '.$validated['first_name'].' '.$validated['middle_name']);
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

        if (! $vatInput->tin_number) {
            return false;
        }

        $tin = substr(preg_replace('/\D/', '', (string) $vatInput->tin_number), 0, 9);

        if ($tin === '') {
            return false;
        }

        return Brokers::query()
            ->whereRaw("SUBSTR(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 1, 9) = ?", [$tin])
            ->exists();
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
