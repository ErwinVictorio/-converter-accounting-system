<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/** Read-only totals of the amounts displayed in Record tables. */
class RecordSummaryService
{
    public function purchases(iterable $rows): array
    {
        return $this->sum($rows, [
            'purchase_local_total' => 'display_purchase_local_amount',
            'services_total' => 'display_services_amount',
            'others_total' => 'display_others_amount',
            'total_purchase_amount' => 'display_calculated_total',
        ]);
    }

    public function sales(iterable $rows): array
    {
        return $this->sum($rows, [
            'exempt_sales_total' => 'exempt_sales',
            'zero_rated_sales_total' => 'zero_rated_sales',
            'taxable_net_of_vat_total' => 'taxable_net_of_vat',
            'output_vat_total' => 'output_vat',
            'gross_amount_total' => 'gross_amount',
        ]);
    }

    public function importations(Builder $query): array
    {
        // Storage columns are DECIMAL(14, 2); aggregate before pagination.
        $fields = [
            'total_landed_cost' => 'total_landed_cost',
            'dutiable_value_total' => 'dutiable_value',
            'charges_total' => 'charges',
            'exempt_total' => 'exempt',
            'taxable_goods_total' => 'taxable_goods',
            'vat_payable_total' => 'vat_payable',
        ];
        $aggregate = (clone $query)->reorder()->selectRaw('COUNT(*) as record_count');
        foreach ($fields as $key => $field) {
            $aggregate->selectRaw("COALESCE(SUM($field), 0) as $key");
        }
        $row = $aggregate->toBase()->first();
        $result = ['record_count' => (int) $row->record_count];
        foreach ($fields as $key => $field) {
            $result[$key] = number_format((float) $row->{$key}, 2, '.', '');
        }

        return $result;
    }

    private function sum(iterable $rows, array $fields): array
    {
        $cents = array_fill_keys(array_keys($fields), 0);
        $count = 0;
        foreach ($rows as $row) {
            $count++;
            foreach ($fields as $key => $field) {
                $cents[$key] += (int) round((float) ($row[$field] ?? 0) * 100);
            }
        }

        return ['record_count' => $count] + array_map(
            fn (int $value) => number_format($value / 100, 2, '.', ''), $cents
        );
    }
}
