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
    public function replace($file, string $reportingPeriod, array $company): array
    {
        $company = app(\App\Services\BIR\PurchaseCompanyDirectory::class)->resolve($company['tin'] ?? null, $company['branch_code'] ?? null);
        $import = new VatInputImport($reportingPeriod, $company);

        DB::transaction(function () use ($reportingPeriod, $file, $import, $company) {
            $scope = ['filing_company_tin' => $company['tin'], 'filing_company_branch_code' => $company['branch_code'], 'reporting_month' => substr($reportingPeriod, 0, 7)];
            $reset = app(PurchaseDmReferenceResetService::class);
            $reset->lock($scope);
            $replaced = VatInput::query()
                ->forFilingCompany($company)
                ->whereDate('date_uploaded', $reportingPeriod)
                ->where('is_adjusted', false)
                ->whereNotIn('id', ImportationEntry::query()
                    ->whereNotNull('vat_input_id')
                    ->select('vat_input_id'));

            $reset->reset($scope, (clone $replaced)->pluck('id')->all());
            $replaced->delete();
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
