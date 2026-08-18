<?php

namespace App\Services\BIR;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class ReliefSalesDatGenerator
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
        return $this->digits((string) ($company['tin'] ?? '')) . 'S' . $period->copy()->endOfMonth()->format('mY') . '.DAT';
    }

    private function generateHeader(array $company, Collection $transactions, Carbon $period): string
    {
        $fields = [
            'H',
            'S',
            $this->quote($this->digits((string) ($company['tin'] ?? ''))),
            $this->quote($company['name'] ?? ''),
            $this->quote($company['last_name'] ?? ''),
            $this->quote($company['first_name'] ?? ''),
            $this->quote($company['middle_name'] ?? ''),
            $this->quote($company['registered_name'] ?? $company['name'] ?? ''),
            $this->quote($company['address1'] ?? ''),
            $this->quote($company['address2'] ?? ''),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'exempt_sales'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'zero_rated_sales'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'taxable_sales'))),
            $this->headerNumber($transactions->sum(fn ($row) => $this->amount($row, 'output_vat'))),
            $company['rdo_code'] ?? '',
            $period->copy()->endOfMonth()->format('m/d/Y'),
            (string) ($company['final_header_field'] ?? '12'),
        ];

        if (count($fields) !== 17) {
            throw new RuntimeException('RELIEF Sales Header must contain exactly 17 fields.');
        }

        return implode(',', $fields);
    }

    private function generateDetail(array $transaction, array $company, Carbon $period): string
    {
        $customerType = $transaction['customer_type'] ?? 'company';
        $companyName = $customerType === 'individual' ? '' : ($transaction['company_name'] ?? $transaction['customer_name'] ?? '');

        $fields = [
            'D',
            'S',
            $this->quote($this->birTin((string) ($transaction['customer_tin'] ?? ''))),
            $this->optionalName($this->birText($companyName)),
            $this->optionalName($this->birText($transaction['last_name'] ?? '')),
            $this->optionalName($this->birText($transaction['first_name'] ?? '')),
            $this->optionalName($this->birText($transaction['middle_name'] ?? '')),
            $this->quote($this->birText($transaction['address1'] ?? '')),
            $this->quote($this->birText($transaction['address2'] ?? '')),
            $this->detailNumber($this->amount($transaction, 'exempt_sales')),
            $this->detailNumber($this->amount($transaction, 'zero_rated_sales')),
            $this->detailNumber($this->amount($transaction, 'taxable_sales')),
            $this->detailNumber($this->amount($transaction, 'output_vat')),
            $this->digits((string) ($company['tin'] ?? '')),
            $period->copy()->endOfMonth()->format('m/d/Y'),
        ];

        if (count($fields) !== 15) {
            throw new RuntimeException('RELIEF Sales Detail must contain exactly 15 fields.');
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

    private function birTin(string $value): string
    {
        return substr($this->digits($value), 0, 9);
    }
}
