<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\SalesVatInput;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class SalesVatInputImport implements OnEachRow, SkipsEmptyRows
{
    protected string $reportingPeriod;
    protected ?string $format = null;
    protected int $importedRows = 0;
    protected int $skippedDebitMemoRows = 0;

    public function __construct(?string $reportingPeriod = null)
    {
        $this->reportingPeriod = $reportingPeriod ?? now()->endOfMonth()->toDateString();
    }

    public function onRow(Row $row): void
    {
        $data = array_values($row->toArray());
        $firstCell = $this->birText((string) ($data[0] ?? ''));

        if ($firstCell === 'DOCUMENT NO') {
            $this->format = 'summary';

            return;
        }

        if ($firstCell === 'CLIENT TIN') {
            $this->format = 'bir';

            return;
        }

        if ($this->isHeadingOrGuideRow($data)) {
            return;
        }

        if ($this->format === 'summary' || str_starts_with($firstCell, 'SI#') || str_starts_with($firstCell, 'CM#')) {
            if ($this->looksLikeSalesSummaryRow($data)) {
                $this->importSalesSummaryRow($data, $row->getIndex());
            }

            return;
        }

        if (($this->format === 'bir' || $this->format === null) && $this->looksLikeBirSalesRow($data)) {
            $this->importBirSalesRow($data, $row->getIndex());
        }
    }

    public function importedRows(): int
    {
        return $this->importedRows;
    }

    public function skippedDebitMemoRows(): int
    {
        return $this->skippedDebitMemoRows;
    }

    private function importSalesSummaryRow(array $data, int $rowNumber): void
    {
        $documentNo = $this->birText((string) ($data[0] ?? ''));
        $customerName = $this->birText((string) ($data[6] ?? ''));

        if ($customerName === '') {
            return;
        }

        if (in_array($customerName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'], true)) {
            return;
        }

        if ($documentNo === '') {
            $documentNo = 'SALES-SUMMARY-' . $this->reportingPeriod . '-' . $rowNumber;
        }

        $documentType = $this->documentType($documentNo);

        if ($documentType === 'DM') {
            $this->skippedDebitMemoRows++;

            return;
        }

        $existingBirInfo = SalesVatInput::query()
            ->where('customer_name', $customerName)
            ->whereNotNull('customer_tin')
            ->latest('id')
            ->first();
        $customer = $this->findCustomer($customerName);

        SalesVatInput::updateOrCreate(
            [
                'document_no' => $documentNo,
                'customer_name' => $customerName,
                'reporting_period' => $this->reportingPeriod,
            ],
            [
                'document_type' => $documentType,
                'document_date' => $this->parseDate($data[1] ?? null),
                'terms' => $this->birText((string) ($data[2] ?? '')),
                'days' => $this->parseInteger($data[3] ?? null),
                'due_date' => $this->parseDate($data[4] ?? null),
                'agent_name' => $this->birText((string) ($data[5] ?? '')),
                'document_refs' => $this->birText((string) ($data[7] ?? '')),
                'gross_amount' => $this->parseNumber($data[8] ?? null),
                'discount' => $this->parseNumber($data[9] ?? null),
                'charges' => $this->parseNumber($data[10] ?? null),
                'net_amount' => $this->parseNumber($data[11] ?? null),
                'output_vat' => $this->parseNumber($data[12] ?? null),
                'taxable_net_of_vat' => $this->parseNumber($data[13] ?? null),
                'customer_tin' => $customer?->tin ?: $existingBirInfo?->customer_tin,
                'customer_type' => $customer ? 'company' : ($existingBirInfo?->customer_type ?: 'company'),
                'company_name' => $customer?->name ?: $existingBirInfo?->company_name,
                'last_name' => $existingBirInfo?->last_name,
                'first_name' => $existingBirInfo?->first_name,
                'middle_name' => $existingBirInfo?->middle_name,
                'address1' => $customer?->addr ?: $existingBirInfo?->address1,
                'address2' => $customer?->city ?: $existingBirInfo?->address2,
                'exempt_sales' => 0,
                'zero_rated_sales' => 0,
                'is_adjusted' => false,
            ]
        );

        $this->importedRows++;
    }

    private function importBirSalesRow(array $data, int $rowNumber): void
    {
        $companyName = $this->birText((string) ($data[1] ?? ''));
        $lastName = $this->birText((string) ($data[2] ?? ''));
        $firstName = $this->birText((string) ($data[3] ?? ''));
        $middleName = $this->birText((string) ($data[4] ?? ''));
        $customerName = $companyName ?: trim("{$lastName} {$firstName} {$middleName}");

        if ($customerName === '') {
            return;
        }

        $customer = $this->findCustomer($customerName);
        $customerType = $companyName !== '' ? 'company' : 'individual';
        $documentNo = 'BIR-R-SALES-' . $this->reportingPeriod . '-' . $rowNumber;

        SalesVatInput::updateOrCreate(
            [
                'document_no' => $documentNo,
                'customer_name' => $customerName,
                'reporting_period' => $this->reportingPeriod,
            ],
            [
                'document_type' => 'BIR',
                'document_date' => $this->reportingPeriod,
                'terms' => null,
                'days' => null,
                'due_date' => null,
                'agent_name' => null,
                'document_refs' => null,
                'gross_amount' => $this->parseNumber($data[13] ?? null),
                'discount' => 0,
                'charges' => 0,
                'net_amount' => $this->parseNumber($data[12] ?? null),
                'output_vat' => $this->parseNumber($data[11] ?? null),
                'taxable_net_of_vat' => $this->parseNumber($data[9] ?? null),
                'customer_tin' => $customer?->tin ?: $this->formatTin((string) ($data[0] ?? '')),
                'customer_type' => $customer ? 'company' : $customerType,
                'company_name' => $customer ? $customer->name : ($customerType === 'company' ? $companyName : null),
                'last_name' => $customer ? null : ($customerType === 'individual' ? $lastName : null),
                'first_name' => $customer ? null : ($customerType === 'individual' ? $firstName : null),
                'middle_name' => $customer ? null : ($customerType === 'individual' ? $middleName : null),
                'address1' => $customer?->addr ?: ($this->birText((string) ($data[5] ?? '')) ?: null),
                'address2' => $customer?->city ?: ($this->birText((string) ($data[6] ?? '')) ?: null),
                'exempt_sales' => $this->parseNumber($data[7] ?? null),
                'zero_rated_sales' => $this->parseNumber($data[8] ?? null),
                'is_adjusted' => false,
            ]
        );

        $this->importedRows++;
    }

    private function documentType(string $documentNo): string
    {
        $documentNo = $this->birText($documentNo);

        if (preg_match('/^SI(?:#|\b|-)?/', $documentNo)) {
            return 'SI';
        }

        if (preg_match('/^CM(?:#|\b|-)?/', $documentNo)) {
            return 'CM';
        }

        if (preg_match('/^DM(?:#|\b|-)?/', $documentNo)) {
            return 'DM';
        }

        return 'OTHER';
    }

    private function isHeadingOrGuideRow(array $data): bool
    {
        $firstCell = $this->birText((string) ($data[0] ?? ''));
        $joinedRow = $this->birText(implode(' ', array_map(fn ($value) => (string) $value, $data)));

        return $joinedRow === ''
            || in_array($firstCell, ['DOCUMENT NO', 'CLIENT TIN', 'FULL GUIDE', 'IMPORTANT NOTICE'], true)
            || str_contains($joinedRow, 'BIR EXCEL UPLOADER')
            || str_contains($joinedRow, 'NUMBER FORMAT')
            || str_contains($joinedRow, 'NOT IN COMMA FORMAT');
    }

    private function looksLikeSalesSummaryRow(array $data): bool
    {
        $customerName = $this->birText((string) ($data[6] ?? ''));
        $hasSalesAmount = $this->parseNumber($data[11] ?? null) !== 0.00
            || $this->parseNumber($data[12] ?? null) !== 0.00
            || $this->parseNumber($data[13] ?? null) !== 0.00;

        return $customerName !== '' && $hasSalesAmount;
    }

    private function looksLikeBirSalesRow(array $data): bool
    {
        $hasName = $this->birText((string) ($data[1] ?? '')) !== ''
            || $this->birText((string) ($data[2] ?? '')) !== '';
        $hasSalesAmount = $this->parseNumber($data[9] ?? null) !== 0.00
            || $this->parseNumber($data[11] ?? null) !== 0.00
            || $this->parseNumber($data[12] ?? null) !== 0.00;

        return $hasName && $hasSalesAmount;
    }

    private function parseNumber($value): float
    {
        if (is_null($value) || trim((string) $value) === '') {
            return 0.00;
        }

        $cleanValue = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($cleanValue) ? (float) $cleanValue : 0.00;
    }

    private function parseInteger($value): ?int
    {
        if (is_null($value) || trim((string) $value) === '') {
            return null;
        }

        return (int) $this->parseNumber($value);
    }

    private function parseDate($value): ?string
    {
        if (is_null($value) || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private function formatTin(?string $value): string
    {
        $digits = substr($this->digits($value), 0, 9);

        return strlen($digits) === 9 ? $digits : '';
    }

    private function findCustomer(string $customerName): ?Customer
    {
        $nameKey = Customer::normalizeName($customerName);

        if ($nameKey === '') {
            return null;
        }

        return Customer::query()
            ->where('name_key', $nameKey)
            ->latest('id')
            ->first();
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
