<?php

namespace App\Services;

use App\Models\VatInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PurchaseDmReferenceResetService
{
    public function identity(array $scope): string
    {
        return $scope['filing_company_tin'].'|'.$scope['filing_company_branch_code'].'|'.$scope['reporting_month'].'-01';
    }

    public function available(): bool
    {
        foreach (['purchase_dm_scopes', 'purchase_dm_applications', 'purchase_dm_references', 'purchase_dm_sources'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    public function generation(array $scope): int
    {
        return (int) DB::table('purchase_dm_scopes')->where('identity', $this->identity($scope))->value('generation');
    }

    // Called within a transaction, before record locks; shared with regular replacement.
    public function lock(array $scope): int
    {
        $identity = $this->identity($scope);
        DB::table('purchase_upload_locks')->insertOrIgnore(['identity' => $identity]);
        DB::table('purchase_upload_locks')->where('identity', $identity)->lockForUpdate()->first();
        if (! $this->available()) {
            return 0;
        }
        DB::table('purchase_dm_scopes')->insertOrIgnore(['identity' => $identity, 'generation' => 0]);

        return $this->generation($scope);
    }

    public function lockRecordScope(VatInput $record): void
    {
        if ($record->filing_company_tin && $record->filing_company_branch_code && $record->date_uploaded) {
            $this->lock(['filing_company_tin' => $record->filing_company_tin, 'filing_company_branch_code' => $record->filing_company_branch_code,
                'reporting_month' => $record->date_uploaded->format('Y-m')]);
        }
    }

    public function guardMutation(int $id): void
    {
        if ($this->available() && DB::table('purchase_dm_sources')->where('vat_input_id', $id)->whereNull('retired_at')->exists()) {
            throw ValidationException::withMessages(['purchase_dm' => 'This Purchase record has active DM deductions. Re-upload the original Purchase workbook before changing its identity or transferring amounts.']);
        }
    }

    public function reset(array $scope, array $replacedIds): void
    {
        if (! $this->available()) {
            return;
        }
        $applications = DB::table('purchase_dm_sources')->whereIn('vat_input_id', $replacedIds)->whereNull('retired_at')->pluck('application_id')->unique();
        foreach ($applications as $id) {
            if (DB::table('purchase_dm_sources')->where('application_id', $id)->whereNull('retired_at')->whereNotIn('vat_input_id', $replacedIds)->exists()) {
                throw ValidationException::withMessages(['purchase_dm' => 'This replacement covers only part of an active DM application. Review the Purchase scope before replacing records.']);
            }
            DB::table('purchase_dm_references')->where('application_id', $id)->delete();
            DB::table('purchase_dm_sources')->where('application_id', $id)->update(['retired_at' => now()]);
            DB::table('purchase_dm_applications')->where('id', $id)->update(['superseded_at' => now()]);
        }
        DB::table('purchase_dm_scopes')->where('identity', $this->identity($scope))->increment('generation');
    }
}
