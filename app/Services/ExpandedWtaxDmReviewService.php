<?php

namespace App\Services;

use App\Imports\ExpandedWtaxDmReader as Money;
use App\Models\ExpandedWtaxEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpandedWtaxDmReviewService
{
    public const VERSION = 4;

    public function identity(array $scope, array $item): string
    {
        return hash('sha256', implode('|', [$scope['withholding_agent_tin'], $scope['withholding_agent_branch_code'],
            $scope['report_type'], $scope['reporting_month'], $scope['percentage'], $item['reference']]));
    }

    public function match(array $item, Collection $records): array
    {
        $matches = $records->filter(fn ($record) => ExpandedWtaxDmAdjustmentService::name($record->payee_name) === ExpandedWtaxDmAdjustmentService::name($item['supplier']));
        $tins = $matches->pluck('payee_tin')->unique()->values();
        if ($matches->isEmpty()) {
            return ['no_matching_record', 'No matching Expanded record. Check supplier name, company, branch, month and rate.', null];
        }
        if ($tins->count() > 1) {
            return ['ambiguous_tin', 'The supplier name matches multiple TINs. Correct the regular Expanded records.', null];
        }
        if (! preg_match('/^(?!000000000)\d{9}$/', (string) $tins->first())) {
            return ['invalid_tin', 'The matching supplier has a missing or invalid TIN. Correct the regular Expanded record.', null];
        }
        $keys = $records->where('payee_tin', $tins->first())->map(fn ($record) => ExpandedWtaxEntry::consolidationKey($record))->unique();
        if ($keys->count() !== 1) {
            return ['ambiguous_group', 'Multiple payee/ATC groups match this TIN and rate. Review their identities.', null];
        }
        $key = $keys->first();
        $group = $records->filter(fn ($record) => ExpandedWtaxEntry::consolidationKey($record) === $key);
        if ($group->pluck('payee_tin')->unique()->count() !== 1 || $group->pluck('payee_branch_code')->unique()->count() !== 1) {
            return ['conflicting_identity', 'The consolidated group has conflicting TINs or branches. Correct its identity.', null];
        }

        return ['ready', 'Ready to apply.', $key];
    }

    public function review(array $scope, array $parsed, Collection $records, bool $available = true): array
    {
        $identities = array_map(fn ($item) => $this->identity($scope, $item), $parsed['items']);
        $applied = $available ? DB::table('expanded_dm_references')->whereIn('identity', $identities)->pluck('identity')->flip()->all() : [];
        $entries = [];
        $eligible = [];
        foreach ($parsed['items'] as $item) {
            $identity = $this->identity($scope, $item);
            [$status, $reason, $key] = isset($applied[$identity])
                ? ['already_applied', 'This reference was already applied for this company, branch, month and rate. No additional deduction; changed amounts are not applied as a difference.', null]
                : $this->match($item, $records);
            $entries[] = $item + ['key' => $identity, 'status' => $status, 'reason' => $reason, 'target_key' => $key, 'amount' => Money::money($item['debit_cents'])];
            if ($status === 'ready') {
                $eligible[] = $item;
            }
        }
        $review = app(ExpandedWtaxDmAdjustmentService::class)->review(array_replace($parsed, ['items' => $eligible]), $records);
        $summary = [];
        foreach (['file' => $entries, 'eligible' => array_filter($entries, fn ($e) => $e['status'] === 'ready'),
            'skipped' => array_filter($entries, fn ($e) => $e['status'] !== 'ready'),
            'already_applied' => array_filter($entries, fn ($e) => $e['status'] === 'already_applied'),
            'needs_review' => array_filter($entries, fn ($e) => ! in_array($e['status'], ['ready', 'already_applied'], true))] as $name => $items) {
            $summary[$name.'_count'] = count($items);
            $summary[$name.'_total'] = Money::money(array_sum(array_column($items, 'debit_cents')));
        }
        // Resolve skipped references for display only; this never changes their eligibility.
        $groups = [];
        foreach ($entries as $entry) {
            $displayKey = $entry['target_key'];
            if ($entry['status'] === 'already_applied') {
                [, , $displayKey] = $this->match($entry, $records);
            }
            $groupKey = $displayKey ?? 'unresolved:'.ExpandedWtaxDmAdjustmentService::name($entry['supplier']);
            $groups[$groupKey] ??= ['key' => $groupKey, 'supplier' => $entry['supplier'], 'target_key' => $displayKey, 'entries' => []];
            $groups[$groupKey]['entries'][] = $entry;
        }
        $review['review_version'] = self::VERSION;
        $review['details_version'] = self::VERSION;
        $review['summary'] = $summary;
        $review['entries'] = $entries;
        $review['supplier_groups'] = array_values($groups);
        $review['dm_count'] = count($entries);
        $review['fingerprint'] = hash('sha256', json_encode([$entries, $summary, $review['changes']]));

        return $review;
    }
}
