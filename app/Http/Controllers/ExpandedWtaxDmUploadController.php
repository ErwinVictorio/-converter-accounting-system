<?php

namespace App\Http\Controllers;

use App\Imports\ExpandedWtaxDmReader;
use App\Services\ExpandedWtaxDmAdjustmentService;
use App\Services\ExpandedWtaxDmReferenceResetService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExpandedWtaxDmUploadController extends Controller
{
    public function review(Request $request, ExpandedWtaxDmReader $reader, ExpandedWtaxDmAdjustmentService $service)
    {
        $scope = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'reporting_month' => ['required', 'date_format:Y-m'],
            'report_type' => ['required', 'in:quarterly'],
            'withholding_agent_tin' => ['required', 'regex:/^(?!000000000)\d{9}$/'],
            'withholding_agent_branch_code' => ['required', 'regex:/^\d{1,4}$/'],
            'percentage' => ['required', 'numeric', 'gt:0', 'max:100', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ]);
        unset($scope['excel_file']);
        $scope['percentage'] = number_format((float) $scope['percentage'], 2, '.', '');
        $scope['withholding_agent_branch_code'] = str_pad($scope['withholding_agent_branch_code'], 4, '0', STR_PAD_LEFT);
        try {
            $parsed = $reader->read($request->file('excel_file')->getRealPath(), $scope['reporting_month'], $scope['percentage']);
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['excel_file' => 'Unable to read the workbook. Use an unencrypted ACCOUNT DETAILS Excel file.']);
        }
        $generation = app(ExpandedWtaxDmReferenceResetService::class)->generation($scope);
        $records = $service->query($scope)->get();
        $review = $service->review($parsed, $records);
        $available = Schema::hasTable('expanded_dm_references') && Schema::hasTable('expanded_dm_applications');
        if ($available) {
            $review['issues'] = array_merge($review['issues'], $this->duplicateIssues($scope, $parsed));
        }
        $token = null;
        if ($available && ! $review['issues']) {
            $token = (string) Str::uuid();
            Cache::put('expanded-dm:'.$token, ['user_id' => $request->user()->getAuthIdentifier(), 'scope' => $scope, 'parsed' => $parsed,
                'generation' => $generation, 'calculation_version' => 3, 'snapshot' => $service->snapshot($records), 'digest' => hash_file('sha256', $request->file('excel_file')->getRealPath())], now()->addMinutes(20));
        }
        unset($review['changes']);

        return response()->json($review + ['token' => $token, 'scope' => $scope, 'apply_available' => $available]);
    }

    public function apply(Request $request, ExpandedWtaxDmAdjustmentService $service)
    {
        $request->validate(['token' => ['required', 'uuid']]);
        if (! Schema::hasTable('expanded_dm_applications') || ! Schema::hasTable('expanded_dm_references')) {
            throw ValidationException::withMessages(['token' => 'DM adjustments are currently preview-only.']);
        }
        $token = $request->string('token')->toString();
        $completed = DB::table('expanded_dm_applications')->where('token', $token)->first();
        if ($completed) {
            abort_unless((string) $completed->user_id === (string) $request->user()->getAuthIdentifier(), 403);

            return $this->completedResponse($completed);
        }
        $pending = Cache::get('expanded-dm:'.$token);
        if (! $pending || ($pending['calculation_version'] ?? 1) !== 3) {
            throw ValidationException::withMessages(['token' => 'This review has expired. Upload the file again to review current totals.']);
        }
        abort_unless((string) $pending['user_id'] === (string) $request->user()->getAuthIdentifier(), 403);
        try {
            $total = DB::transaction(function () use ($pending, $service, $token, $request) {
                $generation = app(ExpandedWtaxDmReferenceResetService::class)->lock($pending['scope']);
                $completed = DB::table('expanded_dm_applications')->where('token', $token)->first();
                if ($completed) {
                    $this->ensureActive($completed);

                    return $completed->total_deduction;
                }
                if (! isset($pending['generation']) || $pending['generation'] !== $generation) {
                    throw ValidationException::withMessages(['token' => 'Expanded records were replaced. Upload the DM file again to review the new records.']);
                }
                $records = $service->query($pending['scope'])->lockForUpdate()->get();
                if ($service->snapshot($records) !== $pending['snapshot']) {
                    throw ValidationException::withMessages(['token' => 'Expanded records changed after review. Upload again to review the latest totals.']);
                }
                $review = $service->review($pending['parsed'], $records);
                $issues = array_merge($review['issues'], $this->duplicateIssues($pending['scope'], $pending['parsed']));
                if ($issues) {
                    throw ValidationException::withMessages(['token' => $issues]);
                }
                // Confirmation acknowledges the concise warning shown with the review.
                if ($review['warnings'] && ! $request->boolean('acknowledge_warnings')) {
                    throw ValidationException::withMessages(['token' => 'Review the adjustment warnings before confirming this deduction.']);
                }
                $application = DB::table('expanded_dm_applications')->insertGetId([
                    'token' => $token, 'user_id' => $pending['user_id'], 'file_digest' => $pending['digest'],
                    'scope' => json_encode($pending['scope']), 'details' => json_encode($review),
                    'total_deduction' => $review['total_deduction'], 'created_at' => now(),
                ]);
                foreach ($pending['parsed']['items'] as $item) {
                    DB::table('expanded_dm_references')->insert(['application_id' => $application,
                        'identity' => $this->identity($pending['scope'], $item), 'reference' => $item['reference']]);
                }
                foreach ($review['changes'] as $change) {
                    $record = $records->firstWhere('id', $change['id']);
                    $record->tax_withheld = $change['after'];
                    $record->income_payment = $change['income_after'];
                    $record->save();
                }

                return $review['total_deduction'];
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            // Unique token/reference keys are the final guard across concurrent reviews.
            $completed = DB::table('expanded_dm_applications')->where('token', $token)->where('user_id', $pending['user_id'])->first();
            if ($completed) {
                return $this->completedResponse($completed);
            }
            throw ValidationException::withMessages(['token' => 'One or more DM references have already been applied. No new deductions were saved.']);
        }
        Cache::forget('expanded-dm:'.$token);

        return $this->success($total);
    }

    private function identity(array $scope, array $item): string
    {
        return hash('sha256', implode('|', [$scope['withholding_agent_tin'], $scope['withholding_agent_branch_code'],
            $scope['report_type'], $scope['reporting_month'], $scope['percentage'], $item['reference']]));
    }

    private function duplicateIssues(array $scope, array $parsed): array
    {
        $identities = array_map(fn ($item) => $this->identity($scope, $item), $parsed['items']);

        return DB::table('expanded_dm_references')->whereIn('identity', $identities)->pluck('reference')
            ->map(fn ($reference) => "{$reference} was already applied for this company, month and rate. No additional deduction will be saved.")->all();
    }

    private function success(string $total)
    {
        return response()->json(['message' => 'DM deduction of PHP '.number_format((float) $total, 2).' saved. Review the updated Expanded totals.']);
    }

    private function ensureActive(object $application): void
    {
        if ($application->superseded_at !== null) {
            throw ValidationException::withMessages(['token' => 'This DM application was superseded by a regular re-upload. Upload the DM file again to review the new records.']);
        }
    }

    private function completedResponse(object $application)
    {
        // Serialize completed-token retries with replacement as well.
        return DB::transaction(function () use ($application) {
            app(ExpandedWtaxDmReferenceResetService::class)->lock(json_decode($application->scope, true));
            $current = DB::table('expanded_dm_applications')->where('id', $application->id)->first();
            $this->ensureActive($current);

            return $this->success($current->total_deduction);
        });
    }
}
