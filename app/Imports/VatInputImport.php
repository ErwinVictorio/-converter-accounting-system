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

    // Tatanggapin nito ang date mula sa Controller (o magde-default sa kasalukuyang petsa)
    public function __construct(?string $uploadDate = null)
    {
        $this->uploadDate = $uploadDate ?? now()->toDateString();
    }

    public function onRow(Row $row)
    {
        $data = $row->toArray();

        $rawTin = (string) $this->value($data, ['vendor_tin', 'tin', 'tin_number']);
        $systemSupplierName = strtoupper(trim((string) $this->value($data, ['supplier_name', 'company_name', 'companyname'])));
        $supplier = $this->findSupplier($rawTin, $systemSupplierName);

        $tinNumber = $this->birTin($supplier?->tin ?: $rawTin);
        $companyName = strtoupper(trim((string) ($supplier?->payee ?: $supplier?->name ?: $systemSupplierName)));
        $lastName = strtoupper(trim((string) $this->value($data, ['last_name', 'lastname'])));
        $firstName = strtoupper(trim((string) $this->value($data, ['first_name', 'firstname'])));
        $middleName = strtoupper(trim((string) $this->value($data, ['middle_name', 'middlename'])));

        if ($systemSupplierName === '' && $companyName === '' && $lastName === '') {
            return;
        }

        $supplierName = $companyName ?: trim("{$lastName} {$firstName} {$middleName}");

        if (in_array($supplierName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'])) {
            return;
        }

        $exempt = $this->parseNumber($this->value($data, ['exempt']));
        $zeroRated = $this->parseNumber($this->value($data, ['zero_rated', 'zerorated']));
        $purchaseImported = $this->parseNumber($this->value($data, ['purchase_imported', 'purchaseimported']));
        $purchaseLocal = $this->parseNumber($this->value($data, ['purchase_local', 'purchaselocal']));
        $services = $this->parseNumber($this->value($data, ['services']));
        $others = $this->parseNumber($this->value($data, ['others']));
        $capitalGoods = $this->parseNumber($this->value($data, ['capital_goods', 'capitalgoods'])) ?: $purchaseImported;
        $otherThanCapitalGoods = $this->parseNumber($this->value($data, ['other_than_capital_goods', 'otherthancapitalgoods'])) ?: $purchaseLocal + $others;
        $taxableNetOfVat = $this->parseNumber($this->value($data, ['taxable_net_of_vat', 'taxablenetofvat']));
        $vatRate = $this->parseNumber($this->value($data, ['vat_rate', 'vatrate'])) ?: 12.00;
        $totalPurchases = $this->parseNumber($this->value($data, ['total_purchases', 'totalpurchases', 'total']));
        $taxableNetOfVat = $taxableNetOfVat ?: $capitalGoods + $otherThanCapitalGoods + $services;
        $inputVat = $this->parseNumber($this->value($data, ['input_vat', 'inputvat'])) ?: round($taxableNetOfVat * ($vatRate / 100), 2);
        $vendorType = $companyName !== '' ? 'company' : 'individual';
        $total = $exempt + $zeroRated + $services + $capitalGoods + $otherThanCapitalGoods;

        VatInput::create([
            'supplier_name' => $supplierName,
            'tin_number' => $tinNumber,
            'vendor_type' => $vendorType,
            'company_name' => $companyName ?: null,
            'last_name' => $lastName ?: null,
            'first_name' => $firstName ?: null,
            'middle_name' => $middleName ?: null,
            'address1' => trim((string) ($supplier?->addr ?: $this->value($data, ['address1', 'address_1']))),
            'address2' => trim((string) $this->value($data, ['address2', 'address_2'])),
            'is_imported' => $purchaseImported > 0,
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
            'total_purchases' => $totalPurchases ?: $total,
            'others' => $others,
            'total' => $total,
            'date_uploaded' => $this->uploadDate,
        ]);
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

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private function birTin(?string $value): string
    {
        return substr($this->digits($value), 0, 9);
    }

    private function findSupplier(?string $tin, string $supplierName): ?Supplier
    {
        $birTin = $this->birTin($tin);

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

        return Supplier::query()
            ->where('name', $supplierName)
            ->orWhere('payee', $supplierName)
            ->first();
    }
}
