<?php

namespace App\Services\BIR;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds the annual 1604E Schedule 3 DAT file from Expanded WTAX rows.
 *
 * This is intentionally separate from ReliefExpandedWtaxDatGenerator. Quarterly
 * 1601EQ/QAP has different leading record codes, detail positions and period
 * formatting, so annual changes must not drift into the validated quarterly file.
 */
class ReliefExpandedWtaxAnnualDatGenerator
{
    private const FORM_TYPE = '1604E';
    private const COMPANY_NAME_LIMIT = 50;

    public function generate(array $company, Collection $transactions, Carbon $periodEnd): string
    {
        $periodEnd = $periodEnd->copy()->endOfDay();

        $lines = [$this->header($company, $periodEnd)];

        $sequence = 0;
        foreach ($transactions as $transaction) {
            $lines[] = $this->detail($company, $transaction, $periodEnd, ++$sequence);
        }

        $lines[] = $this->trailer($company, $transactions, $periodEnd);

        return implode("\r\n", $lines) . "\r\n";
    }

    public function filename(array $company, Carbon $periodEnd): string
    {
        return $this->birTin($this->companyTin($company))
            . $this->branch($company)
            . $periodEnd->copy()->endOfDay()->format('mdY')
            . self::FORM_TYPE
            . '.dat';
    }

    private function header(array $company, Carbon $periodEnd): string
    {
        $fields = [
            'H' . self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $periodEnd->format('m/d/Y'),
        ];

        if (count($fields) !== 4) {
            throw new RuntimeException('1604E header must contain exactly 4 fields.');
        }

        return implode(',', $fields);
    }

    private function detail(array $company, array|object $transaction, Carbon $periodEnd, int $sequence): string
    {
        $row = $this->row($transaction);

        $fields = [
            'D3',
            self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $periodEnd->format('m/d/Y'),
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
            throw new RuntimeException('1604E detail must contain exactly 16 fields.');
        }

        return implode(',', $fields);
    }

    private function trailer(array $company, Collection $transactions, Carbon $periodEnd): string
    {
        $totalTax = 0.0;

        foreach ($transactions as $transaction) {
            $totalTax += $this->amount($this->row($transaction), 'tax_withheld');
        }

        $fields = [
            'C3',
            self::FORM_TYPE,
            $this->birTin($this->companyTin($company)),
            $this->branch($company),
            $periodEnd->format('m/d/Y'),
            $this->number(round($totalTax, 2)),
        ];

        if (count($fields) !== 6) {
            throw new RuntimeException('1604E control record must contain exactly 6 fields.');
        }

        return implode(',', $fields);
    }

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

    private function branch(array $company): string
    {
        return $this->branchCode((string) ($company['branch_code'] ?? ''));
    }

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

    private function optionalName(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : $this->quote($value);
    }

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
