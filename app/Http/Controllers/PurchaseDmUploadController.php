<?php

namespace App\Http\Controllers;

use App\Imports\PurchaseDmReader;
use App\Services\BIR\PurchaseCompanyDirectory;
use App\Services\PurchaseDmAdjustmentService;
use App\Services\PurchaseDmReferenceResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PurchaseDmUploadController extends Controller
{
    public function review(Request $request, PurchaseDmReader $reader, PurchaseDmAdjustmentService $service, PurchaseDmReferenceResetService $reset)
    {
        $scope = $request->validate(['excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'reporting_month' => ['required', 'date_format:Y-m'], 'purchase_type' => ['required', 'in:local,imported,services,others'],
            'filing_company_tin' => ['required', 'string'], 'filing_company_branch_code' => ['required', 'string']]);
        unset($scope['excel_file']);
        $company = app(PurchaseCompanyDirectory::class)->resolve($scope['filing_company_tin'], $scope['filing_company_branch_code']);
        $scope['filing_company_tin'] = $company['tin'];
        $scope['filing_company_branch_code'] = $company['branch_code'];
        try {
            $parsed = $reader->read($request->file('excel_file')->getRealPath(), $scope['reporting_month'], $scope['purchase_type']);
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['excel_file' => 'Unable to read this workbook. Use an unencrypted ACCOUNT DETAILS Excel file.']);
        }
        $available = $reset->available();

        // Generation and balances are captured under the same replacement lock.
        return DB::transaction(function () use ($scope, $parsed, $available, $service, $reset, $request) {
            $generation = $reset->lock($scope);
            $records = $service->query($scope)->get();
            $review = $service->review($scope, $parsed, $records, $available);
            $token = null;
            if ($available && $review['summary']['eligible_count'] > 0) {
                $token = (string) Str::uuid();
                Cache::put('purchase-dm:'.$token, ['user_id' => $request->user()->getAuthIdentifier(), 'scope' => $scope, 'parsed' => $parsed,
                    'generation' => $generation, 'version' => PurchaseDmAdjustmentService::VERSION, 'fingerprint' => $review['fingerprint'],
                    'snapshot' => $service->snapshot($records), 'digest' => hash_file('sha256', $request->file('excel_file')->getRealPath()),
                    'filename' => $request->file('excel_file')->getClientOriginalName()], now()->addMinutes(20));
            }
            unset($review['changes'], $review['fingerprint']);

            return response()->json($review + ['token' => $token, 'scope' => $scope, 'apply_available' => $available]);
        });
    }

    public function apply(Request $request, PurchaseDmAdjustmentService $service, PurchaseDmReferenceResetService $reset)
    {
        $request->validate(['token' => ['required', 'uuid'], 'review_version' => ['required', 'integer', 'in:1'],
            'acknowledge_skipped' => ['sometimes', 'boolean'], 'acknowledge_warnings' => ['sometimes', 'boolean']]);
        if (! $reset->available()) {
            $this->fail('Purchase DM setup is unavailable.');
        }
        $token = (string) $request->input('token');
        $completed = DB::table('purchase_dm_applications')->where('token', $token)->first();
        if ($completed) {
            abort_unless((string) $completed->user_id === (string) $request->user()->getAuthIdentifier(), 403);

            return DB::transaction(function () use ($completed, $reset) {
                $reset->lock(json_decode($completed->scope, true));

                return $this->completed(DB::table('purchase_dm_applications')->find($completed->id));
            });
        }
        $pending = Cache::get('purchase-dm:'.$token);
        if (! $pending || $pending['version'] !== PurchaseDmAdjustmentService::VERSION) {
            $this->fail('This review expired. Upload again to review the latest balances.');
        }
        abort_unless((string) $pending['user_id'] === (string) $request->user()->getAuthIdentifier(), 403);
        $response = DB::transaction(function () use ($pending, $token, $request, $service, $reset) {
            $generation = $reset->lock($pending['scope']);
            if ($done = DB::table('purchase_dm_applications')->where('token', $token)->first()) {
                return $this->completed($done);
            }
            app(PurchaseCompanyDirectory::class)->resolve($pending['scope']['filing_company_tin'], $pending['scope']['filing_company_branch_code']);
            if ($generation !== $pending['generation']) {
                $this->fail('Purchase records were replaced. Upload the DM file again.');
            }
            $records = $service->query($pending['scope'])->lockForUpdate()->get();
            if (! hash_equals($pending['snapshot'], $service->snapshot($records))) {
                $this->fail('Purchase records changed after review. Upload again to review current totals.');
            }
            $review = $service->review($pending['scope'], $pending['parsed'], $records);
            if (! hash_equals($pending['fingerprint'], $review['fingerprint']) || $review['summary']['eligible_count'] === 0) {
                $this->fail('DM eligibility changed. Review the file again.');
            }
            if ($review['summary']['skipped_count'] && ! $request->boolean('acknowledge_skipped')) {
                $this->fail('Acknowledge skipped DM entries before applying eligible DM only.');
            }
            if ($review['warnings'] && ! $request->boolean('acknowledge_warnings')) {
                $this->fail('Acknowledge the negative-balance and validation warnings before confirming.');
            }
            $review['acknowledgments'] = ['skipped' => $request->boolean('acknowledge_skipped'), 'warnings' => $request->boolean('acknowledge_warnings')];
            $id = DB::table('purchase_dm_applications')->insertGetId(['token' => $token, 'user_id' => $pending['user_id'], 'file_digest' => $pending['digest'],
                'filename' => $pending['filename'], 'scope' => json_encode($pending['scope']), 'details' => json_encode($review), 'total_deduction' => $review['total_deduction'], 'created_at' => now()]);
            foreach ($review['entries'] as $item) {
                if ($item['status'] === 'ready') {
                    DB::table('purchase_dm_references')->insert(['application_id' => $id, 'identity' => $item['key'], 'reference' => $item['reference'], 'payload_hash' => $item['payload_hash']]);
                }
            }
            foreach ($review['changes'] as $change) {
                DB::table('purchase_dm_sources')->insert(['application_id' => $id, 'vat_input_id' => $change['id'], 'before' => json_encode($change['before']), 'after' => json_encode($change['after'])]);
                $records->firstWhere('id', $change['id'])->update($change['after']);
            }

            return $this->success($review);
        }, 3);
        Cache::forget('purchase-dm:'.$token);

        return $response;
    }

    private function completed(object $application)
    {
        if ($application->superseded_at !== null) {
            $this->fail('This application was superseded by a regular re-upload. Review the DM file again.');
        }

        return $this->success(json_decode($application->details, true));
    }

    private function success(array $review)
    {
        return response()->json(['message' => 'Purchase DM deduction of PHP '.number_format((float) $review['total_deduction'], 2).' saved.', 'summary' => $review['summary'],
            'skipped_entries' => array_values(array_filter($review['entries'], fn ($e) => $e['status'] !== 'ready'))]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['token' => $message]);
    }
}
