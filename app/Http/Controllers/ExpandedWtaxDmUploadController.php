<?php

namespace App\Http\Controllers;

use App\Imports\ExpandedWtaxDmReader;
use App\Services\ExpandedWtaxDmAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $records = $service->query($scope)->get();
        $review = $service->review($parsed, $records);
        $token = null;
        if (! $review['issues']) {
            $token = (string) Str::uuid();
            Cache::put('expanded-dm:'.$token, ['user_id' => $request->user()->getAuthIdentifier(), 'scope' => $scope, 'parsed' => $parsed,
                'snapshot' => $service->snapshot($records), 'digest' => hash_file('sha256', $request->file('excel_file')->getRealPath())], now()->addMinutes(20));
        }
        unset($review['changes']);

        return response()->json($review + ['token' => $token, 'scope' => $scope, 'apply_available' => false]);
    }

    public function apply(Request $request)
    {
        // Keep previews read-only until the persistence/duplicate-reference policy is settled.
        throw ValidationException::withMessages(['token' => 'DM adjustments are currently preview-only. No records have been changed.']);
    }
}
