<?php

namespace App\Services\BIR;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds the BIR form 1604E schedule of expanded withholding tax.
 *
 * Shape (verified against Docs/Expanded/0087919760000123120251604E.dat):
 *
 *   H1604E,{agent tin},{agent branch},{m/d/Y}                        4 fields
 *   D3,1604E,{agent tin},{agent branch},{m/d/Y},{seq},...           16 fields
 *   C3,1604E,{agent tin},{agent branch},{m/d/Y},{total withheld}     6 fields
 *
 * Two things differ from the RELIEF generators in this namespace and are
 * deliberate:
 *
 * 1. Internal runs of spaces are left alone. The RELIEF generators collapse
 *    them, but the reference file contains "PRINTSCAPE  PRINTING SERVICES AND
 *    BUSINESS SUPPLIE" with a double space, so collapsing here would rewrite
 *    stored data on the way out and make a byte-for-byte comparison impossible.
 *    Collapsing belongs in ExpandedWtaxImport, where messy spreadsheet text
 *    actually enters the system.
 * 2. Amounts always carry two decimals, including negatives. The reference file
 *    has -51600.00 / -2580.00 on a reversal row, so signs are passed through.
 *
 * Rows are not aggregated per payee: the reference file lists the same payee
 * twice under the same ATC, so each stored row becomes one detail line.
 */
class ReliefExpandedWtaxDatGenerator
{
    private const FORM_TYPE = '1604E';

    /**
     * The longest company name in the reference file is exactly 50 characters.
     */
    private const COMPANY_NAME_LIMIT = 50;

    public function generate(array $company, Collection $transactions, Carbon $period): string
    {
        $period = $period->copy()->endOfMonth();

        $lines = [$this->header($company, $period)];

        $sequence = 0;
        foreach ($transactions as $transaction) {
            $lines[] = $this->detail($transaction, $company, $period, ++$sequence);
        }

        $lines[] = $this->trailer($company, $transactions, $period);

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * e.g. 0087919760000123120251604E.dat for TIN 008791976, branch 0000,
     * December 2025.
     */
    public function filename(array $company, Carbon $period): string
    {
        return $this->birTin($this->companyTin($company))
            . $this->branch($company)
            . $period->copy()->endOfMonth()->format('mdY')
            . self::FORM_TYPE
            . '.dat';
    }

    private function header(array $company, Carbon $period): string
    {
        $fields = [
            'H' . self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $period->format('m/d/Y'),
        ];

        if (count($fields) !== 4) {
            throw new RuntimeException('1604E Header must contain exactly 4 fields.');
        }

        return implode(',', $fields);
    }

    private function detail(array|object $transaction, array $company, Carbon $period, int $sequence): string
    {
        $row = $this->row($transaction);

        $fields = [
            'D3',
            self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $period->format('m/d/Y'),
            (string) $sequence,
            $this->birTin($this->text($row, 'payee_tin')),
            $this->payeeBranch($this->text($row, 'payee_branch_code')),
            $this->optionalName($this->birName($this->text($row, 'company_name'), self::COMPANY_NAME_LIMIT)),
            $this->optionalName($this->birName($this->text($row, 'last_name'))),
            $this->optionalName($this->birName($this->text($row, 'first_name'))),
            $this->optionalName($this->birName($this->text($row, 'middle_name'))),
            strtoupper(trim($this->text($row, 'atc_code'))),
            $this->number($this->amount($row, 'income_payment')),
            $this->number($this->amount($row, 'tax_rate')),
            $this->number($this->amount($row, 'tax_withheld')),
        ];

        if (count($fields) !== 16) {
            throw new RuntimeException('1604E Detail must contain exactly 16 fields.');
        }

        return implode(',', $fields);
    }

    private function trailer(array $company, Collection $transactions, Carbon $period): string
    {
        $total = 0.0;

        foreach ($transactions as $transaction) {
            $total += $this->amount($this->row($transaction), 'tax_withheld');
        }

        $fields = [
            'C3',
            self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $period->format('m/d/Y'),
            $this->number(round($total, 2)),
        ];

        if (count($fields) !== 6) {
            throw new RuntimeException('1604E Control record must contain exactly 6 fields.');
        }

        return implode(',', $fields);
    }

    /**
     * Accepts models, plain arrays and stdClass rows, so the controller can hand
     * over an Eloquent collection while tests build fixtures by hand.
     */
    private function row(array|object $transaction): array
    {
        if (is_object($transaction) && method_exists($transaction, 'toBirExpandedRow')) {
            return $transaction->toBirExpandedRow();
        }

        return (array) $transaction;
    }

    private function companyTin(array $company): string
    {
        return (string) ($company['tin'] ?? '');
    }

    /**
     * The withholding agent's own branch code. Absent from config/bir.php, where
     * only head-office details are held, so it falls back to the head office.
     */
    private function branch(array $company): string
    {
        return $this->branchCode((string) ($company['branch_code'] ?? ''));
    }

    /**
     * Every payee in the reference file carries 0000, including payees whose TIN
     * has a non-zero branch suffix, so this never derives the branch from the TIN.
     */
    private function payeeBranch(string $value): string
    {
        return $this->branchCode($value);
    }

    private function branchCode(string $value): string
    {
        $digits = $this->digits($value);

        return $digits === '' ? '0000' : substr(str_pad($digits, 4, '0', STR_PAD_LEFT), 0, 4);
    }

    private function quote(?string $value): string
    {
        return '"' . str_replace('"', '""', (string) $value) . '"';
    }

    /**
     * Name fields are quoted when filled and left bare when empty, matching the
     * ",,,," runs the reference file uses for a company's unused name columns.
     */
    private function optionalName(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : $this->quote($value);
    }

    /**
     * Shapes text for the DAT without collapsing internal spacing -- see the
     * class docblock.
     *
     * The ampersand expansion and comma removal are a last line of defence:
     * BirExpandedWtaxRowValidator rejects both characters, so a stored row
     * carrying one never reaches generation. Dropping the ampersand outright
     * would join two words, so it is expanded even though that can leave a
     * double space behind.
     */
    private function birName(?string $value, ?int $limit = null): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = str_replace(',', ' ', $value);
        $value = preg_replace('/[^A-Z0-9 ]/', ' ', $value);
        $value = rtrim(ltrim($value));

        if ($limit !== null) {
            $value = rtrim(substr($value, 0, $limit));
        }

        return $value;
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function text(array $row, string $key): string
    {
        return (string) ($row[$key] ?? '');
    }

    private function amount(array $row, string $key): float
    {
        $value = $row[$key] ?? null;

        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }

        return round((float) preg_replace('/[^\d.-]/', '', (string) $value), 2);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    private function birTin(string $value): string
    {
        return substr($this->digits($value), 0, 9);
    }
}
