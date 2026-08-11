<?php

namespace App\Imports;

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

        // 1. Skip empty supplier
        if (empty($data['supplier_name'])) {
            return;
        }

        $supplierName = strtoupper(trim((string) $data['supplier_name']));

        // 2. Skip footer / totals
        if (in_array($supplierName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'])) {
            return;
        }

        // Parse numerical columns
        $purchaseImported = $this->parseNumber($data['purchaseimported'] ?? null);
        $purchaseLocal    = $this->parseNumber($data['purchaselocal'] ?? null);
        $services         = $this->parseNumber($data['services'] ?? null);
        $others           = $this->parseNumber($data['others'] ?? null);
        $tinNumber        = isset($data['tin']) ? trim((string) $data['tin']) : null;
        $isImported       = $purchaseImported > 0 ? 1 : 0;

        // 3. Hanapin kung may umiiral nang record para sa supplier name + is_imported
        $existingRecord = VatInput::where('supplier_name', $supplierName)
            ->where('is_imported', $isImported)
            ->first();

        if ($existingRecord) {
            // Sum all columns
            $newPurchaseImported = $existingRecord->purchase_imported + $purchaseImported;
            $newPurchaseLocal    = $existingRecord->purchase_local + $purchaseLocal;
            $newServices         = $existingRecord->services + $services;
            $newOthers           = $existingRecord->others + $others;
            $newTotal            = $newPurchaseImported + $newPurchaseLocal + $newServices + $newOthers;

            $existingRecord->update([
                'tin_number'        => $existingRecord->tin_number ?: $tinNumber, // gamitin ang TIN kung walang laman dati
                'purchase_imported' => $newPurchaseImported,
                'purchase_local'    => $newPurchaseLocal,
                'services'          => $newServices,
                'others'            => $newOthers,
                'total'             => $newTotal,
                'date_uploaded'     => $this->uploadDate,
            ]);
        } else {
            // Gumawa ng bagong record kung wala pa
            $total = $purchaseImported + $purchaseLocal + $services + $others;

            VatInput::create([
                'supplier_name'     => $supplierName,
                'tin_number'        => $tinNumber,
                'is_imported'       => $isImported,
                'purchase_imported' => $purchaseImported,
                'purchase_local'    => $purchaseLocal,
                'services'          => $services,
                'others'            => $others,
                'total'             => $total,
                'date_uploaded'     => $this->uploadDate,
            ]);
        }
    }

    private function parseNumber($value): float
    {
        if (is_null($value) || trim((string) $value) === '') {
            return 0.00;
        }

        $cleanValue = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($cleanValue) ? (float) $cleanValue : 0.00;
    }
}
