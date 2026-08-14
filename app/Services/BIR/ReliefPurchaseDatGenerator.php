<?php

namespace App\Services\BIR;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class ReliefPurchaseDatGenerator
{
    public function generate(
        array $company,
        Collection $transactions,
        Carbon $period,
        float $nonCreditableInputVat = 0
    ): string {
        $totalInputVat = $transactions->sum(fn ($row) => $this->amount($row, 'input_vat'));

        if ($nonCreditableInputVat < 0 || $nonCreditableInputVat > $totalInputVat) {
            throw new RuntimeException('Non-creditable input VAT must be between 0 and total input VAT.');
        }

        $lines = [
            $this->generateHeader($company, $transactions, $period, $nonCreditableInputVat),
        ];

        foreach ($transactions as $transaction) {
            $lines[] = $this->generateDetail((array) $transaction, $company, $period);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    public function filename(array $company, Carbon $period): string
    {
        return $this->digits((string) ($company['tin'] ?? '')) . 'P' . $period->copy()->endOfMonth()->format('mY') . '.DAT';
    }

    private function generateHeader(
        array $company,
        Collection $transactions,
        Carbon $period,
        float $nonCreditableInputVat
    ): string {
        $totalInputVat = $transactions->sum(fn ($row) => $this->amount($row, 'input_vat'));
        $creditableInputVat = $totalInputVat - $nonCreditableInputVat;

        $fields = [
            'H',
            'P',
            $this->quote($this->digits((string) ($company['tin'] ?? ''))),
            $this->quote($company['name'] ?? ''),
            $this->quote($company['last_name'] ?? ''),
            $this->quote($company['first_name'] ?? ''),
            $this->quote($company['middle_name'] ?? ''),
            $this->quote($company['registered_name'] ?? $company['name'] ?? ''),
            $this->quote($company['address1'] ?? ''),
            $this->quote($company['address2'] ?? ''),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'exempt'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'zero_rated'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'services'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'capital_goods'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'other_than_capital_goods'))),
            $this->headerNumber($totalInputVat),
            $this->headerNumber($creditableInputVat),
            $this->headerNumber($nonCreditableInputVat),
            $company['rdo_code'] ?? '',
            $period->copy()->endOfMonth()->format('m/d/Y'),
            (string) ($company['final_header_field'] ?? '12'),
        ];

        if (count($fields) !== 21) {
            throw new RuntimeException('RELIEF Purchase Header must contain exactly 21 fields.');
        }

        return implode(',', $fields);
    }

    private function generateDetail(array $transaction, array $company, Carbon $period): string
    {
        $vendorType = $transaction['vendor_type'] ?? 'company';
        $companyName = $vendorType === 'individual' ? '' : ($transaction['company_name'] ?? $transaction['supplier_name'] ?? '');

        $fields = [
            'D',
            'P',
            $this->quote($this->digits((string) ($transaction['vendor_tin'] ?? $transaction['tin_number'] ?? ''))),
            $this->quote($this->birText($companyName)),
            $this->optionalName($this->birText($transaction['last_name'] ?? '')),
            $this->optionalName($this->birText($transaction['first_name'] ?? '')),
            $this->optionalName($this->birText($transaction['middle_name'] ?? '')),
            $this->quote($this->birText($transaction['address1'] ?? '')),
            $this->quote($this->birText($transaction['address2'] ?? '')),
            $this->detailNumber($this->amount($transaction, 'exempt')),
            $this->detailNumber($this->amount($transaction, 'zero_rated')),
            $this->detailNumber($this->amount($transaction, 'services')),
            $this->detailNumber($this->amount($transaction, 'capital_goods')),
            $this->detailNumber($this->amount($transaction, 'other_than_capital_goods')),
            $this->detailNumber($this->amount($transaction, 'input_vat')),
            $this->digits((string) ($company['tin'] ?? '')),
            $period->copy()->endOfMonth()->format('m/d/Y'),
        ];

        if (count($fields) !== 17) {
            throw new RuntimeException('RELIEF Purchase Detail must contain exactly 17 fields.');
        }

        return implode(',', $fields);
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
