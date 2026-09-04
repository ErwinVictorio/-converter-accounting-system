<?php

namespace App\Http\Controllers;

use App\Models\VatInput;
use App\Models\ExpandedWtaxEntry;
use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\Supplier;
use App\Services\BIR\AnnualCoveredPeriodValidator;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use App\Services\BIR\BirImportationRowValidator;
use App\Services\BIR\BirPurchaseRowValidator;
use App\Services\BIR\BirSalesRowValidator;
use App\Services\BIR\ReliefExpandedWtaxAnnualDatGenerator;
use App\Services\BIR\ReliefExpandedWtaxDatGenerator;
use App\Services\BIR\ReliefImportationDatGenerator;
use App\Services\BIR\ReliefPurchaseDatGenerator;
use App\Services\BIR\ReliefSalesDatGenerator;
use App\Services\BIR\SalesSiCmConsolidator;
use App\Services\BIR\WithholdingCompanyDirectory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DatFileController extends Controller
{
    /**
     * Expanded WTAX is filed per withholding agent, so which company is selected
     * decides which rows are listed and which TIN, branch, registered name and RDO
     * reach the 1601EQ header. All of that comes from one directory, shared with the
     * upload screen -- see WithholdingCompanyDirectory.
     */
    public function __construct(
        private WithholdingCompanyDirectory $companies,
        private AnnualCoveredPeriodValidator $annualPeriod,
        private SalesSiCmConsolidator $salesConsolidator
    ) {
    }

    public function index(
        Request $request,
        BirPurchaseRowValidator $purchaseValidator,
        BirSalesRowValidator $salesValidator,
        BirImportationRowValidator $importationValidator,
        BirExpandedWtaxRowValidator $expandedValidator
    )
    {
        $recordType = $request->validate([
            'record_type' => ['nullable', 'in:purchase,sales,importation,expanded'],
            'report_type' => ['nullable', 'in:quarterly,annual'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'withholding_agent_tin' => ['nullable', 'regex:/^(\d{9}|\d{3}-\d{3}-\d{3})$/'],
            'withholding_agent_branch_code' => ['nullable', 'regex:/^\d{1,4}$/'],
        ])['record_type'] ?? 'purchase';
        $reportType = $recordType === 'expanded'
            ? $request->input('report_type', 'quarterly')
            : 'quarterly';
        $selectedWithholdingAgent = $this->selectedWithholdingAgent($request);
        $annualStartDate = Carbon::parse($request->input('start_date', now()->startOfYear()->toDateString()))->startOfDay();
        $annualEndDate = Carbon::parse($request->input('end_date', now()->endOfYear()->toDateString()))->endOfDay();

        if ($recordType === 'sales') {
            [$availablePeriods, $periodIssues] = $this->salesPeriods($salesValidator);
        } elseif ($recordType === 'importation') {
            [$availablePeriods, $periodIssues] = $this->importationPeriods($importationValidator);
        } elseif ($recordType === 'expanded') {
            [$availablePeriods, $periodIssues] = $this->expandedPeriods($expandedValidator, $selectedWithholdingAgent);
        } else {
            [$availablePeriods, $periodIssues] = $this->purchasePeriods($purchaseValidator);
        }

        return Inertia::render('GenerateDatFile', [
            'defaultCompany' => config('bir.companies.008791976'),
            'recordType' => $recordType,
            'reportType' => $reportType,
            'availablePeriods' => $availablePeriods,
            'periodIssues' => $periodIssues,
            'annualStartDate' => $annualStartDate->toDateString(),
            'annualEndDate' => $annualEndDate->toDateString(),
            'annualSummary' => $recordType === 'expanded'
                ? $this->expandedAnnualSummary($selectedWithholdingAgent, $annualStartDate, $annualEndDate)
                : ['records_count' => 0, 'invalid_count' => 0, 'errors' => []],
            'birCompanies' => $this->companies->activeCompanies(),
            'selectedWithholdingAgent' => $selectedWithholdingAgent,
        ]);
    }

    public function companyLookup(string $tin)
    {
        $tin = preg_replace('/\D/', '', $tin);
        $birTin = substr($tin, 0, 9);
        $company = config("bir.companies.{$tin}") ?: config("bir.companies.{$birTin}");

        if ($company) {
            return response()->json($company);
        }

        $vatInput = VatInput::query()
            ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 9) = ?", [$birTin])
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
            ->whereRaw("REPLACE(REPLACE(REPLACE(tin, '-', ''), ' ', ''), '.', '') = ?", [$tin])
            ->orWhereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin, '-', ''), ' ', ''), '.', ''), 9) = ?", [$birTin])
            ->first();

        if ($supplier) {
            return response()->json([
                'tin' => $tin,
                'name' => $supplier->name,
                'registered_name' => $supplier->name,
                'address1' => $supplier->addr ?: '',
                'address2' => $supplier->city ?: '',
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
        ReliefPurchaseDatGenerator $purchaseGenerator,
        ReliefSalesDatGenerator $salesGenerator,
        ReliefImportationDatGenerator $importationGenerator,
        ReliefExpandedWtaxDatGenerator $expandedGenerator,
        ReliefExpandedWtaxAnnualDatGenerator $expandedAnnualGenerator,
        BirPurchaseRowValidator $purchaseValidator,
        BirSalesRowValidator $salesValidator,
        BirImportationRowValidator $importationValidator,
        BirExpandedWtaxRowValidator $expandedValidator
    )
    {
        $validated = $request->validate([
            'record_type' => ['nullable', 'in:purchase,sales,importation,expanded'],
            'report_type' => ['nullable', 'in:quarterly,annual'],
            'period' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('record_type', 'purchase') !== 'expanded'
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
            'withholding_agent_tin' => ['nullable', 'regex:/^(\d{9}|\d{3}-\d{3}-\d{3})$/'],
            'withholding_agent_branch_code' => ['nullable', 'regex:/^\d{1,4}$/'],
        ]);

        $recordType = $validated['record_type'] ?? 'purchase';

        if ($recordType === 'expanded' && ($validated['report_type'] ?? 'quarterly') === 'annual') {
            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $endDate = Carbon::parse($validated['end_date'])->endOfDay();

            /*
             * The 1604E always carries the taxable year end, 12/31/YYYY, so the
             * selected period has to be that whole year. A partial selection is
             * refused rather than widened: filing seven months of payees in a
             * full-year return would understate it with nothing in the file to show
             * for it. See AnnualCoveredPeriodValidator.
             */
            $periodErrors = $this->annualPeriod->errors($startDate, $endDate);

            if ($periodErrors !== []) {
                return back()->withErrors($periodErrors);
            }

            return $this->downloadExpandedAnnual(
                $startDate,
                $endDate,
                $expandedAnnualGenerator,
                $expandedValidator,
                $this->selectedWithholdingAgent($request)
            );
        }

        $period = Carbon::parse($validated['period'])->endOfMonth();

        if ($recordType === 'sales') {
            return $this->downloadSales($period, $salesGenerator, $salesValidator);
        }

        if ($recordType === 'importation') {
            return $this->downloadImportation($period, $importationGenerator, $importationValidator);
        }

        if ($recordType === 'expanded') {
            return $this->downloadExpanded(
                $period,
                $expandedGenerator,
                $expandedValidator,
                $this->selectedWithholdingAgent($request)
            );
        }

        return $this->downloadPurchase($period, $purchaseGenerator, $purchaseValidator);
    }

    /**
     * Manual Importation entries are synced into vat_inputs so they stay visible
     * in the VAT input listing, but they belong in the Importation ("I") DAT, not
     * the Purchase ("P") one — reporting them in both would double-count the same
     * input VAT across two submitted schedules. Discriminate on the sync link, not
     * on vat_inputs.is_imported, which ordinary Excel uploads also set.
     *
     * The rule itself lives on VatInput::scopeExcludingImportationMirrors() so the
     * dashboard totals apply exactly the same exclusion.
     */
    private function purchasePeriods(BirPurchaseRowValidator $validator): array
    {
        $availablePeriods = VatInput::query()
            ->excludingImportationMirrors()
            ->selectRaw("DATE_FORMAT(date_uploaded, '%Y-%m') as value")
            ->selectRaw("DATE_FORMAT(date_uploaded, '%M %Y') as label")
            ->selectRaw('COUNT(*) as records_count')
            ->groupBy('value', 'label')
            ->orderByDesc('value')
            ->get();
        $periodIssues = [];

        VatInput::query()
            ->excludingImportationMirrors()
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

        return [$availablePeriods, $periodIssues];
    }

    private function salesPeriods(BirSalesRowValidator $validator): array
    {
        $availablePeriods = SalesVatInput::query()
            ->selectRaw("DATE_FORMAT(reporting_period, '%Y-%m') as value")
            ->selectRaw("DATE_FORMAT(reporting_period, '%M %Y') as label")
            ->selectRaw('COUNT(*) as records_count')
            ->whereNotNull('reporting_period')
            ->groupBy('value', 'label')
            ->orderByDesc('value')
            ->get();
        $periodIssues = [];

        SalesVatInput::query()
            ->whereNotNull('reporting_period')
            ->orderBy('reporting_period')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SalesVatInput $record) => $record->reporting_period->format('Y-m'))
            ->each(function ($records, string $period) use (&$periodIssues, $validator) {
                $errors = [];

                foreach ($this->salesConsolidator->consolidate($records)->values() as $index => $row) {
                    foreach ($validator->validate($row, $index + 2) as $error) {
                        $errors[] = "Sales group {$row['customer_name']}: {$error}";
                    }
                }

                $periodIssues[$period] = [
                    'invalid_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ];
            });

        return [$availablePeriods, $periodIssues];
    }

    private function importationPeriods(BirImportationRowValidator $validator): array
    {
        $availablePeriods = ImportationEntry::query()
            ->selectRaw("DATE_FORMAT(tax_month, '%Y-%m') as value")
            ->selectRaw("DATE_FORMAT(tax_month, '%M %Y') as label")
            ->selectRaw('COUNT(*) as records_count')
            ->groupBy('value', 'label')
            ->orderByDesc('value')
            ->get();
        $periodIssues = [];

        ImportationEntry::query()
            ->orderBy('tax_month')
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ImportationEntry $record) => $record->tax_month->format('Y-m'))
            ->each(function ($records, string $period) use (&$periodIssues, $validator) {
                $errors = [];

                foreach ($records->values() as $index => $record) {
                    foreach ($validator->validate($record->toBirImportationRow(), $index + 2) as $error) {
                        $errors[] = "Entry {$record->import_entry_no} {$record->supplier}: {$error}";
                    }
                }

                $periodIssues[$period] = [
                    'invalid_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ];
            });

        return [$availablePeriods, $periodIssues];
    }

    /**
     * Unlike its purchase/sales/importation siblings this groups in PHP instead of
     * with MySQL's DATE_FORMAT, so the listing works on any driver and can be
     * covered by the test suite, which runs on sqlite. It costs nothing extra: the
     * rows have to be loaded anyway to validate them.
     */
    private function expandedPeriods(BirExpandedWtaxRowValidator $validator, array $withholdingAgent): array
    {
        $availablePeriods = [];
        $periodIssues = [];

        $months = ExpandedWtaxEntry::query()
            ->where('withholding_agent_tin', $withholdingAgent['tin'])
            ->where('withholding_agent_branch_code', $withholdingAgent['branch_code'])
            ->where('report_type', 'quarterly')
            ->orderByDesc('reporting_period')
            ->orderBy('payee_name')
            ->orderBy('tax_rate')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ExpandedWtaxEntry $record) => $record->reporting_period->format('Y-m'));

        foreach ($months as $period => $records) {
            $availablePeriods[] = [
                'value' => $period,
                'label' => $records->first()->reporting_period->format('F Y'),
                'withholding_agent_tin' => $withholdingAgent['tin'],
                'withholding_agent_branch_code' => $withholdingAgent['branch_code'],
                'withholding_agent_name' => $withholdingAgent['name'],
                // The consolidated count, not the stored one, so the number on the
                // Generate DAT screen is the number of detail lines the file will
                // actually carry. Two uploaded lines for the same payee, ATC and rate
                // are one line in the DAT even when their TINs disagree, and the
                // screen should say so before the file is downloaded rather than after.
                'records_count' => ExpandedWtaxEntry::consolidate($records)->count(),
            ];

            // Validated per stored row, deliberately: an error names the line of the
            // workbook it came from, which a consolidated group could not.
            $errors = [];

            foreach ($records->values() as $index => $record) {
                foreach ($validator->validate($record->toBirExpandedRow(), $index + 2) as $error) {
                    $errors[] = "{$record->payee_name}: {$error}";
                }
            }

            $periodIssues[$period] = [
                'invalid_count' => count($errors),
                'errors' => array_slice($errors, 0, 10),
            ];
        }

        return [$availablePeriods, $periodIssues];
    }

    private function downloadPurchase(
        Carbon $period,
        ReliefPurchaseDatGenerator $generator,
        BirPurchaseRowValidator $validator
    ) {
        $records = VatInput::query()
            ->excludingImportationMirrors()
            ->whereBetween('date_uploaded', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('id')
            ->get()
            ->sort(fn (VatInput $a, VatInput $b) => $this->compareAlphabetically(
                $a->supplier_name,
                $b->supplier_name,
                $a->tin_number,
                $b->tin_number,
                $a->id,
                $b->id
            ))
            ->values();

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

    /**
     * The 1601EQ/QAP header carries withholding-agent identity plus return period
     * and RDO, so this needs less of the company record than the RELIEF schedules.
     */
    private function downloadExpanded(
        Carbon $period,
        ReliefExpandedWtaxDatGenerator $generator,
        BirExpandedWtaxRowValidator $validator,
        array $withholdingAgent
    ) {
        $records = ExpandedWtaxEntry::query()
            ->where('withholding_agent_tin', $withholdingAgent['tin'])
            ->where('withholding_agent_branch_code', $withholdingAgent['branch_code'])
            ->where('report_type', 'quarterly')
            ->whereBetween('reporting_period', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            // Filed in payee order, the way the reference DAT is arranged.
            ->orderBy('payee_name')
            ->orderBy('tax_rate')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'No expanded withholding tax records found for the selected company and reporting month.');
        }

        $rowErrors = [];
        foreach ($records as $index => $record) {
            foreach ($validator->validate($record->toBirExpandedRow(), $index + 2) as $error) {
                $rowErrors[] = "Record #{$record->id} {$record->payee_name}: {$error}";
            }
        }

        if ($rowErrors !== []) {
            return back()->with('error', 'Cannot generate DAT. Fix these expanded withholding tax rows first: ' . implode(' ', array_slice($rowErrors, 0, 5)));
        }

        /*
         * Validated as uploaded, then consolidated: rows sharing reporting month,
         * withholding agent, payee identity, ATC and rate become one DAT line with
         * the income payment and the tax amount summed. Checking the raw rows first
         * means each row is measured against the figures the workbook actually
         * stated.
         *
         * No re-sort afterwards, deliberately. consolidate() keeps the order rows
         * arrive in, so the payee_name / tax_rate ordering above carries through --
         * and a PHP re-sort would be wrong, because tax_rate reads back as a decimal
         * string where '10.00' sorts before '2.00'.
         */
        $records = ExpandedWtaxEntry::consolidate($records);

        $company = $this->companyForExpandedDownload($withholdingAgent);

        $content = $generator->generate($company, $records, $period);

        $fileName = $generator->filename($company, $period);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function downloadExpandedAnnual(
        Carbon $startDate,
        Carbon $endDate,
        ReliefExpandedWtaxAnnualDatGenerator $generator,
        BirExpandedWtaxRowValidator $validator,
        array $withholdingAgent
    ) {
        $records = ExpandedWtaxEntry::query()
            ->where('withholding_agent_tin', $withholdingAgent['tin'])
            ->where('withholding_agent_branch_code', $withholdingAgent['branch_code'])
            ->where('report_type', 'annual')
            ->whereBetween('reporting_period', [
                $startDate->copy()->startOfMonth()->toDateString(),
                $endDate->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('payee_name')
            ->orderBy('tax_rate')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'No annual expanded withholding tax records found for the selected company and covered period.');
        }

        $rowErrors = [];
        foreach ($records as $index => $record) {
            foreach ($validator->validate($record->toBirExpandedRow(), $index + 2) as $error) {
                $rowErrors[] = "Record #{$record->id} {$record->payee_name}: {$error}";
            }
        }

        if ($rowErrors !== []) {
            return back()->with('error', 'Cannot generate Annual DAT. Fix these expanded withholding tax rows first: ' . implode(' ', array_slice($rowErrors, 0, 5)));
        }

        $taxableYearEnd = $endDate->copy()->endOfYear();
        $records = $this->consolidateAnnualExpandedRecords($records, $taxableYearEnd);
        $company = $this->companyForExpandedDownload($withholdingAgent);
        $content = $generator->generate($company, $records, $taxableYearEnd);
        $fileName = $generator->filename($company, $taxableYearEnd);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function expandedAnnualSummary(array $withholdingAgent, Carbon $startDate, Carbon $endDate): array
    {
        if ($endDate->lessThan($startDate) || $startDate->year !== $endDate->year) {
            return [
                'records_count' => 0,
                'invalid_count' => 0,
                'errors' => [],
            ];
        }

        $records = ExpandedWtaxEntry::query()
            ->where('withholding_agent_tin', $withholdingAgent['tin'])
            ->where('withholding_agent_branch_code', $withholdingAgent['branch_code'])
            ->where('report_type', 'annual')
            ->whereBetween('reporting_period', [
                $startDate->copy()->startOfMonth()->toDateString(),
                $endDate->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('reporting_period')
            ->orderBy('payee_name')
            ->orderBy('tax_rate')
            ->orderBy('id')
            ->get();

        $errors = [];
        foreach ($records->values() as $index => $record) {
            foreach (app(BirExpandedWtaxRowValidator::class)->validate($record->toBirExpandedRow(), $index + 2) as $error) {
                $errors[] = "{$record->payee_name}: {$error}";
            }
        }

        return [
            'records_count' => $this->consolidateAnnualExpandedRecords($records, $endDate->copy()->endOfYear())->count(),
            'invalid_count' => count($errors),
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    private function consolidateAnnualExpandedRecords(Collection $records, Carbon $periodEnd): Collection
    {
        return ExpandedWtaxEntry::consolidate(
            $records->map(function (ExpandedWtaxEntry $record) use ($periodEnd) {
                $annualRecord = clone $record;
                $annualRecord->reporting_period = $periodEnd->copy()->endOfMonth();

                return $annualRecord;
            })
        );
    }

    /**
     * The withholding agent the request is about, defaulting to the first company
     * the directory offers when the request names none.
     */
    private function selectedWithholdingAgent(Request $request): array
    {
        return $this->companies->resolve(
            $request->input('withholding_agent_tin'),
            $request->input('withholding_agent_branch_code')
        );
    }

    /**
     * The header and control record details for the selected agent. Goes through
     * the directory rather than config so a managed company -- including a
     * deactivated one -- can still regenerate a month filed under it.
     */
    private function companyForExpandedDownload(array $withholdingAgent): array
    {
        return $this->companies->companyForDat($withholdingAgent);
    }

    private function compareAlphabetically(
        ?string $leftName,
        ?string $rightName,
        ?string $leftSecondary = null,
        ?string $rightSecondary = null,
        ?int $leftId = null,
        ?int $rightId = null
    ): int {
        return [
            $this->alphabeticalKey($leftName),
            $this->alphabeticalKey($leftSecondary),
            $leftId ?? 0,
        ] <=> [
            $this->alphabeticalKey($rightName),
            $this->alphabeticalKey($rightSecondary),
            $rightId ?? 0,
        ];
    }

    private function alphabeticalKey(?string $value): string
    {
        return preg_replace('/\s+/', ' ', strtoupper(trim((string) $value)));
    }

    private function downloadSales(
        Carbon $period,
        ReliefSalesDatGenerator $generator,
        BirSalesRowValidator $validator
    ) {
        $records = SalesVatInput::query()
            ->whereBetween('reporting_period', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'No Sales VAT records found for the selected reporting month.');
        }

        $salesRows = $this->salesConsolidator
            ->consolidate($records)
            ->sort(fn (array $a, array $b) => $this->compareAlphabetically(
                $a['company_name'] ?: $a['customer_name'],
                $b['company_name'] ?: $b['customer_name'],
                $a['customer_tin'],
                $b['customer_tin'],
                $a['id'],
                $b['id']
            ))
            ->values();
        $rowErrors = [];

        foreach ($salesRows as $index => $row) {
            foreach ($validator->validate($row, $index + 2) as $error) {
                $rowErrors[] = "Sales group {$row['customer_name']}: {$error}";
            }
        }

        if ($rowErrors !== []) {
            return back()->with('error', 'Cannot generate Sales DAT. Fix these sales rows first: ' . implode(' ', array_slice($rowErrors, 0, 5)));
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

        $content = $generator->generate($company, $salesRows, $period);
        $fileName = $generator->filename($company, $period);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function downloadImportation(
        Carbon $period,
        ReliefImportationDatGenerator $generator,
        BirImportationRowValidator $validator
    ) {
        $records = ImportationEntry::query()
            ->whereBetween('tax_month', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get()
            ->sort(fn (ImportationEntry $a, ImportationEntry $b) => $this->compareAlphabetically(
                $a->supplier,
                $b->supplier,
                $a->import_entry_no,
                $b->import_entry_no,
                $a->id,
                $b->id
            ))
            ->values();

        if ($records->isEmpty()) {
            return back()->with('error', 'No importation records found for the selected reporting month.');
        }

        $rowErrors = [];

        foreach ($records as $index => $record) {
            foreach ($validator->validate($record->toBirImportationRow(), $index + 2) as $error) {
                $rowErrors[] = "Entry {$record->import_entry_no} {$record->supplier}: {$error}";
            }
        }

        if ($rowErrors !== []) {
            return back()->with('error', 'Cannot generate Importation DAT. Fix these entries first: ' . implode(' ', array_slice($rowErrors, 0, 5)));
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
            $records->map(fn (ImportationEntry $record) => $record->toBirImportationRow()),
            $period
        );

        $fileName = $generator->filename($company, $period);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

}
