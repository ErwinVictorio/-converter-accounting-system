<?php

namespace App\Services;

use App\Imports\ExpandedWtaxDmReader as Money;
use App\Models\ExpandedWtaxEntry;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExpandedWtaxDmAdjustmentService
{
    public function query(array $scope): Builder
    {
        return ExpandedWtaxEntry::query()
            ->where('withholding_agent_tin', $scope['withholding_agent_tin'])
            ->where('withholding_agent_branch_code', $scope['withholding_agent_branch_code'])
            ->where('report_type', 'quarterly')
            ->whereBetween('reporting_period', [$scope['reporting_month'].'-01', date('Y-m-t', strtotime($scope['reporting_month'].'-01'))])
            ->where('tax_rate', $scope['percentage'])->orderBy('id');
    }

    public static function name(string $name): string
    {
        $name = str_replace('&', ' AND ', strtoupper(trim($name)));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ', $name)));
    }

    public function snapshot(Collection $records): string
    {
        return hash('sha256', $records->map(fn ($record) => $record->getAttributes())->toJson());
    }

    public function review(array $parsed, Collection $records): array
    {
        $groups = $records->groupBy(fn ($record) => ExpandedWtaxEntry::consolidationKey($record));
        $targets = [];
        $issues = [];
        foreach ($parsed['items'] as $item) {
            [$status, $reason, $key] = app(ExpandedWtaxDmReviewService::class)->match($item, $records);
            if ($status !== 'ready') {
                $issues[] = "Row {$item['row']}: {$item['supplier']}: {$reason}";

                continue;
            }
            $group = $groups[$key];
            $tin = $group->first()->payee_tin;
            $targets[$key] ??= ['supplier' => $group->first()->payee_name, 'key' => $key, 'tin' => $tin, 'atc' => $group->first()->atc_code, 'rate' => $group->first()->tax_rate, 'source_count' => $group->count(), 'debit_cents' => 0, 'items' => []];
            $targets[$key]['debit_cents'] += $item['debit_cents'];
            $targets[$key]['items'][] = $item;
        }
        $changes = [];
        $warnings = [];
        $negativeWarnings = [];
        foreach ($targets as $key => &$target) {
            $group = $groups[$key];
            $before = $group->sum(fn ($record) => Money::cents($record->tax_withheld));
            $remaining = $target['debit_cents'];
            $target['before'] = Money::money($before);
            $target['deduction'] = Money::money($remaining);
            $target['after'] = Money::money($before - $remaining);
            $adjusted = collect();
            $incomeBefore = $group->sum(fn ($record) => Money::cents($record->income_payment));
            $rateUnits = Money::cents($group->first()->tax_rate);
            $allocatedTax = 0;
            $allocatedIncome = 0;
            foreach ($group as $record) {
                $copy = clone $record;
                $amount = Money::cents($record->tax_withheld);
                // Preserve ID-order allocation; the last source absorbs any excess.
                // Existing zero/negative sources never turn a deduction into an addition.
                $deduction = $record->id === $group->last()->id
                    ? $remaining
                    : min($remaining, max(0, $amount));
                if ($deduction > 0) {
                    $copy->tax_withheld = Money::money($amount - $deduction);
                    $allocatedTax += $deduction;
                    // Cumulative rounding preserves the group deduction across sources.
                    $baseTotal = (int) round($allocatedTax * 10000 / $rateUnits);
                    $baseDeduction = $baseTotal - $allocatedIncome;
                    $allocatedIncome = $baseTotal;
                    $income = Money::cents($record->income_payment);
                    $copy->income_payment = Money::money($income - $baseDeduction);
                    $changes[] = ['id' => $record->id, 'before' => $record->tax_withheld, 'after' => $copy->tax_withheld,
                        'income_before' => $record->income_payment, 'income_after' => $copy->income_payment];
                    $remaining -= $deduction;
                }
                $adjusted->push($copy);
            }
            $target['income_before'] = Money::money($incomeBefore);
            $target['income_deduction'] = Money::money($allocatedIncome);
            $target['income_after'] = Money::money($incomeBefore - $allocatedIncome);
            if ($adjusted->contains(fn ($record) => Money::cents($record->tax_withheld) < 0 || Money::cents($record->income_payment) < 0)) {
                $negativeWarnings[] = "{$target['supplier']}: this deduction leaves a negative Tax Withheld or Income Payment balance (supplier totals: Tax Withheld {$target['after']}, Income Payment {$target['income_after']}). You may confirm to save the negative amounts.";
            }
            foreach (ExpandedWtaxEntry::consolidate($adjusted) as $row) {
                foreach (app(BirExpandedWtaxRowValidator::class)->validate($row, 0) as $error) {
                    $warnings[] = $target['supplier'].': '.preg_replace('/^Row 0: /', '', $error);
                }
            }
        }
        unset($target);

        return ['targets' => array_values($targets), 'issues' => $issues, 'warnings' => array_values(array_unique(array_merge($negativeWarnings, $warnings))), 'negative_balance_warnings' => $negativeWarnings, 'bir_warnings' => array_values(array_unique($warnings)), 'changes' => $changes,
            'total_deduction' => Money::money(array_sum(array_column($parsed['items'], 'debit_cents'))),
            'dm_count' => count($parsed['items']), 'excluded_count' => $parsed['excluded_count'], 'excluded_debit' => $parsed['excluded_debit']];
    }
}
