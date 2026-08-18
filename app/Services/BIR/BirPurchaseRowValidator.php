<?php

namespace App\Services\BIR;

class BirPurchaseRowValidator
{
    public function validate(array $row, int $excelRow): array
    {
        $errors = [];
        $vendorType = $row['vendor_type'] ?? 'company';
        $tin = substr(preg_replace('/\D/', '', (string) ($row['vendor_tin'] ?? $row['tin_number'] ?? '')), 0, 9);

        if (! preg_match('/^\d{9}$/', $tin) || $tin === '000000000') {
            $errors[] = "Row {$excelRow}: Vendor TIN must contain a valid first 9 digits and cannot be 000000000.";
        }

        if (! in_array($vendorType, ['company', 'individual'], true)) {
            $errors[] = "Row {$excelRow}: Vendor type must be company or individual.";
        }

        if ($vendorType === 'company' && trim((string) ($row['company_name'] ?? $row['supplier_name'] ?? '')) === '') {
            $errors[] = "Row {$excelRow}: Company name is required for company vendors.";
        }

        if ($vendorType === 'individual') {
            foreach (['last_name', 'first_name', 'middle_name'] as $field) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    $errors[] = "Row {$excelRow}: {$field} is required for individual vendors.";
                }
            }
        }

        if (trim((string) ($row['address1'] ?? '')) === '') {
            $errors[] = "Row {$excelRow}: Supplier Address1 is required.";
        }

        foreach (['company_name', 'last_name', 'first_name', 'middle_name', 'address1', 'address2'] as $field) {
            $value = (string) ($row[$field] ?? '');

            if (str_contains($value, ',') || str_contains($value, '&')) {
                $errors[] = "Row {$excelRow}: {$field} cannot contain comma or ampersand.";
            }
        }

        foreach (['exempt', 'zero_rated', 'services', 'capital_goods', 'other_than_capital_goods', 'input_vat'] as $field) {
            $value = $row[$field] ?? 0;

            if ($value === '' || ! is_numeric(preg_replace('/[^\d.-]/', '', (string) $value))) {
                $errors[] = "Row {$excelRow}: {$field} must be numeric. Use 0 when there is no amount.";
            }
        }

        return $errors;
    }
}
