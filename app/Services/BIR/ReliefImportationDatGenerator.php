<?php

namespace App\Services\BIR;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds the RELIEF Importation (type "I") DAT file.
 *
 * Layout reverse-engineered from the BIR-accepted sample
 * ImportaionFormat/008791976I072026.DAT: 18 header fields, 16 detail fields.
 * The header identity block and trailer are byte-identical to the Purchase and
 * Sales files; only the totals block and the detail line differ.
 */
class ReliefImportationDatGenerator
{
    public function generate(array $company, Collection $transactions, Carbon $period): string
    {
        $lines = [
            $this->generateHeader($company, $transactions, $period),
        ];

        foreach ($transactions as $transaction) {
            $lines[] = $this->generateDetail((array) $transaction, $company, $period);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    public function filename(array $company, Carbon $period): string
    {
        return $this->digits((string) ($company['tin'] ?? '')) . 'I' . $period->copy()->endOfMonth()->format('mY') . '.DAT';
    }

    private function generateHeader(array $company, Collection $transactions, Carbon $period): string
    {
        $fields = [
            'H',
            'I',
            $this->quote($this->digits((string) ($company['tin'] ?? ''))),
            $this->quote($company['name'] ?? ''),
            $this->quote($company['last_name'] ?? ''),
            $this->quote($company['first_name'] ?? ''),
            $this->quote($company['middle_name'] ?? ''),
            $this->quote($company['registered_name'] ?? $company['name'] ?? ''),
            $this->quote($company['address1'] ?? ''),
            $this->quote($company['address2'] ?? ''),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'dutiable_value'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'charges'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'exempt'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'taxable_goods'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'vat_payable'))),
            $company['rdo_code'] ?? '',
            $period->copy()->endOfMonth()->format('m/d/Y'),
            $this->headerVatRate($transactions, $company),
        ];

        if (count($fields) !== 18) {
            throw new RuntimeException('RELIEF Importation Header must contain exactly 18 fields.');
        }

        return implode(',', $fields);
    }

    private function generateDetail(array $transaction, array $company, Carbon $period): string
    {
        $fields = [
            'D',
            'I',
            $this->quote($this->birText($transaction['import_entry_no'] ?? '')),
            $this->datDate($transaction['assessment_date'] ?? ''),
            $this->quote($this->birText($transaction['supplier'] ?? '')),
            $this->datDate($transaction['importation_date'] ?? ''),
            $this->quote($this->birText($transaction['country'] ?? '')),
            $this->detailNumber($this->amount($transaction, 'dutiable_value')),
            $this->detailNumber($this->amount($transaction, 'charges')),
            $this->detailNumber($this->amount($transaction, 'exempt')),
            $this->detailNumber($this->amount($transaction, 'taxable_goods')),
            $this->detailNumber($this->amount($transaction, 'vat_payable')),
            // OR numbers are written verbatim, never numerically: the reference file
            // carries both "000" and "0000", so leading zeros are significant.
            $this->quote($this->birText($transaction['or_number'] ?? '')),
            $this->datDate($transaction['payment_date'] ?? ''),
            $this->digits((string) ($company['tin'] ?? '')),
            $period->copy()->endOfMonth()->format('m/d/Y'),
        ];

        if (count($fields) !== 16) {
            throw new RuntimeException('RELIEF Importation Detail must contain exactly 16 fields.');
        }

        return implode(',', $fields);
    }

    /**
     * The detail lines carry no VAT rate; the header carries exactly one for the
     * whole month. Mixed rates have nowhere to go, so fail loudly rather than
     * silently reporting one rate for rows filed at another.
     */
    private function headerVatRate(Collection $transactions, array $company): string
    {
        $rates = $transactions
            ->map(fn ($row) => $this->amount($row, 'vat_rate'))
            ->filter(fn (float $rate) => $rate > 0)
            ->unique()
            ->values();

        if ($rates->count() > 1) {
            throw new RuntimeException(
                'RELIEF Importation DAT holds one VAT rate per month, but these rows mix: '
                . $rates->map(fn (float $rate) => $this->rate($rate))->implode(', ') . '.'
            );
        }

        if ($rates->isEmpty()) {
            return (string) ($company['final_header_field'] ?? '12');
        }

        return $this->rate((float) $rates->first());
    }

    private function datDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('m/d/Y');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return Carbon::parse($value)->format('m/d/Y');
    }

    private function rate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function quote(?string $value): string
    {
        return '"' . str_replace('"', '""', (string) $value) . '"';
    }

    private function birText(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = str_replace(',', ' ', $value);
        $value = preg_replace('/[^A-Z0-9 .#\/\-\(\)]/', ' ', $value);

        return preg_replace('/\s+/', ' ', trim($value));
    }

    private function headerNumber(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function detailNumber(float $value): string
    {
        if ((float) $value == 0.0) {
            return '0';
        }

        return number_format($value, 2, '.', '');
    }

    private function amount(array|object $row, string $key): float
    {
        if (is_object($row)) {
            $value = $row->{$key} ?? null;
        } else {
            $value = $row[$key] ?? null;
        }

        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }

        return round((float) preg_replace('/[^\d.-]/', '', (string) $value), 2);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
