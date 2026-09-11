<?php

namespace App\Imports;

use App\Models\ExpandedWtaxEntry;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Row;

/**
 * Reads a BIR-format Expanded Withholding Tax workbook into expanded_wtax_entries.
 *
 * The layout is the eleven columns of Docs/1601EQ_Schedule_1_template.xls, headings
 * on row 1:
 *
 *   Reporting_Month | Vendor_TIN | branchCode | companyName | surName | firstName
 *   | middleName | ATC | income_payment | ewt_rate | tax_amount
 *
 * One worksheet line becomes exactly one stored row.
 *
 * **Nothing here computes an amount.** income_payment, ewt_rate and tax_amount are
 * already computed in the workbook -- column K is normally the formula
 * ROUND(I*J/100, 2) -- and all three are read and stored as the file supplies
 * them. The income payment is never derived from the tax, the tax is never derived
 * from the income payment, and no rate is applied to either. Where the two sides
 * disagree, BirExpandedWtaxRowValidator reports the row and blocks the DAT; it
 * does not correct the figures. Formula cells are resolved to their computed value
 * because the value is what the accountant entered the formula to produce.
 *
 * Text and identifiers are normalised, which is a different matter from
 * recalculating an amount: names are folded to the BIR's punctuation-free
 * uppercase because the DAT is comma-delimited, the TIN is reduced to its digits,
 * and the branch code is padded to the four digits the DAT carries. Values, not
 * amounts.
 *
 * Two columns are stored that the BIR format does not carry, both composed purely
 * from the columns above: payee_type, which tells the validator whether to require
 * a company name or a surname, and payee_name, a display label and sort key. See
 * ExpandedWtaxEntry for the full mapping.
 *
 * Header and reporting-month checks that must fail the whole file live in
 * ExpandedWtaxUploadPreflight, and per-row BIR checks in
 * ExpandedWtaxBirInfoPreflight. Both run before the month is replaced.
 *
 * The row-to-attributes mapping itself lives in ExpandedWtaxRowMapper, which this
 * class and ExpandedWtaxBirInfoPreflight share: what the preflight validates has to
 * be what this importer would store, or the check is against a row nobody saves.
 * Everything below is the reading half -- heading detection, blank rows, and the
 * create() calls.
 */
class ExpandedWtaxImport implements OnEachRow, SkipsEmptyRows, WithCalculatedFormulas
{
    /**
     * The BIR heading as written in the template, mapped to the heading keys
     * WithHeadingRow can produce for it (Str::slug($header, '_')). The alternates
     * cost nothing and spare Accounting a failed upload over an underscore.
     *
     * ExpandedWtaxUploadPreflight reads this same list, so the columns the upload
     * requires and the columns the importer looks for cannot drift apart.
     *
     * @var array<string, array<int, string>>
     */
    public const COLUMNS = [
        'Reporting_Month' => ['reporting_month', 'reportingmonth'],
        'Vendor_TIN' => ['vendor_tin', 'vendortin'],
        'branchCode' => ['branchcode', 'branch_code'],
        'companyName' => ['companyname', 'company_name'],
        'surName' => ['surname', 'sur_name', 'last_name'],
        'firstName' => ['firstname', 'first_name'],
        'middleName' => ['middlename', 'middle_name'],
        'ATC' => ['atc', 'atc_code'],
        'income_payment' => ['income_payment', 'incomepayment'],
        'ewt_rate' => ['ewt_rate', 'ewtrate'],
        'tax_amount' => ['tax_amount', 'taxamount'],
    ];

    public const SYSTEM_COLUMNS = [
        'No' => ['no'],
        'Date' => ['date'],
        'Supplier Name' => ['suppliername', 'supplier'],
        'TIN' => ['tin'],
        'Reference' => ['reference'],
        '(1%)' => ['1', '1percent', 'onepercent'],
        '(2%)' => ['2', '2percent', 'twopercent'],
        '(5%)' => ['5', '5percent', 'fivepercent'],
        '(10%)' => ['10', '10percent', 'tenpercent'],
        '(15%)' => ['15', '15percent', 'fifteenpercent'],
        'Total' => ['total'],
    ];

    private ExpandedWtaxRowMapper $mapper;

    /** @var 'bir'|'system'|null */
    private ?string $layout = null;

    /** @var array<string, int> */
    private array $columnIndexes = [];

    public function __construct(
        ?string $reportingPeriod = null,
        ?array $withholdingAgent = null,
        bool $useRowReportingPeriod = false,
        string $reportType = 'quarterly'
    )
    {
        $this->mapper = new ExpandedWtaxRowMapper(
            Carbon::parse($reportingPeriod ?? now()->toDateString())->endOfMonth()->toDateString(),
            $withholdingAgent,
            $useRowReportingPeriod,
            $reportType === 'annual' ? 'annual' : 'quarterly'
        );
    }

    public function onRow(Row $row): void
    {
        // true = resolve formulas. Column K is a formula in the BIR template, and
        // its computed value is the tax amount the file is stating.
        $data = array_values($row->toArray(null, true));

        if ($this->layout === null) {
            $this->detectHeadingRow($data);

            return;
        }

        if ($this->isBlankRow($data)) {
            return;
        }

        $entries = $this->layout === 'system'
            ? $this->mapper->mapSystemRow($data, $this->columnIndexes, $row->getIndex())
            : $this->mapper->mapBirRow($data, $this->columnIndexes, $row->getIndex());

        foreach ($entries as $entry) {
            ExpandedWtaxEntry::create(ExpandedWtaxRowMapper::attributes($entry));
        }
    }

    private function detectHeadingRow(array $data): void
    {
        $headings = $this->headingMap($data);

        $birIndexes = $this->indexesFor($headings, self::COLUMNS);
        if (count($birIndexes) === count(self::COLUMNS)) {
            $this->layout = 'bir';
            $this->columnIndexes = $birIndexes;

            return;
        }

        $systemIndexes = $this->indexesFor($headings, self::SYSTEM_COLUMNS);
        if (count($systemIndexes) >= 10
            && isset($systemIndexes['Supplier Name'], $systemIndexes['TIN'])
            && $this->hasAnySystemRateColumn($systemIndexes)
        ) {
            $this->layout = 'system';
            $this->columnIndexes = $systemIndexes;
        }
    }

    /**
     * @param  array<int, mixed>  $data
     * @return array<string, int>
     */
    private function headingMap(array $data): array
    {
        $headings = [];

        foreach ($data as $index => $value) {
            $key = $this->normaliseHeading($value);

            if ($key !== '') {
                $headings[$key] = $index;
            }
        }

        return $headings;
    }

    /**
     * @param  array<string, int>  $headings
     * @param  array<string, array<int, string>>  $columns
     * @return array<string, int>
     */
    private function indexesFor(array $headings, array $columns): array
    {
        $indexes = [];

        foreach ($columns as $column => $acceptedKeys) {
            foreach ($acceptedKeys as $key) {
                $normalised = $this->normaliseHeading($key);

                if (array_key_exists($normalised, $headings)) {
                    $indexes[$column] = $headings[$normalised];

                    break;
                }
            }
        }

        return $indexes;
    }

    /**
     * @param  array<string, int>  $indexes
     */
    private function hasAnySystemRateColumn(array $indexes): bool
    {
        foreach (array_keys(ExpandedWtaxRowMapper::SYSTEM_RATES) as $column) {
            if (array_key_exists($column, $indexes)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseHeading($value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string) $value)));
    }

    private function isBlankRow(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
