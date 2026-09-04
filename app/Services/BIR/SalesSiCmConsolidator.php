<?php

namespace App\Services\BIR;

use App\Models\SalesVatInput;
use Illuminate\Support\Collection;

class SalesSiCmConsolidator
{
    /**
     * @param  Collection<int, SalesVatInput>  $records
     * @return Collection<int, array<string, mixed>>
     */
    public function consolidate(Collection $records): Collection
    {
        return $records
            ->groupBy(fn (SalesVatInput $record) => $this->identityKey($record))
            ->map(fn (Collection $group) => $this->consolidateGroup($group))
            ->values();
    }

    private function identityKey(SalesVatInput $record): string
    {
        $tin = substr(preg_replace('/\D/', '', (string) $record->customer_tin), 0, 9);
        $name = preg_replace('/\s+/', '', strtoupper((string) ($record->company_name ?: $record->customer_name)));

        return implode('|', [
            $tin,
            $record->customer_type ?: 'company',
            $name,
            strtoupper((string) $record->last_name),
            strtoupper((string) $record->first_name),
            strtoupper((string) $record->middle_name),
            strtoupper((string) $record->address1),
            strtoupper((string) $record->address2),
        ]);
    }

    /**
     * @param  Collection<int, SalesVatInput>  $group
     * @return array<string, mixed>
     */
    private function consolidateGroup(Collection $group): array
    {
        $first = $group->first();
        $siRows = $group->filter(fn (SalesVatInput $record) => $this->documentType($record) === 'SI');
        $cmRows = $group->filter(fn (SalesVatInput $record) => $this->documentType($record) === 'CM');
        $otherRows = $group->reject(fn (SalesVatInput $record) => in_array($this->documentType($record), ['SI', 'CM'], true));

        $netAmount = fn (string $field) => round(
            $this->sumSigned($siRows, $field)
            + $this->sumSigned($otherRows, $field)
            - $this->sumCreditMemo($cmRows, $field),
            2
        );

        $exemptSales = $netAmount('exempt_sales');
        $zeroRatedSales = $netAmount('zero_rated_sales');
        $netAmountValue = $netAmount('net_amount');
        $vatableGross = round($netAmountValue - $exemptSales - $zeroRatedSales, 2);
        $taxableSales = round($vatableGross / 1.12, 2);
        $outputVat = round($vatableGross - $taxableSales, 2);

        return [
            'id' => $first->id,
            'records_count' => $group->count(),
            'si_count' => $siRows->count(),
            'cm_count' => $cmRows->count(),
            'customer_type' => $first->customer_type ?: 'company',
            'customer_tin' => $first->customer_tin,
            'customer_name' => $first->customer_name,
            'company_name' => $first->customer_type === 'individual'
                ? ''
                : ($first->company_name ?: $first->customer_name),
            'last_name' => $first->last_name,
            'first_name' => $first->first_name,
            'middle_name' => $first->middle_name,
            'address1' => $first->address1,
            'address2' => $first->address2,
            'exempt_sales' => $exemptSales,
            'zero_rated_sales' => $zeroRatedSales,
            'taxable_sales' => $taxableSales,
            'taxable_net_of_vat' => $taxableSales,
            'output_vat' => $outputVat,
            'net_amount' => $netAmountValue,
            'gross_amount' => $netAmount('gross_amount'),
            'si_taxable_sales' => round($this->sumSigned($siRows, 'taxable_net_of_vat'), 2),
            'cm_taxable_sales' => round($this->sumCreditMemo($cmRows, 'taxable_net_of_vat'), 2),
            'si_output_vat' => round($this->sumSigned($siRows, 'output_vat'), 2),
            'cm_output_vat' => round($this->sumCreditMemo($cmRows, 'output_vat'), 2),
        ];
    }

    /**
     * @param  Collection<int, SalesVatInput>  $records
     */
    private function sumSigned(Collection $records, string $field): float
    {
        return round($records->sum(fn (SalesVatInput $record) => (float) $record->{$field}), 2);
    }

    /**
     * @param  Collection<int, SalesVatInput>  $records
     */
    private function sumCreditMemo(Collection $records, string $field): float
    {
        return round($records->sum(fn (SalesVatInput $record) => abs((float) $record->{$field})), 2);
    }

    private function documentType(SalesVatInput $record): string
    {
        $type = strtoupper(trim((string) $record->document_type));

        if ($type !== '') {
            return $type;
        }

        $documentNo = strtoupper(trim((string) $record->document_no));

        if (preg_match('/^SI(?:#|\b|-)?/', $documentNo)) {
            return 'SI';
        }

        if (preg_match('/^CM(?:#|\b|-)?/', $documentNo)) {
            return 'CM';
        }

        return 'OTHER';
    }
}
