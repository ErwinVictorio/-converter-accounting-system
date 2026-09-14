<?php

namespace App\Services;

use App\Imports\UploadBirInfoPreflight;
use App\Imports\UploadWorkbookTypePreflight;
use App\Imports\VatInputImport;
use App\Models\ImportationEntry;
use App\Models\VatInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseUploadService
{
    /**
     * @param  UploadedFile|string  $file
     * @return array{type_issues: string[], bir_issues: array<int, array<string, mixed>>}
     */
    public function preflight($file, string $reportingPeriod): array
    {
        $typeIssues = (new UploadWorkbookTypePreflight)->check($file, 'purchase', $reportingPeriod);

        return [
            'type_issues' => $typeIssues,
            'bir_issues' => $typeIssues === []
                ? (new UploadBirInfoPreflight)->checkPurchase($file, $reportingPeriod)
                : [],
        ];
    }

    /**
     * Replace the normal Purchase rows only after callers have completed a fresh preflight.
     *
     * @param  UploadedFile|string  $file
     * @return array{skipped_supplier_count: int}
     */
    public function replace($file, string $reportingPeriod): array
    {
        $import = new VatInputImport($reportingPeriod);

        DB::transaction(function () use ($reportingPeriod, $file, $import) {
            VatInput::query()
                ->whereDate('date_uploaded', $reportingPeriod)
                ->where('is_adjusted', false)
                ->whereNotIn('id', ImportationEntry::query()
                    ->whereNotNull('vat_input_id')
                    ->select('vat_input_id'))
                ->delete();

            Excel::import($import, $file);

            if ($import->importedRows() === 0 && $import->skippedExcludedSupplierRows() > 0) {
                throw new \RuntimeException(
                    'Purchase upload skipped '.$import->skippedExcludedSupplierRows().' BUREAU OF CUSTOMS row(s). No importable Purchase rows were found.'
                );
            }
        });

        return ['skipped_supplier_count' => $import->skippedExcludedSupplierRows()];
    }
}
