<?php

namespace App\Services\BIR;

use App\Models\ImportationEntry;
use App\Models\VatInput;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DatAttachmentReportBuilder
{
    public function build(string $recordType, Collection $records, array $company, Carbon $period): array
    {
        return match ($recordType) {
            'sales' => $this->sales($records, $company, $period),
            'importation' => $this->importation($records, $company, $period),
            default => $this->purchase($records, $company, $period),
        };
    }

    private function purchase(Collection $records, array $company, Carbon $period): array
    {
        $columns = [
            'Taxpayer Identification Number',
            'Registered Name',
            'Name of Supplier',
            "Supplier's Address",
            'Amount of Gross Purchase',
            'Amount of Exempt Purchase',
            'Amount of Zero-Rated Purchase',
            'Amount of Taxable Purchase',
            'Amount of Purchase of Services',
            'Amount of Purchase of Capital Goods',
            'Amount of Purchase of Goods Other Than Capital Goods',
            'Amount of Input Tax',
            'Amount of Gross Taxable Purchase',
        ];

        $rows = $records->map(function (VatInput $record) {
            $row = $record->toBirPurchaseRow();
            $taxablePurchase = $this->amount($row, 'services')
                + $this->amount($row, 'capital_goods')
                + $this->amount($row, 'other_than_capital_goods');
            $grossPurchase = $this->amount($row, 'exempt')
                + $this->amount($row, 'zero_rated')
                + $taxablePurchase;
            $grossTaxablePurchase = $taxablePurchase + $this->amount($row, 'input_vat');
            [$registeredName, $displayName] = $this->partyNames(
                (string) ($row['vendor_type'] ?? 'company'),
                $row['company_name'] ?? '',
                $row['last_name'] ?? '',
                $row['first_name'] ?? '',
                $row['middle_name'] ?? ''
            );

            return [
                $this->tin((string) ($row['vendor_tin'] ?? '')),
                $registeredName,
                $displayName,
                $this->address($row['address1'] ?? '', $row['address2'] ?? ''),
                $this->money($grossPurchase),
                $this->money($this->amount($row, 'exempt')),
                $this->money($this->amount($row, 'zero_rated')),
                $this->money($taxablePurchase),
                $this->money($this->amount($row, 'services')),
                $this->money($this->amount($row, 'capital_goods')),
                $this->money($this->amount($row, 'other_than_capital_goods')),
                $this->money($this->amount($row, 'input_vat')),
                $this->money($grossTaxablePurchase),
            ];
        })->values();

        return $this->report('PURCHASE TRANSACTION', $columns, $rows, $company, $period);
    }

    private function sales(Collection $records, array $company, Carbon $period): array
    {
        $columns = [
            'Taxpayer Identification Number',
            'Registered Name',
            'Name of Customer',
            "Customer's Address",
            'Amount of Gross Sales',
            'Amount of Exempt Sales',
            'Amount of Zero Rated Sales',
            'Amount of Taxable Sales',
            'Amount of Output Tax',
            'Amount of Gross Taxable Sales',
        ];

        $rows = $records->map(function (array $row) {
            $grossSales = $this->amount($row, 'exempt_sales')
                + $this->amount($row, 'zero_rated_sales')
                + $this->amount($row, 'taxable_sales');
            $grossTaxableSales = $this->amount($row, 'taxable_sales')
                + $this->amount($row, 'output_vat');
            [$registeredName, $displayName] = $this->partyNames(
                (string) ($row['customer_type'] ?? 'company'),
                $row['company_name'] ?? '',
                $row['last_name'] ?? '',
                $row['first_name'] ?? '',
                $row['middle_name'] ?? ''
            );

            return [
                $this->tin((string) ($row['customer_tin'] ?? '')),
                $registeredName,
                $displayName,
                $this->address($row['address1'] ?? '', $row['address2'] ?? ''),
                $this->money($grossSales),
                $this->money($this->amount($row, 'exempt_sales')),
                $this->money($this->amount($row, 'zero_rated_sales')),
                $this->money($this->amount($row, 'taxable_sales')),
                $this->money($this->amount($row, 'output_vat')),
                $this->money($grossTaxableSales),
            ];
        })->values();

        return $this->report('SALES TRANSACTION', $columns, $rows, $company, $period);
    }

    private function importation(Collection $records, array $company, Carbon $period): array
    {
        $columns = [
            'Import Entry Number',
            'Assessment/Release Date',
            'Registered Name',
            'Importation Date',
            'Country of Origin',
            'Amount of Total Landed Cost',
            'Amount of Dutiable Value',
            'Amount of Charges Before Release From Custom',
            'Amount of Taxable Imports',
            'Amount of Exempt Imports',
            'Amount of VAT',
            'OR Number',
            'Date of VAT Payment',
        ];

        $rows = $records->map(fn (ImportationEntry $record) => [
            (string) $record->import_entry_no,
            $this->date($record->assessment_date),
            (string) $record->supplier,
            $this->date($record->importation_date),
            (string) $record->country,
            $this->money($record->total_landed_cost),
            $this->money($record->dutiable_value),
            $this->money($record->charges),
            $this->money($record->taxable_goods),
            $this->money($record->exempt),
            $this->money($record->vat_payable),
            (string) $record->or_number,
            $this->date($record->payment_date),
        ])->values();

        return $this->report('IMPORTS TRANSACTION', $columns, $rows, $company, $period);
    }

    private function report(string $title, array $columns, Collection $rows, array $company, Carbon $period): array
    {
        return [
            'title' => $title,
            'subtitle' => 'RECONCILIATION OF LISTING FOR ENFORCEMENT',
            'period' => $this->reportDate($period),
            'company' => [
                'tin' => $this->tin((string) ($company['tin'] ?? '')),
                'name' => (string) ($company['name'] ?? ''),
                'trade_name' => (string) ($company['registered_name'] ?? $company['name'] ?? ''),
                'address' => $this->address($company['address1'] ?? '', $company['address2'] ?? ''),
            ],
            'columns' => $columns,
            'rows' => $rows->all(),
            'totals' => $this->totals($rows),
        ];
    }

    private function totals(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $totals = array_fill(0, count($rows->first()), '');
        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                if ($this->isMoney($value)) {
                    $totals[$index] = $this->money($this->amount($totals[$index]) + $this->amount($value));
                }
            }
        }

        $firstMoneyIndex = collect($totals)->search(fn ($value) => $value !== '');
        if ($firstMoneyIndex !== false) {
            $totals[$firstMoneyIndex - 1] = 'Grand Total :';
        }

        return $totals;
    }

    private function partyNames(
        string $type,
        ?string $companyName,
        ?string $lastName,
        ?string $firstName,
        ?string $middleName
    ): array {
        if ($type === 'individual') {
            return ['', trim(implode(' ', array_filter([$lastName, $firstName, $middleName])))];
        }

        return [(string) $companyName, ''];
    }

    private function amount(mixed $row, ?string $key = null): float
    {
        $value = $key === null
            ? $row
            : (is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null));

        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }

        return round((float) preg_replace('/[^\d.-]/', '', (string) $value), 2);
    }

    private function money(mixed $value): string
    {
        return number_format($this->amount($value), 2, '.', '');
    }

    private function isMoney(mixed $value): bool
    {
        return is_string($value) && preg_match('/^-?\d+\.\d{2}$/', $value) === 1;
    }

    private function tin(string $value): string
    {
        $digits = substr(preg_replace('/\D/', '', $value), 0, 9);

        if (strlen($digits) !== 9) {
            return $value;
        }

        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 3);
    }

    private function address(?string $address1, ?string $address2): string
    {
        return trim(implode(' ', array_filter([trim((string) $address1), trim((string) $address2)])));
    }

    private function reportDate(Carbon $period): string
    {
        return $period->copy()->endOfMonth()->format('m/d/Y');
    }

    private function date(mixed $date): string
    {
        if ($date instanceof Carbon) {
            return $date->format('m/d/Y');
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('m/d/Y');
        }

        $value = trim((string) $date);

        return $value === '' ? '' : Carbon::parse($value)->format('m/d/Y');
    }
}
