<?php

namespace App\Imports;

use App\Models\ExpandedWtaxEntry;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

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
 * ExpandedWtaxUploadPreflight, which runs before the month is replaced.
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

    private const SYSTEM_RATES = [
        '(1%)' => 1.00,
        '(2%)' => 2.00,
        '(5%)' => 5.00,
        '(10%)' => 10.00,
        '(15%)' => 15.00,
    ];

    /** The DAT's company-name field, and the reference file's longest entry. */
    private const COMPANY_NAME_LIMIT = 50;

    protected string $reportingPeriod;

    private array $withholdingAgent;

    private bool $useRowReportingPeriod;

    private string $reportType;

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
        $this->reportingPeriod = Carbon::parse($reportingPeriod ?? now()->toDateString())
            ->endOfMonth()
            ->toDateString();
        $this->withholdingAgent = $this->normaliseWithholdingAgent($withholdingAgent);
        $this->useRowReportingPeriod = $useRowReportingPeriod;
        $this->reportType = $reportType === 'annual' ? 'annual' : 'quarterly';
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

        if ($this->layout === 'system') {
            $this->importSystemRow($data);

            return;
        }

        $this->importBirRow($data);
    }

    private function importBirRow(array $data): void
    {
        $companyName = $this->birName($this->value($data, 'companyName'), self::COMPANY_NAME_LIMIT);
        $lastName = $this->birName($this->value($data, 'surName'));
        $firstName = $this->birName($this->value($data, 'firstName'));
        $middleName = $this->birName($this->value($data, 'middleName'));
        $tin = $this->digits($this->value($data, 'Vendor_TIN'));

        // A line that names nobody is a spacer or a stray note, not a payment.
        if ($companyName === '' && $lastName === '' && $firstName === '' && $tin === '') {
            return;
        }

        // The template has no payee-type column: the type is whichever name side
        // the file filled in. A row that fills both, or neither, is stored as it
        // stands and reported by the validator rather than guessed at here.
        $isCompany = $companyName !== '';

        ExpandedWtaxEntry::create([
            'reporting_period' => $this->reportingPeriodForRow($data, 'Reporting_Month'),
            'report_type' => $this->reportType,
            ...$this->withholdingAgent,
            'payee_name' => $isCompany
                ? $companyName
                : $this->individualName($lastName, $firstName, $middleName),
            'payee_type' => $isCompany ? 'company' : 'individual',
            // Nine digits, the shape both the template and the DAT use. Any branch
            // suffix the file carries is dropped; branchCode is its own column.
            'payee_tin' => substr($tin, 0, 9),
            'payee_branch_code' => $this->branchCode($this->value($data, 'branchCode')),
            'company_name' => $companyName ?: null,
            'last_name' => $lastName ?: null,
            'first_name' => $firstName ?: null,
            'middle_name' => $middleName ?: null,
            'atc_code' => $this->atcCode($this->value($data, 'ATC')),
            // The three uploaded amounts, exactly as the workbook computed them.
            'income_payment' => $this->parseNumber($this->value($data, 'income_payment')),
            'tax_rate' => $this->parseNumber($this->value($data, 'ewt_rate')),
            'tax_withheld' => $this->parseNumber($this->value($data, 'tax_amount')),
        ]);
    }

    private function importSystemRow(array $data): void
    {
        $rawName = (string) $this->systemValue($data, 'Supplier Name');
        [$payeeType, $companyName, $lastName, $firstName, $middleName, $payeeName] = $this->systemPayee($rawName);
        $tin = $this->digits($this->systemValue($data, 'TIN'));

        if ($payeeName === '' && $tin === '') {
            return;
        }

        if (in_array($payeeName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'], true)) {
            return;
        }

        foreach (self::SYSTEM_RATES as $column => $rate) {
            $taxWithheld = $this->parseNumber($this->systemValue($data, $column));

            if (abs($taxWithheld) < 0.005) {
                continue;
            }

            $atcCode = $this->defaultAtcCode($tin, $payeeType, $rate);

            ExpandedWtaxEntry::create([
                'reporting_period' => $this->reportingPeriodForRow($data, 'Date'),
                'report_type' => $this->reportType,
                ...$this->withholdingAgent,
                'payee_name' => $payeeName,
                'payee_type' => $payeeType,
                'payee_tin' => substr($tin, 0, 9),
                'payee_branch_code' => '0000',
                'company_name' => $companyName ?: null,
                'last_name' => $lastName ?: null,
                'first_name' => $firstName ?: null,
                'middle_name' => $middleName ?: null,
                'atc_code' => $atcCode,
                'income_payment' => round($taxWithheld / ($rate / 100), 2),
                'tax_rate' => $rate,
                'tax_withheld' => $taxWithheld,
            ]);
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
     * "SURNAME, FIRST MIDDLE" -- a label for the screen and a sort key that files
     * an individual under their surname, the way the reference DAT orders them.
     * It is never written to the DAT, so the comma is safe here; the four name
     * columns the DAT does carry are stored separately and stay comma-free.
     */
    private function individualName(string $last, string $first, string $middle): string
    {
        $given = trim($first . ' ' . $middle);

        if ($last === '') {
            return $given;
        }

        return $given === '' ? $last : $last . ', ' . $given;
    }

    /**
     * The template writes a plain number (its sample shows 1); the DAT carries four
     * digits. Padding here keeps the screen and the generated file in agreement.
     * Blank means head office, which the reference file files as 0000.
     */
    private function branchCode($value): string
    {
        $digits = substr($this->digits($value), 0, 4);

        return $digits === '' ? '0000' : str_pad($digits, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Stored null when the cell is blank, so the row is visible in the listing and
     * reported by the validator. It is no longer resolved from the rate: the file
     * states the ATC, and guessing one would put a payment on the wrong schedule.
     */
    private function atcCode($value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return $code === '' ? null : $code;
    }

    /**
     * Names are stripped down to letters, digits and single spaces: the reference
     * DAT contains no punctuation at all, so periods and apostrophes become
     * spaces rather than being kept.
     */
    private function birName($value, ?int $limit = null): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = preg_replace('/[^A-Z0-9 ]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $limit === null ? $value : rtrim(substr($value, 0, $limit));
    }

    /**
     * Reads the cell as the number it is. Thousands separators are tolerated even
     * though the template's ReadMe forbids them, because a stray comma-formatted
     * cell is a formatting slip rather than a different amount. Two decimals is the
     * column's own scale, not a recalculation -- no rate is ever applied here.
     */
    private function parseNumber($value): float
    {
        if (is_null($value) || trim((string) $value) === '') {
            return 0.00;
        }

        $cleanValue = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($cleanValue) ? round((float) $cleanValue, 2) : 0.00;
    }

    private function reportingPeriodForRow(array $data, string $dateColumn): string
    {
        if (! $this->useRowReportingPeriod) {
            return $this->reportingPeriod;
        }

        $date = $this->dateValue(
            $dateColumn === 'Date'
                ? $this->systemValue($data, $dateColumn)
                : $this->value($data, $dateColumn)
        );

        return ($date ?: Carbon::parse($this->reportingPeriod))->endOfMonth()->toDateString();
    }

    private function dateValue($value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_null($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))
                : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Looks a BIR column up by its template heading, trying each heading key
     * WithHeadingRow might have produced for it.
     */
    private function value(array $data, string $column): mixed
    {
        $index = $this->columnIndexes[$column] ?? null;

        return $index === null ? null : ($data[$index] ?? null);
    }

    private function systemValue(array $data, string $column): mixed
    {
        $index = $this->columnIndexes[$column] ?? null;

        return $index === null ? null : ($data[$index] ?? null);
    }

    private function digits($value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private function defaultAtcCode(string $tin, string $payeeType, float $rate): ?string
    {
        $rateKey = number_format($rate, 2, '.', '');
        $tinKey = substr($this->digits($tin), 0, 9);
        $overrides = (array) config('bir.expanded_wtax.payee_atc_overrides', []);

        if (isset($overrides[$tinKey][$rateKey])) {
            return strtoupper(trim((string) $overrides[$tinKey][$rateKey]));
        }

        $defaultRateCodes = (array) config('bir.expanded_wtax.default_rate_codes', []);
        $mapping = $defaultRateCodes[$rateKey][$payeeType] ?? null;

        return $mapping ? strtoupper(trim((string) $mapping)) : null;
    }

    /**
     * The system export has one payee-name column. Names shaped as "SURNAME,
     * FIRST MIDDLE" are individuals unless they also carry a company suffix.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    private function systemPayee(string $rawName): array
    {
        $rawName = trim($rawName);

        if ($rawName === '') {
            return ['company', '', '', '', '', ''];
        }

        if (str_contains($rawName, ',') && ! $this->looksLikeCompany($rawName)) {
            [$last, $given] = array_pad(explode(',', $rawName, 2), 2, '');
            $parts = preg_split('/\s+/', trim($given)) ?: [];
            $first = array_shift($parts) ?? '';
            $middle = implode(' ', $parts);
            $lastName = $this->birName($last);
            $firstName = $this->birName($first);
            $middleName = $this->birName($middle);

            return [
                'individual',
                '',
                $lastName,
                $firstName,
                $middleName,
                $this->individualName($lastName, $firstName, $middleName),
            ];
        }

        $companyName = $this->birName($rawName, self::COMPANY_NAME_LIMIT);

        return ['company', $companyName, '', '', '', $companyName];
    }

    private function looksLikeCompany(string $name): bool
    {
        return preg_match('/\b(INC|INCORPORATED|CORP|CORPORATION|COMPANY|CO|OPC|SERVICES|AGENCY|SUPPLY|SALES|HARDWARE|TRADING)\b/i', $name) === 1;
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
        foreach (array_keys(self::SYSTEM_RATES) as $column) {
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

    private function normaliseWithholdingAgent(?array $agent): array
    {
        $default = config('bir.companies.008791976', []);
        $agent = $agent ?: $default;
        $tin = substr($this->digits($agent['tin'] ?? $default['tin'] ?? '008791976'), 0, 9);
        $branch = substr($this->digits($agent['branch_code'] ?? '0000'), 0, 4);

        return [
            'withholding_agent_tin' => $tin === '' ? '008791976' : $tin,
            'withholding_agent_branch_code' => $branch === '' ? '0000' : str_pad($branch, 4, '0', STR_PAD_LEFT),
            'withholding_agent_name' => (string) ($agent['name'] ?? $agent['registered_name'] ?? $default['name'] ?? 'FORTRESS STEEL INC.'),
        ];
    }
}
