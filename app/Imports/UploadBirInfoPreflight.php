<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\SalesVatInput;
use App\Models\Supplier;
use App\Services\BIR\BirPurchaseRowValidator;
use App\Services\BIR\BirSalesRowValidator;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Facades\Excel;

class UploadBirInfoPreflight implements ToArray, WithCalculatedFormulas
{
    /** @var array<int, array<int, mixed>> */
    private array $rows = [];

    public function array(array $rows): void
    {
        $this->rows = array_map(
            fn (array $row) => array_values(array_map(
                fn ($value) => is_string($value) ? trim($value) : $value,
                $row
            )),
            $rows
        );
    }

    /**
     * @param  \Illuminate\Http\UploadedFile|string  $file
     * @return array<int, array<string, mixed>>
     */
    public function checkPurchase($file, string $reportingPeriod): array
    {
        $this->load($file);
        $headingRow = $this->purchaseHeadingRow();

        if ($headingRow === null) {
            return [];
        }

        $headings = $this->headingMap($this->rows[$headingRow]);
        $issues = [];

        foreach (array_slice($this->rows, $headingRow + 1, null, true) as $index => $row) {
            $data = $this->associateRow($headings, $row);
            $rawTin = (string) $this->value($data, ['vendor_tin', 'tin', 'tin_number']);
            $systemSupplierName = $this->birText((string) $this->value($data, ['supplier_name', 'company_name', 'companyname']));

            if ($this->isSkippedPurchaseSupplier($systemSupplierName)) {
                continue;
            }

            $supplier = $this->findSupplier($rawTin, $systemSupplierName);
            [$address1, $address2] = $this->purchaseAddress($supplier, $data);
            $tinNumber = $this->formatTin($supplier?->tin ?: $rawTin);
            $companyName = $this->birText((string) ($supplier?->name ?: $systemSupplierName));
            $lastName = $this->birText((string) $this->value($data, ['last_name', 'lastname']));
            $firstName = $this->birText((string) $this->value($data, ['first_name', 'firstname']));
            $middleName = $this->birText((string) $this->value($data, ['middle_name', 'middlename']));

            if ($systemSupplierName === '' && $companyName === '' && $lastName === '') {
                continue;
            }

            $supplierName = $companyName ?: trim("{$lastName} {$firstName} {$middleName}");

            if (in_array($supplierName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'], true)) {
                continue;
            }

            $rowIssues = (new BirPurchaseRowValidator)->validate([
                'vendor_type' => $companyName !== '' ? 'company' : 'individual',
                'vendor_tin' => $tinNumber,
                'company_name' => $companyName ?: null,
                'last_name' => $lastName ?: null,
                'first_name' => $firstName ?: null,
                'middle_name' => $middleName ?: null,
                'address1' => $address1,
                'address2' => $address2,
                'exempt' => $this->parseNumber($this->value($data, ['exempt'])),
                'zero_rated' => $this->parseNumber($this->value($data, ['zero_rated', 'zerorated'])),
                'services' => $this->parseNumber($this->value($data, ['services'])),
                'capital_goods' => $this->parseNumber($this->value($data, ['capital_goods', 'capitalgoods'])),
                'other_than_capital_goods' => $this->parseNumber($this->value($data, ['other_than_capital_goods', 'otherthancapitalgoods'])),
                'input_vat' => $this->parseNumber($this->value($data, ['input_vat', 'inputvat'])),
            ], $index + 1);

            foreach ($rowIssues as $error) {
                $issues[] = $this->issue(
                    $index + 1,
                    $supplierName,
                    'purchase',
                    $this->purchaseField($error),
                    $this->stripRowPrefix($error),
                    'Master Data > Suppliers',
                    '/suppliers',
                    ['TIN', 'Address', 'City'],
                    $supplier ? 'matched supplier master record' : 'supplier name / vendor TIN'
                );
            }
        }

        return $issues;
    }

    /**
     * @param  \Illuminate\Http\UploadedFile|string  $file
     * @return array<int, array<string, mixed>>
     */
    public function checkSales($file, string $reportingPeriod): array
    {
        $this->load($file);
        $issues = [];
        $format = null;

        foreach ($this->rows as $index => $row) {
            $data = array_values($row);
            $firstCell = $this->birText((string) ($data[0] ?? ''));

            if ($firstCell === 'DOCUMENT NO') {
                $format = 'summary';

                continue;
            }

            if ($firstCell === 'CLIENT TIN') {
                $format = 'bir';

                continue;
            }

            if ($this->isSalesHeadingOrGuideRow($data)) {
                continue;
            }

            if ($format === 'summary' || str_starts_with($firstCell, 'SI#') || str_starts_with($firstCell, 'CM#')) {
                if ($this->looksLikeSalesSummaryRow($data)) {
                    $issues = [
                        ...$issues,
                        ...$this->salesSummaryIssues($data, $index + 1, $reportingPeriod),
                    ];
                }

                continue;
            }

            if (($format === 'bir' || $format === null) && $this->looksLikeBirSalesRow($data)) {
                $issues = [
                    ...$issues,
                    ...$this->birSalesIssues($data, $index + 1),
                ];
            }
        }

        return $issues;
    }

    private function load($file): void
    {
        $this->rows = [];

        Excel::import($this, $file);
    }

    private function purchaseHeadingRow(): ?int
    {
        foreach ($this->rows as $index => $row) {
            $headings = $this->headingMap($row);

            if ((isset($headings['vendortin']) || isset($headings['tin']) || isset($headings['tinnumber']))
                && (isset($headings['suppliername']) || isset($headings['companyname']))
            ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function headingMap(array $row): array
    {
        $headings = [];

        foreach ($row as $index => $value) {
            $key = $this->normaliseHeading($value);

            if ($key !== '') {
                $headings[$key] = $index;
            }
        }

        return $headings;
    }

    /**
     * @param  array<string, int>  $headings
     * @return array<string, mixed>
     */
    private function associateRow(array $headings, array $row): array
    {
        $data = [];

        foreach ($headings as $key => $index) {
            $data[$key] = $row[$index] ?? null;
            $data[$this->snakeHeading($key)] = $row[$index] ?? null;
        }

        return $data;
    }

    private function findSupplier(?string $tin, string $supplierName): ?Supplier
    {
        $birTin = $this->birTin($tin);
        $supplierTin = $this->supplierTin($tin);

        if ($supplierTin !== '') {
            $supplier = Supplier::query()
                ->get()
                ->first(fn (Supplier $supplier) => $this->supplierTin($supplier->tin) === $supplierTin);

            if ($supplier) {
                return $supplier;
            }
        }

        if ($birTin !== '') {
            $supplier = Supplier::query()
                ->get()
                ->first(fn (Supplier $supplier) => $this->birTin($supplier->tin) === $birTin);

            if ($supplier) {
                return $supplier;
            }
        }

        if ($supplierName === '') {
            return null;
        }

        $supplierNameKey = Supplier::normalizeName($supplierName);

        return Supplier::query()
            ->get()
            ->first(fn (Supplier $supplier) => Supplier::normalizeName($supplier->name) === $supplierNameKey);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function purchaseAddress(?Supplier $supplier, array $data): array
    {
        if ($supplier) {
            return [
                $this->birText((string) $supplier->addr),
                $this->birText((string) $supplier->city),
            ];
        }

        [$address1, $address2] = $this->splitAddress((string) $this->value($data, ['address1', 'address_1']));

        return [
            $address1,
            $address2 ?: $this->birText((string) $this->value($data, ['address2', 'address_2'])),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function salesSummaryIssues(array $data, int $rowNumber, string $reportingPeriod): array
    {
        $documentNo = $this->birText((string) ($data[0] ?? ''));
        $customerName = $this->birText((string) ($data[6] ?? ''));

        if ($customerName === '' || in_array($customerName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'], true)) {
            return [];
        }

        if ($this->documentType($documentNo) === 'DM') {
            return [];
        }

        $existingBirInfo = SalesVatInput::query()
            ->where('customer_name', $customerName)
            ->whereNotNull('customer_tin')
            ->where(function ($query) use ($reportingPeriod) {
                $query->whereDate('reporting_period', '!=', $reportingPeriod)
                    ->orWhere('is_adjusted', true);
            })
            ->latest('id')
            ->first();
        $customer = $this->findCustomer($customerName);
        $rowIssues = (new BirSalesRowValidator)->validate([
            'customer_type' => $customer ? 'company' : ($existingBirInfo?->customer_type ?: 'company'),
            'customer_tin' => $customer?->tin ?: $existingBirInfo?->customer_tin,
            'company_name' => $customer?->name ?: $existingBirInfo?->company_name ?: $customerName,
            'last_name' => $existingBirInfo?->last_name,
            'first_name' => $existingBirInfo?->first_name,
            'middle_name' => $existingBirInfo?->middle_name,
            'address1' => $customer?->addr ?: $existingBirInfo?->address1,
            'address2' => $customer?->city ?: $existingBirInfo?->address2,
            'exempt_sales' => 0,
            'zero_rated_sales' => 0,
            'taxable_sales' => $this->parseNumber($data[13] ?? null),
            'output_vat' => $this->parseNumber($data[12] ?? null),
        ], $rowNumber);

        return $this->salesIssuesFromErrors($rowIssues, $rowNumber, $customerName, $customer);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function birSalesIssues(array $data, int $rowNumber): array
    {
        $companyName = $this->birText((string) ($data[1] ?? ''));
        $lastName = $this->birText((string) ($data[2] ?? ''));
        $firstName = $this->birText((string) ($data[3] ?? ''));
        $middleName = $this->birText((string) ($data[4] ?? ''));
        $customerName = $companyName ?: trim("{$lastName} {$firstName} {$middleName}");

        if ($customerName === '') {
            return [];
        }

        $customer = $this->findCustomer($customerName);
        $customerType = $companyName !== '' ? 'company' : 'individual';
        $rowIssues = (new BirSalesRowValidator)->validate([
            'customer_type' => $customer ? 'company' : $customerType,
            'customer_tin' => $customer?->tin ?: $this->formatTin((string) ($data[0] ?? '')),
            'company_name' => $customer ? $customer->name : ($customerType === 'company' ? $companyName : null),
            'last_name' => $customer ? null : ($customerType === 'individual' ? $lastName : null),
            'first_name' => $customer ? null : ($customerType === 'individual' ? $firstName : null),
            'middle_name' => $customer ? null : ($customerType === 'individual' ? $middleName : null),
            'address1' => $customer?->addr ?: ($this->birText((string) ($data[5] ?? '')) ?: null),
            'address2' => $customer?->city ?: ($this->birText((string) ($data[6] ?? '')) ?: null),
            'exempt_sales' => $this->parseNumber($data[7] ?? null),
            'zero_rated_sales' => $this->parseNumber($data[8] ?? null),
            'taxable_sales' => $this->parseNumber($data[9] ?? null),
            'output_vat' => $this->parseNumber($data[11] ?? null),
        ], $rowNumber);

        return $this->salesIssuesFromErrors($rowIssues, $rowNumber, $customerName, $customer);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function salesIssuesFromErrors(array $errors, int $rowNumber, string $customerName, ?Customer $customer): array
    {
        $issues = [];

        foreach ($errors as $error) {
            $issues[] = $this->issue(
                $rowNumber,
                $customerName,
                'sales',
                $this->salesField($error),
                $this->stripRowPrefix($error),
                'Master Data > Customers',
                '/customers',
                ['TIN', 'Address', 'City'],
                $customer ? 'matched customer master record' : 'customer name'
            );
        }

        return $issues;
    }

    private function isSkippedPurchaseSupplier(string $supplierName): bool
    {
        $supplierNameKey = Supplier::normalizeName($supplierName);

        if ($supplierNameKey === '') {
            return false;
        }

        foreach (config('bir.purchase.skipped_suppliers', []) as $skippedSupplier) {
            if ($supplierNameKey === Supplier::normalizeName($skippedSupplier)) {
                return true;
            }
        }

        return false;
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

    private function isSalesHeadingOrGuideRow(array $data): bool
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

    /**
     * @return array<string, mixed>
     */
    private function issue(
        int $row,
        string $name,
        string $recordType,
        string $field,
        string $problem,
        string $fixLocation,
        string $fixRoute,
        array $neededFields,
        string $matchBasis
    ): array {
        return [
            'row' => $row,
            'name' => $name,
            'record_type' => $recordType,
            'field' => $field,
            'problem' => $problem,
            'fix_location' => $fixLocation,
            'fix_route' => $fixRoute,
            'needed_fields' => $neededFields,
            'match_basis' => $matchBasis,
        ];
    }

    private function purchaseField(string $error): string
    {
        return match (true) {
            str_contains($error, 'Vendor TIN') => 'vendor_tin',
            str_contains($error, 'Address1') => 'address1',
            str_contains($error, 'Company name') => 'company_name',
            default => 'bir_info',
        };
    }

    private function salesField(string $error): string
    {
        return match (true) {
            str_contains($error, 'Customer TIN') => 'customer_tin',
            str_contains($error, 'Address1') => 'address1',
            str_contains($error, 'Company name') => 'company_name',
            default => 'bir_info',
        };
    }

    private function stripRowPrefix(string $error): string
    {
        return preg_replace('/^Row \d+:\s*/', '', $error) ?? $error;
    }

    private function value(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $normalised = $this->normaliseHeading($key);

            if (array_key_exists($key, $data)) {
                return $data[$key];
            }

            if (array_key_exists($normalised, $data)) {
                return $data[$normalised];
            }
        }

        return null;
    }

    private function parseNumber($value): float
    {
        if (is_null($value) || trim((string) $value) === '') {
            return 0.00;
        }

        $cleanValue = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($cleanValue) ? (float) $cleanValue : 0.00;
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private function birTin(?string $value): string
    {
        return substr($this->digits($value), 0, 9);
    }

    private function supplierTin(?string $value): string
    {
        return substr($this->digits($value), 0, 12);
    }

    private function formatTin(?string $value): string
    {
        $digits = $this->supplierTin($value);

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

    /**
     * @return array{0: string, 1: string}
     */
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

    private function normaliseHeading(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace('%', 'percent', $value);
        $value = str_replace(['#'], ['no'], $value);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    private function snakeHeading(string $value): string
    {
        return match ($value) {
            'vendortin' => 'vendor_tin',
            'tinnumber' => 'tin_number',
            'suppliername' => 'supplier_name',
            'companyname' => 'company_name',
            'lastname' => 'last_name',
            'firstname' => 'first_name',
            'middlename' => 'middle_name',
            'zerorated' => 'zero_rated',
            'purchaseimported' => 'purchase_imported',
            'purchaselocal' => 'purchase_local',
            'capitalgoods' => 'capital_goods',
            'otherthancapitalgoods' => 'other_than_capital_goods',
            'taxablenetofvat' => 'taxable_net_of_vat',
            'inputvat' => 'input_vat',
            'totalpurchases' => 'total_purchases',
            default => $value,
        };
    }
}
