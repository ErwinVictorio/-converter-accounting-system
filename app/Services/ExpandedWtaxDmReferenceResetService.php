<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpandedWtaxDmReferenceResetService
{
    private function identity(array $scope): string
    {
        return hash('sha256', implode('|', [$scope['withholding_agent_tin'], $scope['withholding_agent_branch_code'], $scope['report_type'], $scope['reporting_month']]));
    }

    public function generation(array $scope): int
    {
        return (int) DB::table('expanded_dm_scopes')->where('identity', $this->identity($scope))->value('generation');
    }

    // Called inside a transaction before locking any Expanded records.
    public function lock(array $scope): int
    {
        $identity = $this->identity($scope);
        try {
            DB::table('expanded_dm_scopes')->insertOrIgnore(['identity' => $identity, 'generation' => 0]);

            return (int) DB::table('expanded_dm_scopes')->where('identity', $identity)->lockForUpdate()->value('generation');
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [5, 1205, 1213], true)) {
                throw ValidationException::withMessages(['upload' => 'Another Expanded upload or DM confirmation is processing this month. Please retry when it finishes.']);
            }
            throw $error;
        }
    }

    public function reset(array $scope): int
    {
        $query = DB::table('expanded_dm_applications')->whereNull('superseded_at');
        foreach (['withholding_agent_tin', 'withholding_agent_branch_code', 'report_type', 'reporting_month'] as $field) {
            $query->where('scope->'.$field, $scope[$field]);
        }
        $ids = $query->pluck('id');
        $count = DB::table('expanded_dm_references')->whereIn('application_id', $ids)->delete();
        DB::table('expanded_dm_applications')->whereIn('id', $ids)->update(['superseded_at' => now()]);
        DB::table('expanded_dm_scopes')->where('identity', $this->identity($scope))->increment('generation');

        return $count;
    }
}
