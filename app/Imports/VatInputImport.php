<?php

namespace App\Imports;

use App\Models\Supplier;
use App\Models\VatInput;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Row;

class VatInputImport implements OnEachRow, WithHeadingRow, SkipsEmptyRows
{
    public function headingRow(): int
    {
        return 3;
    }

    protected string $uploadDate;
    protected int $importedRows = 0;
    protected int $skippedExcludedSupplierRows = 0;

    // Tatanggapin nito ang date mula sa Controller (o magde-default sa kasalukuyang petsa)
    public function __construct(?string $uploadDate = null)
    {
        $this->uploadDate = $uploadDate ?? now()->toDateString();
    }

    public function onRow(Row $row)
    {
        $data = $row->toArray();

        $rawTin = (string) $this->value($data, ['vendor_tin', 'tin', 'tin_number']);
        $systemSupplierName = $this->birText((string) $this->value($data, ['supplier_name', 'company_name', 'companyname']));

        if ($this->isSkippedPurchaseSupplier($systemSupplierName)) {
            $this->skippedExcludedSupplierRows++;

            return;
        }

        $supplier = $this->findSupplier($rawTin, $systemSupplierName);
        [$address1, $address2] = $this->supplierAddress($supplier, $data);

        $tinNumber = $this->formatTin($supplier?->tin ?: $rawTin);
        $companyName = $this->birText((string) ($supplier?->name ?: $systemSupplierName));
        $lastName = $this->birText((string) $this->value($data, ['last_name', 'lastname']));
        $firstName = $this->birText((string) $this->value($data, ['first_name', 'firstname']));
        $middleName = $this->birText((string) $this->value($data, ['middle_name', 'middlename']));

        if ($systemSupplierName === '' && $companyName === '' && $lastName === '') {
            return;
        }

        $supplierName = $companyName ?: trim("{$lastName} {$firstName} {$middleName}");

        if (in_array($supplierName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'])) {
            return;
        }

        $exempt = $this->parseNumber($this->value($data, ['exempt']));
        $zeroRated = $this->parseNumber($this->value($data, ['zero_rated', 'zerorated']));
        $vatRate = $this->parseNumber($this->value($data, ['vat_rate', 'vatrate'])) ?: 12.00;
        $purchaseImportedValue = $this->parseNumber($this->value($data, ['purchase_imported', 'purchaseimported']));
        $purchaseLocalValue = $this->parseNumber($this->value($data, ['purchase_local', 'purchaselocal']));
        $servicesValue = $this->parseNumber($this->value($data, ['services']));
        $othersValue = $this->parseNumber($this->value($data, ['others']));
        $totalPurchases = $this->parseNumber($this->value($data, ['total_purchases', 'totalpurchases', 'total']));
        $inputVatValue = $this->parseNumber($this->value($data, ['input_vat', 'inputvat']));
        $usesVatBucketAmounts = !$this->hasFilled($data, [
            'input_vat',
            'inputvat',
            'capital_goods',
            'capitalgoods',
            'other_than_capital_goods',
            'otherthancapitalgoods',
            'taxable_net_of_vat',
            'taxablenetofvat',
        ]);

        if ($usesVatBucketAmounts) {
            $purchaseImported = $this->taxableFromVat($purchaseImportedValue, $vatRate);
            $purchaseLocal = $this->taxableFromVat($purchaseLocalValue, $vatRate);
            $services = $this->taxableFromVat($servicesValue, $vatRate);
            $others = $this->taxableFromVat($othersValue, $vatRate);
            $capitalGoods = $purchaseImported;
            $otherThanCapitalGoods = round($purchaseLocal + $others, 2);
            $taxableNetOfVat = round($capitalGoods + $otherThanCapitalGoods + $services, 2);
            $inputVat = $totalPurchases ?: round($purchaseImportedValue + $purchaseLocalValue + $servicesValue + $othersValue, 2);
            $totalPurchases = round($taxableNetOfVat + $inputVat + $exempt + $zeroRated, 2);
        } else {
            $purchaseImported = $purchaseImportedValue;
            $purchaseLocal = $purchaseLocalValue;
            $services = $servicesValue;
            $others = $othersValue;
            $capitalGoods = $this->parseNumber($this->value($data, ['capital_goods', 'capitalgoods'])) ?: $purchaseImported;
            $otherThanCapitalGoods = $this->parseNumber($this->value($data, ['other_than_capital_goods', 'otherthancapitalgoods'])) ?: $purchaseLocal + $others;
            $taxableNetOfVat = $this->parseNumber($this->value($data, ['taxable_net_of_vat', 'taxablenetofvat']));
            $taxableNetOfVat = $taxableNetOfVat ?: $capitalGoods + $otherThanCapitalGoods + $services;
            $inputVat = $inputVatValue ?: round($taxableNetOfVat * ($vatRate / 100), 2);
        }

        $vendorType = $companyName !== '' ? 'company' : 'individual';
        $total = $exempt + $zeroRated + $services + $capitalGoods + $otherThanCapitalGoods;
        $totalPurchases = $totalPurchases ?: $total;
        $isImported = $purchaseImported > 0;

        $existingRecord = VatInput::where('supplier_name', $supplierName)
            ->where('is_imported', $isImported)
            ->where('is_adjusted', false)
            ->whereDate('date_uploaded', $this->uploadDate)
            ->first();

        if ($existingRecord) {
            $newPurchaseImported = round((float) $existingRecord->purchase_imported + $purchaseImported, 2);
            $newPurchaseLocal = round((float) $existingRecord->purchase_local + $purchaseLocal, 2);
            $newServices = round((float) $existingRecord->services + $services, 2);
            $newOthers = round((float) $existingRecord->others + $others, 2);
            $newExempt = round((float) $existingRecord->exempt + $exempt, 2);
            $newZeroRated = round((float) $existingRecord->zero_rated + $zeroRated, 2);
            $newCapitalGoods = round((float) $existingRecord->capital_goods + $capitalGoods, 2);
            $newOtherThanCapitalGoods = round((float) $existingRecord->other_than_capital_goods + $otherThanCapitalGoods, 2);
            $newTaxableNetOfVat = round($newCapitalGoods + $newOtherThanCapitalGoods + $newServices, 2);
            $newInputVat = round((float) $existingRecord->input_vat + $inputVat, 2);
            $newTotal = round((float) $existingRecord->total_purchases + $totalPurchases, 2);

            $existingRecord->update([
                'tin_number' => $tinNumber ?: $existingRecord->tin_number,
                'company_name' => $existingRecord->company_name ?: ($companyName ?: null),
                'last_name' => $existingRecord->last_name ?: ($lastName ?: null),
                'first_name' => $existingRecord->first_name ?: ($firstName ?: null),
                'middle_name' => $existingRecord->middle_name ?: ($middleName ?: null),
                'address1' => $supplier ? $address1 : ($existingRecord->address1 ?: $address1),
                'address2' => $supplier ? $address2 : ($existingRecord->address2 ?: $address2),
                'exempt' => $newExempt,
                'zero_rated' => $newZeroRated,
                'purchase_imported' => $newPurchaseImported,
                'purchase_local' => $newPurchaseLocal,
                'services' => $newServices,
                'capital_goods' => $newCapitalGoods,
                'other_than_capital_goods' => $newOtherThanCapitalGoods,
                'taxable_net_of_vat' => $newTaxableNetOfVat,
                'vat_rate' => $vatRate,
                'input_vat' => $newInputVat,
                'total_purchases' => $newTotal,
                'others' => $newOthers,
                'total' => $newTotal,
            ]);

            $this->importedRows++;

            return;
        }

        VatInput::create([
            'supplier_name' => $supplierName,
            'tin_number' => $tinNumber,
            'vendor_type' => $vendorType,
            'company_name' => $companyName ?: null,
            'last_name' => $lastName ?: null,
            'first_name' => $firstName ?: null,
            'middle_name' => $middleName ?: null,
            'address1' => $address1,
            'address2' => $address2,
            'is_imported' => $isImported,
            'exempt' => $exempt,
            'zero_rated' => $zeroRated,
            'purchase_imported' => $purchaseImported,
            'purchase_local' => $purchaseLocal,
            'services' => $services,
            'capital_goods' => $capitalGoods,
            'other_than_capital_goods' => $otherThanCapitalGoods,
            'taxable_net_of_vat' => $taxableNetOfVat,
            'vat_rate' => $vatRate,
            'input_vat' => $inputVat,
            'total_purchases' => $totalPurchases,
            'others' => $others,
            'total' => $total,
            'date_uploaded' => $this->uploadDate,
            'is_adjusted' => false,
        ]);

        $this->importedRows++;
    }

    public function importedRows(): int
    {
        return $this->importedRows;
    }

    public function skippedExcludedSupplierRows(): int
    {
        return $this->skippedExcludedSupplierRows;
    }

    private function parseNumber($value): float
    {
        if (is_null($value) || trim((string) $value) === '') {
            return 0.00;
        }

        $cleanValue = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($cleanValue) ? (float) $cleanValue : 0.00;
    }

    private function value(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return null;
    }

    private function hasFilled(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    private function taxableFromVat(float $value, float $vatRate): float
    {
        if ($value === 0.00 || $vatRate <= 0) {
            return 0.00;
        }

        return round($value / ($vatRate / 100), 2);
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private function birTin(?string $value): string
    {
        return substr($this->digits($value), 0, 9);
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

    private function supplierTin(?string $value): string
    {
        return substr($this->digits($value), 0, 12);
    }

    private function findSupplier(?string $tin, string $supplierName): ?Supplier
    {
        $birTin = $this->birTin($tin);
        $supplierTin = $this->supplierTin($tin);

        if (strlen($supplierTin) === 12) {
            $supplier = Supplier::query()
                ->whereRaw("REPLACE(REPLACE(REPLACE(tin, '-', ''), ' ', ''), '.', '') = ?", [$supplierTin])
                ->first();

            if ($supplier) {
                return $supplier;
            }
        }

        if ($birTin !== '') {
            $supplier = Supplier::query()
                ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin, '-', ''), ' ', ''), '.', ''), 9) = ?", [$birTin])
                ->first();

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

    private function supplierAddress(?Supplier $supplier, array $data): array
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
