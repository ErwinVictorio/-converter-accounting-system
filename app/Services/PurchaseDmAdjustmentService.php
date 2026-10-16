<?php

namespace App\Services;

use App\Imports\ExpandedWtaxDmReader as Money;
use App\Models\Brokers;
use App\Models\VatInput;
use App\Services\BIR\BirPurchaseRowValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseDmAdjustmentService
{
    public const VERSION = 1;

    public const BASES = ['local' => 'purchase_local', 'imported' => 'purchase_imported', 'services' => 'services', 'others' => 'others'];

    public function query(array $scope): Builder
    {
        return VatInput::query()->forFilingCompany(['tin' => $scope['filing_company_tin'], 'branch_code' => $scope['filing_company_branch_code']])
            ->whereBetween('date_uploaded', [$scope['reporting_month'] . '-01', date('Y-m-t', strtotime($scope['reporting_month'] . '-01'))])
            ->excludingImportationMirrors()->withCount(['outgoingPurchaseAdjustments', 'incomingPurchaseAdjustments'])->orderBy('id');
    }

    public function snapshot(Collection $records): string
    {
        return hash('sha256', $records->map(fn($r) => $r->getAttributes())->toJson());
    }

    public function identity(array $scope, array $item): string
    {
        return hash('sha256', implode('|', [$scope['filing_company_tin'], $scope['filing_company_branch_code'], $scope['reporting_month'], $scope['purchase_type'], $item['normalized_reference']]));
    }

    public function payloadHash(array $item): string
    {
        return hash('sha256', json_encode([ExpandedWtaxDmAdjustmentService::name($item['supplier']), $item['date'], $item['credit_cents']]));
    }

    private function match(array $item, Collection $records, string $type, array $brokerTins): array
    {
        $matches = $records->filter(fn($r) => ExpandedWtaxDmAdjustmentService::name($r->supplier_name) === ExpandedWtaxDmAdjustmentService::name($item['supplier']));
        if ($matches->isEmpty()) {
            return ['no_matching_record', 'No Purchase supplier matches this company, branch and month.', collect()];
        }
        $identities = $matches->map(fn($r) => preg_replace('/\D/', '', (string) $r->tin_number))->unique();
        if ($identities->count() !== 1) {
            return ['ambiguous_identity', 'Supplier name matches multiple tax identities.', collect()];
        }
        if (! preg_match('/^(?!000000000)(?:\d{9}|\d{12})$/', $identities->first())) {
            return ['invalid_tin', 'The matched supplier has a missing or invalid TIN.', collect()];
        }
        // Different raw names collapsing to one normalized name require human review.
        if ($matches->pluck('supplier_name')->map(fn($n) => strtoupper(trim($n)))->unique()->count() !== 1) {
            return ['ambiguous_identity', 'Multiple stored supplier names normalize to the same name.', collect()];
        }
        if ($matches->contains(fn($r) => $r->is_adjusted || $r->is_broker || in_array(substr(preg_replace('/\D/', '', (string) $r->tin_number), 0, 9), $brokerTins, true)
            || $r->outgoing_purchase_adjustments_count || $r->incoming_purchase_adjustments_count)) {
            return ['unsupported_record', 'Broker, transferred or adjusted records are not eligible for Purchase DM.', collect()];
        }
        if ($matches->pluck('is_imported')->unique()->count() !== 1 || ($type === 'imported' && ! $matches->first()->is_imported)) {
            return ['ambiguous_group', 'Purchase classification does not identify one compatible original group.', collect()];
        }
        $signature = $matches->map(fn($r) => json_encode([$r->vendor_type, $r->company_name, $r->last_name, $r->first_name, $r->middle_name, $r->address1, $r->address2]))->unique();
        if ($signature->count() !== 1) {
            return ['ambiguous_identity', 'Stored supplier identity details conflict across original rows.', collect()];
        }
        foreach ($matches as $r) {
            if ($r->uses_vat_bucket_amounts !== true || Money::cents($r->vat_rate) !== 1200) {
                return ['unsupported_record', 'This record needs saved VAT amounts at 12%.', collect()];
            }
            foreach (self::BASES as $base) {
                if ($r->{$base . '_vat_amount'} === null) {
                    return ['unsupported_record', 'Original VAT amounts are missing. Review the Purchase upload.', collect()];
                }
            }
        }

        return ['ready', 'Ready to apply.', $matches];
    }

    public function review(array $scope, array $parsed, Collection $records, bool $available = true): array
    {
        $keys = array_map(fn($item) => $this->identity($scope, $item), $parsed['items']);
        $applied = $available ? DB::table('purchase_dm_references')->whereIn('identity', $keys)->get()->keyBy('identity') : collect();
        $brokerTins = Brokers::query()->pluck('tin_number')->map(fn($tin) => substr(preg_replace('/\D/', '', (string) $tin), 0, 9))->all();
        $entries = [];
        $groups = [];
        foreach ($parsed['items'] as $item) {
            $key = $this->identity($scope, $item);
            $hash = $this->payloadHash($item);
            $matched = collect();
            if ($item['invalid_reason']) {
                $status = 'invalid_dm';
                $reason = $item['invalid_reason'];
            } elseif ($prior = $applied->get($key)) {
                $status = hash_equals($prior->payload_hash, $hash) ? 'already_applied' : 'conflicting_reference';
                $reason = $status === 'already_applied' ? 'Already applied in this scope; no additional deduction.' : 'This reference was already applied with different supplier, date or Credit.';
            } else {
                [$status, $reason, $matched] = $this->match($item, $records, $scope['purchase_type'], $brokerTins);
            }
            $targetKey = $matched->isNotEmpty() ? implode(',', $matched->pluck('id')->all()) : null;
            $entries[] = $item + [
                'key' => $key,
                'payload_hash' => $hash,
                'status' => $status,
                'reason' => $reason,
                'target_key' => $targetKey,
                'amount' => $item['credit_cents'] === null ? null : Money::money($item['credit_cents'])
            ];
            if ($status === 'ready') {
                $groups[$targetKey] ??= ['records' => $matched, 'credit' => 0, 'supplier' => $matched->first()->supplier_name];
                $groups[$targetKey]['credit'] += $item['credit_cents'];
            }
        }
        $targets = [];
        $changes = [];
        $warnings = [];
        $blocked = [];
        $base = self::BASES[$scope['purchase_type']];
        $raw = $base . '_vat_amount';
        foreach ($groups as $key => $group) {
            $deduction = $group['credit'];
            $remaining = $deduction;
            $allocated = 0;
            $allocatedBase = 0;
            $copies = collect();
            $groupChanges = [];
            $groupWarnings = [];
            $overflow = false;
            foreach ($group['records'] as $record) {
                $copy = clone $record;
                $take = $record->id === $group['records']->last()->id ? $remaining : min($remaining, max(0, Money::cents($record->{$raw})));
                if ($take > 0) {
                    $allocated += $take;
                    // Exact half-up conversion of VAT cents at 12%; cumulative allocation preserves cents.
                    $baseTotal = intdiv($allocated * 100 + 6, 12);
                    $baseTake = $baseTotal - $allocatedBase;
                    $allocatedBase = $baseTotal;
                    $deltas = [$raw => $take, $base => $baseTake, 'input_vat' => $take, 'taxable_net_of_vat' => $baseTake, 'total' => $baseTake, 'total_purchases' => $baseTake + $take];
                    if ($base === 'purchase_imported') {
                        $deltas['capital_goods'] = $baseTake;
                    } elseif ($base !== 'services') {
                        $deltas['other_than_capital_goods'] = $baseTake;
                    }
                    $before = [];
                    $after = [];
                    foreach ($deltas as $field => $amount) {
                        $before[$field] = (string) $record->{$field};
                        $next = Money::cents($record->{$field}) - $amount;
                        // Original base columns are DECIMAL(10,2); reject before any write.
                        $limit = in_array($field, ['purchase_imported', 'purchase_local', 'services', 'others', 'total'], true) ? 9999999999 : 99999999999999;
                        if (abs($next) > $limit) {
                            $overflow = true;
                        }
                        $copy->{$field} = $after[$field] = Money::money($next);
                    }
                    $groupChanges[] = ['id' => $record->id, 'before' => $before, 'after' => $after];
                    $remaining -= $take;
                }
                if (Money::cents($copy->{$raw}) < 0 || Money::cents($copy->{$base}) < 0 || Money::cents($copy->input_vat) < 0) {
                    $groupWarnings[] = $group['supplier'] . ': The remaining balance will be negative. Please confirm to continue.';
                }
                foreach (app(BirPurchaseRowValidator::class)->validate($copy->toBirPurchaseRow(), 0) as $warning) {
                    $groupWarnings[] = $group['supplier'] . ': ' . preg_replace('/^Row 0: /', '', $warning);
                }
                $copies->push($copy);
            }
            if ($overflow) {
                $blocked[] = (string) $key;

                continue;
            }
            $fields = ['vat' => $raw, 'base' => $base, 'input_vat' => 'input_vat', 'gross' => 'total_purchases'];
            $metrics = [];
            foreach ($fields as $label => $field) {
                $before = $group['records']->sum(fn($r) => Money::cents($r->{$field}));
                $after = $copies->sum(fn($r) => Money::cents($r->{$field}));
                $metrics[$label] = ['before' => Money::money($before), 'deduction' => Money::money($before - $after), 'after' => Money::money($after)];
            }
            // Existing presenter computes each stored Purchase row's displayed Total.
            $display = fn($rs) => $rs->sum(fn($r) => Money::cents(app(PurchaseAmountPresenter::class)->forRecord($r)['display_calculated_total']));
            $metrics['visible_total'] = ['before' => Money::money($display($group['records'])), 'deduction' => Money::money($display($group['records']) - $display($copies)), 'after' => Money::money($display($copies))];
            $targets[] = ['key' => (string) $key, 'supplier' => $group['supplier'], 'source_count' => $copies->count(), 'metrics' => $metrics];
            array_push($changes, ...$groupChanges);
            array_push($warnings, ...$groupWarnings);
        }
        foreach ($entries as &$entry) {
            if ($entry['status'] === 'ready' && in_array((string) $entry['target_key'], $blocked, true)) {
                $entry['status'] = 'amount_out_of_range';
                $entry['reason'] = 'The resulting amount exceeds the supported database range.';
            }
        }
        unset($entry);
        $summary = [];
        foreach (['file' => $entries, 'eligible' => array_filter($entries, fn($e) => $e['status'] === 'ready'), 'skipped' => array_filter($entries, fn($e) => $e['status'] !== 'ready')] as $label => $items) {
            $summary[$label . '_count'] = count($items);
            $summary[$label . '_total'] = Money::money(array_sum(array_column($items, 'credit_cents')));
            $summary[$label . '_unknown_count'] = count(array_filter($items, fn($e) => $e['credit_cents'] === null));
        }

        return [
            'review_version' => self::VERSION,
            'entries' => $entries,
            'targets' => $targets,
            'changes' => $changes,
            'warnings' => array_values(array_unique($warnings)),
            'summary' => $summary,
            'excluded' => $parsed['excluded'],
            'excluded_count' => $parsed['excluded_count'],
            'total_deduction' => $summary['eligible_total'],
            'fingerprint' => hash('sha256', json_encode([$entries, $changes, $warnings]))
        ];
    }
}
