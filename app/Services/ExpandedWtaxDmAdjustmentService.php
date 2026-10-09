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
            $matches = $records->filter(fn ($record) => self::name($record->payee_name) === self::name($item['supplier']));
            $tins = $matches->pluck('payee_tin')->unique()->values();
            if ($tins->count() !== 1 || ! preg_match('/^(?!000000000)\d{9}$/', (string) $tins->first())) {
                $issues[] = "Row {$item['row']}: {$item['supplier']} has no unique valid TIN match in the selected scope.";

                continue;
            }
            $keys = $records->where('payee_tin', $tins->first())->map(fn ($record) => ExpandedWtaxEntry::consolidationKey($record))->unique();
            if ($keys->count() !== 1) {
                $issues[] = "Row {$item['row']}: multiple payee/ATC groups match this TIN and rate.";

                continue;
            }
            $key = $keys->first();
            $group = $groups[$key];
            if ($group->pluck('payee_tin')->unique()->count() !== 1 || $group->pluck('payee_branch_code')->unique()->count() !== 1) {
                $issues[] = "Row {$item['row']}: the consolidated group has conflicting TINs or branches.";

                continue;
            }
            $targets[$key] ??= ['supplier' => $group->first()->payee_name, 'tin' => $tins->first(), 'atc' => $group->first()->atc_code, 'rate' => $group->first()->tax_rate, 'source_count' => $group->count(), 'debit_cents' => 0, 'items' => []];
            $targets[$key]['debit_cents'] += $item['debit_cents'];
            $targets[$key]['items'][] = $item;
        }
        $changes = [];
        $warnings = [];
        foreach ($targets as $key => &$target) {
            $group = $groups[$key];
            $before = $group->sum(fn ($record) => Money::cents($record->tax_withheld));
            $remaining = $target['debit_cents'];
            $target['before'] = Money::money($before);
            $target['deduction'] = Money::money($remaining);
            $target['after'] = Money::money($before - $remaining);
            if ($remaining > $before || $group->contains(fn ($record) => Money::cents($record->tax_withheld) < 0)) {
                $issues[] = "{$target['supplier']}: deduction exceeds the available total or the group contains negative source amounts; manual review is required.";

                continue;
            }
            $adjusted = collect();
            foreach ($group as $record) {
                $copy = clone $record;
                $amount = Money::cents($record->tax_withheld);
                $deduction = min($remaining, $amount);
                if ($deduction > 0) {
                    $copy->tax_withheld = Money::money($amount - $deduction);
                    $changes[] = ['id' => $record->id, 'before' => $record->tax_withheld, 'after' => $copy->tax_withheld];
                    $remaining -= $deduction;
                }
                $adjusted->push($copy);
            }
            foreach (ExpandedWtaxEntry::consolidate($adjusted) as $row) {
                foreach (app(BirExpandedWtaxRowValidator::class)->validate($row, 0) as $error) {
                    $warnings[] = $target['supplier'].': '.preg_replace('/^Row 0: /', '', $error);
                }
            }
        }
        unset($target);

        return ['targets' => array_values($targets), 'issues' => $issues, 'warnings' => array_values(array_unique($warnings)), 'changes' => $changes,
            'total_deduction' => Money::money(array_sum(array_column($parsed['items'], 'debit_cents'))),
            'dm_count' => count($parsed['items']), 'excluded_count' => $parsed['excluded_count'], 'excluded_debit' => $parsed['excluded_debit']];
    }
}
