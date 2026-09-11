<?php

namespace App\Http\Controllers;

use App\Models\Brokers;
use App\Models\Customer;
use App\Models\ExpandedWtaxEntry;
use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\Supplier;
use App\Models\VatInput;
use App\Models\WithholdingCompany;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use App\Services\BIR\SalesSiCmConsolidator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViewInfoController extends Controller
{
    public function show(
        Request $request,
        string $resource,
        string $id,
        SalesSiCmConsolidator $salesConsolidator,
        BirExpandedWtaxRowValidator $expandedValidator
    ): JsonResponse {
        $payload = match ($resource) {
            'purchase' => $this->purchase($id),
            'sales' => $this->sales($request, $id, $salesConsolidator),
            'expanded-wtax' => $this->expandedWtax($id, $expandedValidator),
            'importation' => $this->importation($id),
            'supplier' => $this->supplier($id),
            'customer' => $this->customer($id),
            'broker' => $this->broker($id),
            'withholding-company' => $this->withholdingCompany($id),
            default => abort(404),
        };

        return response()->json(['resource' => $resource] + $payload + [
            'source_records' => $payload['source_records'] ?? [],
        ]);
    }

    private function purchase(string $id): array
    {
        $record = VatInput::query()->findOrFail($id);
        $baseTin = $this->baseTin($record->tin_number);
        $brokerEligible = ! $record->is_adjusted
            && $baseTin !== ''
            && Brokers::query()->get(['tin_number'])->contains(
                fn (Brokers $broker) => $this->baseTin($broker->tin_number) === $baseTin
            );

        $displayTotal = (
            (float) $record->purchase_local
            + (float) $record->services
            + (float) $record->others
        ) / 0.12;

        return [
            'title' => 'Purchase Information',
            'subtitle' => (string) $record->supplier_name,
            'sections' => [
                $this->section('Vendor and BIR Information', [
                    $this->field('supplier_name', 'Supplier Name', $record->supplier_name),
                    $this->field('tin_number', 'TIN Number', $record->tin_number, 'identifier'),
                    $this->field('vendor_type', 'Vendor Type', $record->vendor_type, 'badge'),
                    $this->field('company_name', 'Company Name', $record->company_name),
                    $this->field('last_name', 'Last Name', $record->last_name),
                    $this->field('first_name', 'First Name', $record->first_name),
                    $this->field('middle_name', 'Middle Name', $record->middle_name),
                    $this->field('address1', 'Address 1', $record->address1, 'multiline'),
                    $this->field('address2', 'City / Address 2', $record->address2, 'multiline'),
                ]),
                $this->section('Purchase Classification and Amounts', [
                    $this->field('is_imported', 'Imported', (bool) $record->is_imported, 'boolean'),
                    $this->field('exempt', 'Exempt', $record->exempt, 'money'),
                    $this->field('zero_rated', 'Zero Rated', $record->zero_rated, 'money'),
                    $this->field('purchase_imported', 'Purchase Imported', $record->purchase_imported, 'money'),
                    $this->field('purchase_local', 'Purchase Local', $record->purchase_local, 'money'),
                    $this->field('services', 'Services', $record->services, 'money'),
                    $this->field('capital_goods', 'Capital Goods', $record->capital_goods, 'money'),
                    $this->field('other_than_capital_goods', 'Other Than Capital Goods', $record->other_than_capital_goods, 'money'),
                    $this->field('others', 'Others', $record->others, 'money'),
                    $this->field('taxable_net_of_vat', 'Taxable Net of VAT', $record->taxable_net_of_vat, 'money'),
                    $this->field('vat_rate', 'VAT Rate', $record->vat_rate, 'percentage'),
                    $this->field('input_vat', 'Input VAT', $record->input_vat, 'money'),
                    $this->field('total_purchases', 'Total Purchases', $record->total_purchases, 'money'),
                    $this->field('total', 'Stored Total', $record->total, 'money'),
                    $this->field('display_calculated_total', 'Display-Calculated Total', $displayTotal, 'money'),
                ]),
                $this->section('Record Status', [
                    $this->field('date_uploaded', 'Date Uploaded', $this->date($record->date_uploaded), 'date'),
                    $this->field('stored_is_broker', 'Stored Broker Flag', (bool) $record->getRawOriginal('is_broker'), 'boolean'),
                    $this->field('broker_eligible', 'Broker Eligible', $brokerEligible, 'boolean'),
                    $this->field('is_adjusted', 'Adjusted', (bool) $record->is_adjusted, 'boolean'),
                ]),
                $this->systemSection($record->id, $record->created_at, $record->updated_at),
            ],
        ];
    }

    private function sales(Request $request, string $id, SalesSiCmConsolidator $consolidator): array
    {
        $anchor = SalesVatInput::query()->findOrFail($id);
        $period = $this->period($request->query('period'));

        $candidates = SalesVatInput::query()
            ->when($period, fn ($query, Carbon $month) => $query->whereBetween('reporting_period', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ]))
            ->orderBy('document_date')
            ->orderBy('document_no')
            ->orderBy('id')
            ->get();

        $identity = $consolidator->identityKey($anchor);
        $sources = $candidates
            ->filter(fn (SalesVatInput $record) => $consolidator->identityKey($record) === $identity)
            ->values();

        abort_unless($sources->contains(fn (SalesVatInput $record) => $record->is($anchor)), 404);

        $summary = $consolidator->consolidate($sources)->firstOrFail();

        return [
            'title' => 'Sales Information',
            'subtitle' => (string) ($summary['customer_name'] ?? $anchor->customer_name),
            'sections' => [
                $this->section('Consolidated Summary', [
                    $this->field('records_count', 'Records Count', $summary['records_count'], 'identifier'),
                    $this->field('si_count', 'SI Rows', $summary['si_count'], 'identifier'),
                    $this->field('cm_count', 'CM Rows', $summary['cm_count'], 'identifier'),
                    $this->field('customer_type', 'Customer Type', $summary['customer_type'], 'badge'),
                    $this->field('customer_tin', 'Customer TIN', $summary['customer_tin'], 'identifier'),
                    $this->field('customer_name', 'Customer Name', $summary['customer_name']),
                    $this->field('company_name', 'Company Name', $summary['company_name']),
                    $this->field('last_name', 'Last Name', $summary['last_name']),
                    $this->field('first_name', 'First Name', $summary['first_name']),
                    $this->field('middle_name', 'Middle Name', $summary['middle_name']),
                    $this->field('address1', 'Address 1', $summary['address1'], 'multiline'),
                    $this->field('address2', 'City / Address 2', $summary['address2'], 'multiline'),
                    $this->field('exempt_sales', 'Exempt Sales', $summary['exempt_sales'], 'money'),
                    $this->field('zero_rated_sales', 'Zero-Rated Sales', $summary['zero_rated_sales'], 'money'),
                    $this->field('taxable_net_of_vat', 'Taxable Net of VAT', $summary['taxable_net_of_vat'], 'money'),
                    $this->field('output_vat', 'Output VAT', $summary['output_vat'], 'money'),
                    $this->field('net_amount', 'Net Amount', $summary['net_amount'], 'money'),
                    $this->field('gross_amount', 'Gross Amount', $summary['gross_amount'], 'money'),
                    $this->field('si_taxable_sales', 'SI Taxable Sales', $summary['si_taxable_sales'], 'money'),
                    $this->field('cm_taxable_sales', 'CM Taxable Sales', $summary['cm_taxable_sales'], 'money'),
                    $this->field('si_output_vat', 'SI Output VAT', $summary['si_output_vat'], 'money'),
                    $this->field('cm_output_vat', 'CM Output VAT', $summary['cm_output_vat'], 'money'),
                    $this->field('period_scope', 'Reporting Period Scope', $period?->format('Y-m') ?? 'All available periods', 'badge'),
                ]),
            ],
            'source_records' => $sources->map(fn (SalesVatInput $record) => [
                'id' => $record->id,
                'title' => trim(($record->document_type ?: 'Document').' '.($record->document_no ?: '#'.$record->id)),
                'sections' => $this->salesSourceSections($record),
            ])->all(),
        ];
    }

    private function expandedWtax(string $id, BirExpandedWtaxRowValidator $validator): array
    {
        $anchor = ExpandedWtaxEntry::query()->findOrFail($id);

        $candidates = ExpandedWtaxEntry::query()
            ->whereDate('reporting_period', $anchor->reporting_period->toDateString())
            ->where('report_type', $anchor->report_type ?: 'quarterly')
            ->where('withholding_agent_tin', $anchor->withholding_agent_tin)
            ->where('withholding_agent_branch_code', $anchor->withholding_agent_branch_code)
            ->where('tax_rate', $anchor->tax_rate)
            ->when(
                $anchor->atc_code === null,
                fn ($query) => $query->whereNull('atc_code'),
                fn ($query) => $query->where('atc_code', $anchor->atc_code)
            )
            ->orderBy('id')
            ->get();

        $groupKey = ExpandedWtaxEntry::consolidationKey($anchor);
        $sources = $candidates
            ->filter(fn (ExpandedWtaxEntry $record) => ExpandedWtaxEntry::consolidationKey($record) === $groupKey)
            ->values();
        $summary = ExpandedWtaxEntry::consolidate($sources)->firstOrFail();
        $errors = $validator->validate($summary, 0);
        $validationErrors = array_map(
            fn (string $error) => preg_replace('/^Row \d+: /', '', $error),
            $errors
        );
        $hasMissingId = collect($errors)->contains(
            fn (string $error) => str_contains($error, 'payee_tin must contain at least 9 digits')
                || str_contains($error, 'payee_tin cannot be 000000000')
        );

        return [
            'title' => 'Expanded WTAX Information',
            'subtitle' => (string) $summary['payee_name'],
            'sections' => [
                $this->section('Consolidated Summary', [
                    $this->field('payee_name', 'Payee Name', $summary['payee_name']),
                    $this->field('payee_type', 'Payee Type', $summary['payee_type'], 'badge'),
                    $this->field('company_name', 'Company Name', $summary['company_name']),
                    $this->field('last_name', 'Last Name', $summary['last_name']),
                    $this->field('first_name', 'First Name', $summary['first_name']),
                    $this->field('middle_name', 'Middle Name', $summary['middle_name']),
                    $this->field('payee_tin', 'Payee TIN', $summary['payee_tin'], 'identifier'),
                    $this->field('payee_branch_code', 'Payee Branch Code', $summary['payee_branch_code'], 'identifier'),
                    $this->field('withholding_agent_name', 'Withholding Agent Name', $summary['withholding_agent_name']),
                    $this->field('withholding_agent_tin', 'Withholding Agent TIN', $summary['withholding_agent_tin'], 'identifier'),
                    $this->field('withholding_agent_branch_code', 'Withholding Agent Branch Code', $summary['withholding_agent_branch_code'], 'identifier'),
                    $this->field('atc_code', 'ATC Code', $summary['atc_code'], 'identifier'),
                    $this->field('tax_rate', 'Tax Rate', $summary['tax_rate'], 'percentage'),
                    $this->field('income_payment', 'Income Payment', $summary['income_payment'], 'money'),
                    $this->field('tax_withheld', 'Tax Withheld', $summary['tax_withheld'], 'money'),
                    $this->field('reporting_period', 'Reporting Period', $summary['reporting_period'], 'date'),
                    $this->field('report_type', 'Report Type', $summary['report_type'], 'badge'),
                    $this->field('merged_rows', 'Merged Rows', $summary['merged_rows'], 'identifier'),
                ]),
                $this->section('Validation and Consolidation Details', [
                    $this->field('status', 'Status', count($errors) > 0 ? 'Needs BIR Info' : 'Ready', 'badge'),
                    $this->field('validation_errors', 'Validation Errors', $validationErrors, 'multiline'),
                    $this->field('has_missing_id', 'Missing ID / TIN', $hasMissingId, 'boolean'),
                    $this->field('has_multiple_payee_tins', 'Multiple Payee TINs', $summary['has_multiple_payee_tins'], 'boolean'),
                    $this->field('distinct_payee_tins', 'Distinct Payee TINs', $summary['distinct_payee_tins'], 'multiline'),
                    $this->field('has_multiple_payee_branch_codes', 'Multiple Payee Branch Codes', $summary['has_multiple_payee_branch_codes'], 'boolean'),
                    $this->field('distinct_payee_branch_codes', 'Distinct Payee Branch Codes', $summary['distinct_payee_branch_codes'], 'multiline'),
                ]),
            ],
            'source_records' => $sources->map(fn (ExpandedWtaxEntry $record) => [
                'id' => $record->id,
                'title' => 'Source Record #'.$record->id,
                'sections' => $this->expandedSourceSections($record),
            ])->all(),
        ];
    }

    private function importation(string $id): array
    {
        $record = ImportationEntry::query()->findOrFail($id);

        return [
            'title' => 'Importation Information',
            'subtitle' => (string) $record->import_entry_no,
            'sections' => [
                $this->section('Importation Reference', [
                    $this->field('sequence_number', 'Sequence Number', $record->sequence_number, 'identifier'),
                    $this->field('tax_month', 'Tax Month', $this->date($record->tax_month), 'date'),
                    $this->field('import_entry_no', 'Import Entry Number', $record->import_entry_no, 'identifier'),
                    $this->field('assessment_date', 'Assessment Date', $this->date($record->assessment_date), 'date'),
                    $this->field('importation_date', 'Importation Date', $this->date($record->importation_date), 'date'),
                    $this->field('supplier', 'Supplier / Name of Seller', $record->supplier),
                    $this->field('country', 'Country', $record->country),
                ]),
                $this->section('Amounts and VAT Payment', [
                    $this->field('total_landed_cost', 'Total Landed Cost', $record->total_landed_cost, 'money'),
                    $this->field('dutiable_value', 'Dutiable Value', $record->dutiable_value, 'money'),
                    $this->field('charges', 'Charges', $record->charges, 'money'),
                    $this->field('exempt', 'Exempt', $record->exempt, 'money'),
                    $this->field('taxable_goods', 'Taxable Goods', $record->taxable_goods, 'money'),
                    $this->field('vat_rate', 'VAT Rate', $record->vat_rate, 'percentage'),
                    $this->field('vat_payable', 'VAT Payable', $record->vat_payable, 'money'),
                    $this->field('or_number', 'OR Number', $record->or_number, 'identifier'),
                    $this->field('payment_date', 'Payment Date', $this->date($record->payment_date), 'date'),
                ]),
                $this->systemSection($record->id, $record->created_at, $record->updated_at, [
                    $this->field('vat_input_id', 'Linked Purchase VAT Input ID', $record->vat_input_id, 'identifier'),
                ]),
            ],
        ];
    }

    private function supplier(string $id): array
    {
        $record = Supplier::query()->findOrFail($id);

        return [
            'title' => 'Supplier Information',
            'subtitle' => (string) $record->name,
            'sections' => [
                $this->section('Supplier Information', [
                    $this->field('tin', 'TIN', $record->tin, 'identifier'),
                    $this->field('name', 'Supplier Name', $record->name),
                    $this->field('addr', 'Address', $record->addr, 'multiline'),
                    $this->field('city', 'City', $record->city),
                ]),
                $this->systemSection($record->id, $record->created_at, $record->updated_at, [], 'Supplier ID'),
            ],
        ];
    }

    private function customer(string $id): array
    {
        $record = Customer::query()->findOrFail($id);

        return [
            'title' => 'Customer Information',
            'subtitle' => (string) $record->name,
            'sections' => [
                $this->section('Customer Information', [
                    $this->field('tin', 'TIN', $record->tin, 'identifier'),
                    $this->field('name', 'Customer Name', $record->name),
                    $this->field('addr', 'Address', $record->addr, 'multiline'),
                    $this->field('city', 'City', $record->city),
                ]),
                $this->section('Matching Information', [
                    $this->field('name_key', 'Normalized Name Key', $record->name_key, 'identifier'),
                ]),
                $this->systemSection($record->id, $record->created_at, $record->updated_at, [], 'Customer ID'),
            ],
        ];
    }

    private function broker(string $id): array
    {
        $record = Brokers::query()->findOrFail($id);

        return [
            'title' => 'Broker Information',
            'subtitle' => (string) $record->broker_name,
            'sections' => [
                $this->section('Broker Information', [
                    $this->field('broker_name', 'Broker Name', $record->broker_name),
                    $this->field('tin_number', 'TIN', $record->tin_number, 'identifier'),
                ]),
                $this->systemSection($record->id, $record->created_at, $record->updated_at, [], 'Broker ID'),
            ],
        ];
    }

    private function withholdingCompany(string $id): array
    {
        $record = WithholdingCompany::query()->findOrFail($id);
        $hasFiledRows = $record->hasFiledRows();

        return [
            'title' => 'Withholding Company Information',
            'subtitle' => (string) $record->registered_name,
            'sections' => [
                $this->section('Company Identity', [
                    $this->field('registered_name', 'Registered Name', $record->registered_name),
                    $this->field('trade_name', 'Trade Name', $record->trade_name),
                    $this->field('tin', 'TIN', $record->tin, 'identifier'),
                    $this->field('branch_code', 'Branch Code', $record->branch_code, 'identifier'),
                    $this->field('label', 'Directory Label', $record->label),
                ]),
                $this->section('Registration and Address', [
                    $this->field('rdo_code', 'RDO Code', $record->rdo_code, 'identifier'),
                    $this->field('address1', 'Address 1', $record->address1, 'multiline'),
                    $this->field('address2', 'Address 2 / City', $record->address2, 'multiline'),
                ]),
                $this->section('Status', [
                    $this->field('is_active', 'Active', (bool) $record->is_active, 'boolean'),
                    $this->field('has_filed_rows', 'Has Filed Rows', $hasFiledRows, 'boolean'),
                    $this->field(
                        'identity_status',
                        'Identity Status',
                        $hasFiledRows ? 'Locked because Expanded WTAX records were filed under this identity.' : 'Editable',
                        'badge'
                    ),
                ]),
                $this->systemSection($record->id, $record->created_at, $record->updated_at, [], 'Company ID'),
            ],
        ];
    }

    private function salesSourceSections(SalesVatInput $record): array
    {
        return [
            $this->section('Document Information', [
                $this->field('id', 'Record ID', $record->id, 'identifier'),
                $this->field('document_no', 'Document Number', $record->document_no, 'identifier'),
                $this->field('document_type', 'Document Type', $record->document_type, 'badge'),
                $this->field('document_date', 'Document Date', $this->date($record->document_date), 'date'),
                $this->field('terms', 'Terms', $record->terms),
                $this->field('days', 'Days', $record->days, 'identifier'),
                $this->field('due_date', 'Due Date', $this->date($record->due_date), 'date'),
                $this->field('agent_name', 'Agent Name', $record->agent_name),
                $this->field('document_refs', 'Document References', $record->document_refs, 'multiline'),
            ]),
            $this->section('Customer and BIR Information', [
                $this->field('customer_name', 'Customer Name', $record->customer_name),
                $this->field('customer_tin', 'Customer TIN', $record->customer_tin, 'identifier'),
                $this->field('customer_type', 'Customer Type', $record->customer_type, 'badge'),
                $this->field('company_name', 'Company Name', $record->company_name),
                $this->field('last_name', 'Last Name', $record->last_name),
                $this->field('first_name', 'First Name', $record->first_name),
                $this->field('middle_name', 'Middle Name', $record->middle_name),
                $this->field('address1', 'Address 1', $record->address1, 'multiline'),
                $this->field('address2', 'Address 2 / City', $record->address2, 'multiline'),
            ]),
            $this->section('Amounts and Status', [
                $this->field('gross_amount', 'Gross Amount', $record->gross_amount, 'money'),
                $this->field('discount', 'Discount', $record->discount, 'money'),
                $this->field('charges', 'Charges', $record->charges, 'money'),
                $this->field('net_amount', 'Net Amount', $record->net_amount, 'money'),
                $this->field('output_vat', 'Output VAT', $record->output_vat, 'money'),
                $this->field('taxable_net_of_vat', 'Taxable Net of VAT', $record->taxable_net_of_vat, 'money'),
                $this->field('exempt_sales', 'Exempt Sales', $record->exempt_sales, 'money'),
                $this->field('zero_rated_sales', 'Zero-Rated Sales', $record->zero_rated_sales, 'money'),
                $this->field('s_zero_rated', 'S Zero-Rated Classification', (bool) $record->s_zero_rated, 'boolean'),
                $this->field('reporting_period', 'Reporting Period', $this->date($record->reporting_period), 'date'),
                $this->field('is_adjusted', 'Adjusted', (bool) $record->is_adjusted, 'boolean'),
                $this->field('created_at', 'Created At', $this->dateTime($record->created_at), 'datetime'),
                $this->field('updated_at', 'Updated At', $this->dateTime($record->updated_at), 'datetime'),
            ]),
        ];
    }

    private function expandedSourceSections(ExpandedWtaxEntry $record): array
    {
        return [
            $this->section('Filing and Agent Information', [
                $this->field('id', 'Record ID', $record->id, 'identifier'),
                $this->field('reporting_period', 'Reporting Period', $this->date($record->reporting_period), 'date'),
                $this->field('report_type', 'Report Type', $record->report_type, 'badge'),
                $this->field('withholding_agent_name', 'Withholding Agent Name', $record->withholding_agent_name),
                $this->field('withholding_agent_tin', 'Withholding Agent TIN', $record->withholding_agent_tin, 'identifier'),
                $this->field('withholding_agent_branch_code', 'Withholding Agent Branch Code', $record->withholding_agent_branch_code, 'identifier'),
            ]),
            $this->section('Payee Information', [
                $this->field('payee_name', 'Payee Name', $record->payee_name),
                $this->field('payee_type', 'Payee Type', $record->payee_type, 'badge'),
                $this->field('payee_tin', 'Payee TIN', $record->payee_tin, 'identifier'),
                $this->field('payee_branch_code', 'Payee Branch Code', $record->payee_branch_code, 'identifier'),
                $this->field('company_name', 'Company Name', $record->company_name),
                $this->field('last_name', 'Last Name', $record->last_name),
                $this->field('first_name', 'First Name', $record->first_name),
                $this->field('middle_name', 'Middle Name', $record->middle_name),
            ]),
            $this->section('Tax and System Information', [
                $this->field('atc_code', 'ATC Code', $record->atc_code, 'identifier'),
                $this->field('tax_rate', 'Tax Rate', $record->tax_rate, 'percentage'),
                $this->field('income_payment', 'Income Payment', $record->income_payment, 'money'),
                $this->field('tax_withheld', 'Tax Withheld', $record->tax_withheld, 'money'),
                $this->field('created_at', 'Created At', $this->dateTime($record->created_at), 'datetime'),
                $this->field('updated_at', 'Updated At', $this->dateTime($record->updated_at), 'datetime'),
            ]),
        ];
    }

    private function systemSection(
        mixed $id,
        ?CarbonInterface $createdAt,
        ?CarbonInterface $updatedAt,
        array $extra = [],
        string $idLabel = 'Record ID'
    ): array {
        return $this->section('System Information', array_merge([
            $this->field('id', $idLabel, $id, 'identifier'),
        ], $extra, [
            $this->field('created_at', 'Created At', $this->dateTime($createdAt), 'datetime'),
            $this->field('updated_at', 'Updated At', $this->dateTime($updatedAt), 'datetime'),
        ]));
    }

    private function section(string $title, array $fields): array
    {
        return compact('title', 'fields');
    }

    private function field(string $key, string $label, mixed $value, string $type = 'text'): array
    {
        return compact('key', 'label', 'value', 'type');
    }

    private function period(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        abort_unless(is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value), 422);

        try {
            $month = Carbon::createFromFormat('!Y-m', $value);
            abort_unless($month && $month->format('Y-m') === $value, 422);

            return $month->startOfMonth();
        } catch (\Throwable) {
            abort(422);
        }
    }

    private function baseTin(?string $value): string
    {
        return substr(preg_replace('/\D/', '', (string) $value), 0, 9);
    }

    private function date(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }

    private function dateTime(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }
}
