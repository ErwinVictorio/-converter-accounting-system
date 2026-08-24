<?php

namespace App\Imports;

use App\Models\ExpandedWtaxEntry;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Throwable;

/**
 * Reads the Expanded Withholding Tax workbook into expanded_wtax_entries.
 *
 * The spreadsheet holds one line per payment voucher with the tax withheld split
 * across rate columns (1%, 2%, 5%, 10%, 15%), while the 1604E DAT wants one
 * detail row per payee per ATC. So one worksheet line can produce several rows
 * here -- one for each rate column that carries an amount.
 *
 * The workbook also gives no income payment and no ATC code, both of which the
 * DAT needs, so this importer derives them:
 *
 *   income_payment = tax_withheld / (rate / 100)
 *   atc_code       = config('bir.expanded_wtax') keyed by rate and payee type
 *
 * All BIR text normalisation happens here rather than in the generator, because
 * this is where messy spreadsheet text enters the system. A row whose ATC cannot
 * be resolved is still stored, with a null code, so the upload does not fail
 * wholesale -- BirExpandedWtaxRowValidator then reports it and blocks the DAT.
 */
class ExpandedWtaxImport implements OnEachRow, WithHeadingRow, SkipsEmptyRows
{
    /**
     * Column headings slug down to the bare rate number, e.g. "(1%)" -> "1".
     * Ascending so a voucher split across two rates lands in a stable order.
     *
     * @var array<string, array{rate: float, keys: array<int, string>}>
     */
    private const RATE_COLUMNS = [
        '1' => ['rate' => 1.00, 'keys' => ['1', '1_percent', '1percent']],
        '2' => ['rate' => 2.00, 'keys' => ['2', '2_percent', '2percent']],
        '5' => ['rate' => 5.00, 'keys' => ['5', '5_percent', '5percent']],
        '10' => ['rate' => 10.00, 'keys' => ['10', '10_percent', '10percent']],
        '15' => ['rate' => 15.00, 'keys' => ['15', '15_percent', '15percent']],
    ];

    private const TOTAL_LABELS = ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL', 'TOTALS'];

    /**
     * Tokens that cannot occur in a personal name, used to tell a company whose
     * registered name contains a comma from an individual filed "SURNAME, GIVEN".
     */
    private const CORPORATE_TOKENS = [
        'INC', 'INCORPORATED', 'CORP', 'CORPORATION', 'CO', 'COMPANY', 'LTD',
        'LIMITED', 'LLC', 'OPC', 'ENTERPRISE', 'ENTERPRISES', 'TRADING',
        'HOLDINGS', 'BANK', 'INSURANCE', 'CONSTRUCTION', 'INDUSTRIES',
    ];

    private const COMPANY_NAME_LIMIT = 50;

    protected string $reportingPeriod;

    public function __construct(?string $reportingPeriod = null)
    {
        $this->reportingPeriod = Carbon::parse($reportingPeriod ?? now()->toDateString())
            ->endOfMonth()
            ->toDateString();
    }

    public function headingRow(): int
    {
        return 3;
    }

    public function onRow(Row $row)
    {
        $data = $row->toArray();

        $rawName = (string) $this->value($data, ['supplier_name', 'payee_name', 'suppliername', 'payee']);
        $rawTin = (string) $this->value($data, ['tin', 'payee_tin', 'tin_number']);

        $payeeName = $this->birName($rawName);

        if ($payeeName === '' && $this->digits($rawTin) === '') {
            return;
        }

        if (in_array($payeeName, self::TOTAL_LABELS, true)) {
            return;
        }

        $parts = $this->splitName($rawName);
        $tin = $this->formatTin($rawTin);

        $shared = [
            'reporting_period' => $this->reportingPeriod,
            'transaction_date' => $this->parseDate($this->value($data, ['date', 'transaction_date'])),
            'source_no' => $this->birReference($this->value($data, ['no', 'source_no', 'voucher', 'voucher_no'])),
            'reference_no' => $this->birReference($this->value($data, ['reference', 'reference_no', 'invoice', 'si_no'])),
            'payee_name' => $payeeName,
            'payee_type' => $parts['type'],
            'payee_tin' => $tin,
            // Constant, not derived from the TIN: every payee in the reference DAT
            // carries 0000 even when its TIN has a non-zero branch suffix.
            'payee_branch_code' => '0000',
            'company_name' => $parts['company_name'],
            'last_name' => $parts['last_name'],
            'first_name' => $parts['first_name'],
            'middle_name' => $parts['middle_name'],
            'source_row' => $row->getIndex(),
        ];

        foreach (self::RATE_COLUMNS as $column) {
            $withheld = $this->parseNumber($this->value($data, $column['keys']));

            if ($withheld === 0.00) {
                continue;
            }

            $rate = $column['rate'];

            ExpandedWtaxEntry::create($shared + [
                'atc_code' => $this->resolveAtc($tin, $parts['type'], $rate),
                'tax_rate' => $rate,
                'income_payment' => round($withheld * 100 / $rate, 2),
                'tax_withheld' => $withheld,
            ]);
        }
    }

    /**
     * Rate alone cannot decide the code -- 5% is WC100 or WI010 and 10% is WC139
     * or WI516 -- so the payee type decides between the WC and WI families, and a
     * per-TIN override wins over both. Returns null when nothing maps, leaving
     * the row for the validator to report.
     */
    private function resolveAtc(string $tin, string $payeeType, float $rate): ?string
    {
        $rateKey = number_format($rate, 2, '.', '');
        $tinKey = $this->birTin($tin);

        $overrides = (array) config('bir.expanded_wtax.payee_atc_overrides', []);

        if ($tinKey !== '' && isset($overrides[$tinKey][$rateKey])) {
            return strtoupper((string) $overrides[$tinKey][$rateKey]);
        }

        $mapping = ((array) config('bir.expanded_wtax.default_rate_codes', []))[$rateKey] ?? null;

        // A flat "rate => code" mapping is accepted too, for a site that files a
        // single code per rate regardless of payee type.
        if (is_string($mapping)) {
            return $mapping === '' ? null : strtoupper($mapping);
        }

        if (is_array($mapping) && isset($mapping[$payeeType]) && $mapping[$payeeType] !== '') {
            return strtoupper((string) $mapping[$payeeType]);
        }

        return null;
    }

    /**
     * Splits a supplier cell into the DAT's name columns.
     *
     * A name counts as an individual only when it has one comma and nothing after
     * that comma looks corporate. Checking only the tail matters: "CO" is both a
     * company suffix and a common surname, so "CO, JUAN" must stay an individual
     * while "WORLD BEST SALES, INC." must not.
     *
     * For the given-name half, the last token is taken as the middle name when
     * there is more than one -- "SY, JULIET HUI" is Juliet Hui Sy. A single token
     * leaves the middle name empty, which the reference DAT does for three of its
     * twelve individual payees.
     *
     * @return array{type: string, company_name: ?string, last_name: ?string, first_name: ?string, middle_name: ?string}
     */
    private function splitName(string $rawName): array
    {
        $company = [
            'type' => 'company',
            'company_name' => $this->birName($rawName, self::COMPANY_NAME_LIMIT) ?: null,
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
        ];

        $pieces = explode(',', trim($rawName));

        if (count($pieces) !== 2) {
            return $company;
        }

        $last = $this->birName($pieces[0]);
        $given = $this->birName($pieces[1]);

        if ($last === '' || $given === '') {
            return $company;
        }

        foreach (explode(' ', $given) as $token) {
            if (in_array($token, self::CORPORATE_TOKENS, true)) {
                return $company;
            }
        }

        $tokens = explode(' ', $given);
        $middle = count($tokens) > 1 ? array_pop($tokens) : '';

        return [
            'type' => 'individual',
            'company_name' => null,
            'last_name' => $last,
            'first_name' => implode(' ', $tokens),
            'middle_name' => $middle ?: null,
        ];
    }

    /**
     * Excel dates arrive as DateTime objects, serial numbers or plain strings
     * depending on how the cell was formatted.
     */
    private function parseDate($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_null($value) || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                )->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Names are stripped down to letters, digits and single spaces: the reference
     * DAT contains no punctuation at all, so periods and apostrophes become
     * spaces rather than being kept.
     */
    private function birName(?string $value, ?int $limit = null): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = preg_replace('/[^A-Z0-9 ]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $limit === null ? $value : rtrim(substr($value, 0, $limit));
    }

    /**
     * Voucher and invoice numbers are not written to the DAT, so they keep more of
     * their punctuation than names do; only the comma has to go, since these are
     * shown next to CSV-bound fields on screen.
     */
    private function birReference($value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace(',', ' ', $value);

        return preg_replace('/\s+/', ' ', trim($value));
    }

    private function parseNumber($value): float
    {
        if (is_null($value) || trim((string) $value) === '') {
            return 0.00;
        }

        $cleanValue = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($cleanValue) ? round((float) $cleanValue, 2) : 0.00;
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

    private function formatTin(?string $value): string
    {
        $digits = substr($this->digits($value), 0, 12);

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
}
