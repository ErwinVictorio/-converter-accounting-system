<?php

namespace App\Services;

use App\Imports\SalesVatInputImport;
use App\Imports\UploadBirInfoPreflight;
use App\Imports\UploadWorkbookTypePreflight;
use App\Models\SalesVatInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class SalesUploadService
{
    private const CUSTOMER_FIXABLE_FIELDS = [
        'customer_tin',
        'company_name',
        'address1',
        'address2',
        'bir_info',
    ];

    /** @param UploadedFile|string $file */
    public function preflight($file, string $reportingPeriod): array
    {
        $typeIssues = (new UploadWorkbookTypePreflight)->check($file, 'sales', $reportingPeriod);
        $issues = $typeIssues === []
            ? (new UploadBirInfoPreflight)->checkSales($file, $reportingPeriod)
            : [];

        return [
            'type_issues' => $typeIssues,
            'bir_issues' => $issues,
            'customer_issues' => array_values(array_filter($issues, fn (array $issue) => $this->isCustomerFixable($issue))),
            'workbook_issues' => array_values(array_filter($issues, fn (array $issue) => ! $this->isCustomerFixable($issue))),
        ];
    }

    /** @param UploadedFile|string $file */
    public function replace($file, string $reportingPeriod): array
    {
        $import = new SalesVatInputImport($reportingPeriod);

        DB::transaction(function () use ($reportingPeriod, $file, $import) {
            SalesVatInput::query()
                ->whereDate('reporting_period', $reportingPeriod)
                ->where('is_adjusted', false)
                ->delete();

            Excel::import($import, $file);

            if ($import->importedRows() === 0 && $import->skippedDebitMemoRows() > 0) {
                throw new \RuntimeException(
                    'Sales upload skipped '.$import->skippedDebitMemoRows().' DM row(s). No importable SI/CM Sales rows were found.'
                );
            }
        });

        return ['skipped_debit_memo_count' => $import->skippedDebitMemoRows()];
    }

    private function isCustomerFixable(array $issue): bool
    {
        return ($issue['issue_class'] ?? null) === 'customer'
            && in_array($issue['field'] ?? '', self::CUSTOMER_FIXABLE_FIELDS, true);
    }
}
