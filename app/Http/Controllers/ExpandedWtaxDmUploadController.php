<?php

namespace App\Http\Controllers;

use App\Imports\ExpandedWtaxDmReader;
use App\Services\ExpandedWtaxDmAdjustmentService;
use App\Services\ExpandedWtaxDmReferenceResetService;
use App\Services\ExpandedWtaxDmReviewService;
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
        $available = $this->available();
        $generation = $available ? app(ExpandedWtaxDmReferenceResetService::class)->generation($scope) : 0;
        $records = $service->query($scope)->get();
        $review = app(ExpandedWtaxDmReviewService::class)->review($scope, $parsed, $records, $available);
        $token = null;
        if ($available && ! $review['issues'] && $review['summary']['eligible_count'] > 0) {
            $token = (string) Str::uuid();
            Cache::put('expanded-dm:'.$token, ['user_id' => $request->user()->getAuthIdentifier(), 'scope' => $scope, 'parsed' => $parsed,
                'eligible_keys' => array_column(array_filter($review['entries'], fn ($item) => $item['status'] === 'ready'), 'key'),
                'generation' => $generation, 'calculation_version' => ExpandedWtaxDmReviewService::VERSION, 'fingerprint' => $review['fingerprint'], 'snapshot' => $service->snapshot($records), 'digest' => hash_file('sha256', $request->file('excel_file')->getRealPath())], now()->addMinutes(20));
        }
        unset($review['changes'], $review['fingerprint']);

        return response()->json($review + ['token' => $token, 'scope' => $scope, 'apply_available' => $available]);
    }

    public function apply(Request $request, ExpandedWtaxDmAdjustmentService $service)
    {
        $request->validate(['token' => ['required', 'uuid']]);
        if (! $this->available()) {
            throw ValidationException::withMessages(['token' => 'DM adjustments are currently preview-only.']);
        }
        $token = $request->string('token')->toString();
        $completed = DB::table('expanded_dm_applications')->where('token', $token)->first();
        if ($completed) {
            abort_unless((string) $completed->user_id === (string) $request->user()->getAuthIdentifier(), 403);

            return $this->completedResponse($completed);
        }
        $request->validate(['review_version' => ['required', 'integer', 'in:4']], ['review_version.*' => 'The DM review format changed. Refresh the page and review the file again.']);
        $pending = Cache::get('expanded-dm:'.$token);
        if (! $pending || ($pending['calculation_version'] ?? 1) !== ExpandedWtaxDmReviewService::VERSION) {
            throw ValidationException::withMessages(['token' => 'This review has expired. Upload the file again to review current totals.']);
        }
        abort_unless((string) $pending['user_id'] === (string) $request->user()->getAuthIdentifier(), 403);
        try {
            $outcome = DB::transaction(function () use ($pending, $service, $token, $request) {
                $generation = app(ExpandedWtaxDmReferenceResetService::class)->lock($pending['scope']);
                $completed = DB::table('expanded_dm_applications')->where('token', $token)->first();
                if ($completed) {
                    $this->ensureActive($completed);

                    return $this->outcome($completed);
                }
                if (! isset($pending['generation']) || $pending['generation'] !== $generation) {
                    throw ValidationException::withMessages(['token' => 'Expanded records were replaced. Upload the DM file again to review the new records.']);
                }
                $records = $service->query($pending['scope'])->lockForUpdate()->get();
                if ($service->snapshot($records) !== $pending['snapshot']) {
                    throw ValidationException::withMessages(['token' => 'Expanded records changed after review. Upload again to review the latest totals.']);
                }
                $review = app(ExpandedWtaxDmReviewService::class)->review($pending['scope'], $pending['parsed'], $records);
                if (($pending['eligible_keys'] ?? []) !== array_column(array_filter($review['entries'], fn ($item) => $item['status'] === 'ready'), 'key') || $review['issues'] || $review['summary']['eligible_count'] === 0 || ! hash_equals($pending['fingerprint'] ?? '', $review['fingerprint'])) {
                    throw ValidationException::withMessages(['token' => 'Records or DM status changed. Review the file again.']);
                }
                if ($review['summary']['skipped_count'] > 0 && ! $request->boolean('acknowledge_skipped')) {
                    throw ValidationException::withMessages(['token' => 'Acknowledge the skipped entries before applying eligible DM only.']);
                }
                $review['acknowledgments'] = ['skipped' => $request->boolean('acknowledge_skipped'), 'warnings' => $request->boolean('acknowledge_warnings')];
                // Confirmation acknowledges the concise warning shown with the review.
                if ($review['warnings'] && ! $request->boolean('acknowledge_warnings')) {
                    throw ValidationException::withMessages(['token' => 'Review the adjustment warnings before confirming this deduction.']);
                }
                $application = DB::table('expanded_dm_applications')->insertGetId([
                    'token' => $token, 'user_id' => $pending['user_id'], 'file_digest' => $pending['digest'],
                    'scope' => json_encode($pending['scope']), 'details' => json_encode($review),
                    'total_deduction' => $review['total_deduction'], 'created_at' => now(),
                ]);
                foreach (array_filter($review['entries'], fn ($item) => $item['status'] === 'ready') as $item) {
                    DB::table('expanded_dm_references')->insert(['application_id' => $application,
                        'identity' => $item['key'], 'reference' => $item['reference']]);
                }
                foreach ($review['changes'] as $change) {
                    $record = $records->firstWhere('id', $change['id']);
                    $record->tax_withheld = $change['after'];
                    $record->income_payment = $change['income_after'];
                    $record->save();
                }

                return ['total' => $review['total_deduction'], 'summary' => $review['summary'], 'skipped_entries' => array_values(array_filter($review['entries'], fn ($item) => $item['status'] !== 'ready'))];
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

        return $this->success($outcome);
    }

    private function available(): bool
    {
        return Schema::hasTable('expanded_dm_applications') && Schema::hasTable('expanded_dm_references')
            && Schema::hasTable('expanded_dm_scopes') && Schema::hasColumn('expanded_dm_applications', 'superseded_at');
    }

    private function outcome(object $application): array
    {
        $details = json_decode($application->details, true) ?? [];

        return ['total' => $application->total_deduction, 'summary' => $details['summary'] ?? null,
            'skipped_entries' => array_values(array_filter($details['entries'] ?? [], fn ($entry) => $entry['status'] !== 'ready'))];
    }

    private function success(array $outcome)
    {
        $summary = $outcome['summary'];
        $message = 'DM deduction of PHP '.number_format((float) $outcome['total'], 2).' saved.';
        if ($summary) {
            $message .= " {$summary['eligible_count']} DM entries applied; {$summary['skipped_count']} skipped. Skipped entries were not saved as deductions.";
        }

        return response()->json(['message' => $message.' Review the updated Expanded totals.', 'summary' => $summary, 'skipped_entries' => $outcome['skipped_entries']]);
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

            return $this->success($this->outcome($current));
        });
    }
}
