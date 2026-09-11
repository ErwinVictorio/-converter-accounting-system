<?php

namespace App\Imports;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Turns one worksheet line of an Expanded WTAX workbook into the entry attributes
 * ExpandedWtaxEntry stores -- and nothing else. No database, no Excel reader, no
 * state beyond the upload's own withholding agent and period.
 *
 * It exists so that ExpandedWtaxImport and ExpandedWtaxBirInfoPreflight read a row
 * the same way. The preflight has to reject a bad row before the importer deletes
 * the month, and the only way it can know what the importer would have stored is to
 * run the importer's own mapping. Validating by importing and rolling back is the
 * alternative, and it is worse: the delete would already have happened inside the
 * transaction, and the row ids would burn.
 *
 * **Nothing here computes an amount.** For the BIR layout the three amounts are read
 * as the workbook supplies them; for the system export the income payment is derived
 * from the withheld tax and the rate column it was written in, exactly as the
 * importer has always done, because that layout carries no income column at all.
 * See ExpandedWtaxImport's class comment for why that distinction matters.
 *
 * Every mapped row carries the worksheet row it came from, and a system-export row
 * carries the rate column too, so an error message can name the cell to fix.
 */
class ExpandedWtaxRowMapper
{
    /** @var array<string, float> */
    public const SYSTEM_RATES = [
        '(1%)' => 1.00,
        '(2%)' => 2.00,
        '(5%)' => 5.00,
        '(10%)' => 10.00,
        '(15%)' => 15.00,
    ];

    /** The DAT's company-name field, and the reference file's longest entry. */
    private const COMPANY_NAME_LIMIT = 50;

    /** @var array<string, mixed> */
    private array $withholdingAgent;

    public function __construct(
        private string $reportingPeriod,
        ?array $withholdingAgent,
        private bool $useRowReportingPeriod,
        private string $reportType
    ) {
        $this->withholdingAgent = $this->normaliseWithholdingAgent($withholdingAgent);
    }

    /**
     * @param  array<int, mixed>  $data  the row's cells, re-indexed from zero
     * @param  array<string, int>  $columnIndexes  heading => cell index
     * @return array<int, array<string, mixed>> zero, one, or (system export) several
     */
    public function mapBirRow(array $data, array $columnIndexes, int $worksheetRow): array
    {
        $value = fn (string $column) => $this->cell($data, $columnIndexes, $column);

        $companyName = $this->birName($value('companyName'), self::COMPANY_NAME_LIMIT);
        $lastName = $this->birName($value('surName'));
        $firstName = $this->birName($value('firstName'));
        $middleName = $this->birName($value('middleName'));
        $tin = $this->digits($value('Vendor_TIN'));

        // A line that names nobody is a spacer or a stray note, not a payment.
        if ($companyName === '' && $lastName === '' && $firstName === '' && $tin === '') {
            return [];
        }

        // The template has no payee-type column: the type is whichever name side
        // the file filled in. A row that fills both, or neither, is mapped as it
        // stands and reported by the validator rather than guessed at here.
        $isCompany = $companyName !== '';

        return [[
            'reporting_period' => $this->reportingPeriodForRow($value('Reporting_Month')),
            'report_type' => $this->reportType,
            ...$this->withholdingAgent,
            'payee_name' => $isCompany
                ? $companyName
                : $this->individualName($lastName, $firstName, $middleName),
            'payee_type' => $isCompany ? 'company' : 'individual',
            // Nine digits, the shape both the template and the DAT use. Any branch
            // suffix the file carries is dropped; branchCode is its own column.
            'payee_tin' => substr($tin, 0, 9),
            'payee_branch_code' => $this->branchCode($value('branchCode')),
            'company_name' => $companyName ?: null,
            'last_name' => $lastName ?: null,
            'first_name' => $firstName ?: null,
            'middle_name' => $middleName ?: null,
            'atc_code' => $this->atcCode($value('ATC')),
            // The three uploaded amounts, exactly as the workbook computed them.
            'income_payment' => $this->parseNumber($value('income_payment')),
            'tax_rate' => $this->parseNumber($value('ewt_rate')),
            'tax_withheld' => $this->parseNumber($value('tax_amount')),
            'source_row' => $worksheetRow,
            'source_column' => null,
        ]];
    }

    /**
     * @param  array<int, mixed>  $data
     * @param  array<string, int>  $columnIndexes
     * @return array<int, array<string, mixed>> one entry per nonzero rate column
     */
    public function mapSystemRow(array $data, array $columnIndexes, int $worksheetRow): array
    {
        $rawName = (string) $this->cell($data, $columnIndexes, 'Supplier Name');
        [$payeeType, $companyName, $lastName, $firstName, $middleName, $payeeName] = $this->systemPayee($rawName);
        $tin = $this->digits($this->cell($data, $columnIndexes, 'TIN'));

        if ($payeeName === '' && $tin === '') {
            return [];
        }

        if (in_array($payeeName, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'], true)) {
            return [];
        }

        $entries = [];

        foreach (self::SYSTEM_RATES as $column => $rate) {
            $taxWithheld = $this->parseNumber($this->cell($data, $columnIndexes, $column));

            if (abs($taxWithheld) < 0.005) {
                continue;
            }

            $entries[] = [
                'reporting_period' => $this->reportingPeriodForRow($this->cell($data, $columnIndexes, 'Date')),
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
                'atc_code' => $this->defaultAtcCode($tin, $payeeType, $rate),
                // This layout has no income column, so the one figure it does carry
                // is divided back out by the rate column it was written in. That is
                // the importer's long-standing behaviour, moved rather than changed.
                'income_payment' => round($taxWithheld / ($rate / 100), 2),
                'tax_rate' => $rate,
                'tax_withheld' => $taxWithheld,
                'source_row' => $worksheetRow,
                'source_column' => $column,
            ];
        }

        return $entries;
    }

    /**
     * The attributes ExpandedWtaxEntry is filled with, without the two keys that
     * only exist to point an error message at a cell.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function attributes(array $entry): array
    {
        unset($entry['source_row'], $entry['source_column']);

        return $entry;
    }

    /**
     * @param  array<int, mixed>  $data
     * @param  array<string, int>  $columnIndexes
     */
    private function cell(array $data, array $columnIndexes, string $column): mixed
    {
        $index = $columnIndexes[$column] ?? null;

        return $index === null ? null : ($data[$index] ?? null);
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
     * Mapped null when the cell is blank, so the row stays visible and is reported
     * by the validator. It is no longer resolved from the rate: the file states the
     * ATC, and guessing one would put a payment on the wrong schedule.
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

    private function reportingPeriodForRow($dateCell): string
    {
        if (! $this->useRowReportingPeriod) {
            return $this->reportingPeriod;
        }

        $date = $this->dateValue($dateCell);

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
     * @return array<string, string>
     */
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
