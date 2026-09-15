<?php

namespace App\Http\Controllers;

use App\Models\PendingSalesUpload;
use App\Services\PendingSalesUploadService;
use App\Services\SalesUploadService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PendingSalesUploadController extends Controller
{
    public function __construct(private PendingSalesUploadService $pendingUploads, private SalesUploadService $salesUploads) {}

    public function show(Request $request, PendingSalesUpload $pendingSalesUpload)
    {
        $this->pendingUploads->authorize($pendingSalesUpload, $request->user());

        return response()->json($this->pendingUploads->refresh($pendingSalesUpload));
    }

    public function refresh(Request $request, PendingSalesUpload $pendingSalesUpload)
    {
        $this->pendingUploads->authorize($pendingSalesUpload, $request->user());

        return response()->json($this->pendingUploads->refresh($pendingSalesUpload));
    }

    public function retry(Request $request, PendingSalesUpload $pendingSalesUpload)
    {
        $this->pendingUploads->authorize($pendingSalesUpload, $request->user());
        $claimed = PendingSalesUpload::query()->whereKey($pendingSalesUpload->id)
            ->where('status', PendingSalesUpload::STATUS_PENDING)
            ->update(['status' => PendingSalesUpload::STATUS_RETRYING]);
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['pending_upload' => 'This pending Sales upload is already being retried or is no longer available.']);
        }
        $pendingSalesUpload->refresh();

        try {
            $path = $this->pendingUploads->verifiedPath($pendingSalesUpload);
            $preflight = $this->salesUploads->preflight($path, $pendingSalesUpload->reporting_period->toDateString());
            if ($preflight['type_issues'] !== []) {
                $pendingSalesUpload->forceFill(['status' => PendingSalesUpload::STATUS_PENDING])->save();

                return back()->with('error', implode(' ', $preflight['type_issues']));
            }
            if ($preflight['bir_issues'] !== []) {
                $pendingSalesUpload->forceFill(['status' => PendingSalesUpload::STATUS_PENDING, 'issues' => $preflight['bir_issues']])->save();

                return back()->with('error', $preflight['workbook_issues'] !== []
                    ? 'Sales workbook amounts still need correction. Correct the workbook and upload it again.'
                    : 'Sales upload still has Customer BIR information that must be fixed.')
                    ->with('uploadIssueDialog', $this->issueDialog($pendingSalesUpload, $this->pendingUploads->queue($pendingSalesUpload, $preflight['bir_issues'])));
            }

            $result = $this->salesUploads->replace($path, $pendingSalesUpload->reporting_period->toDateString());
            $this->pendingUploads->complete($pendingSalesUpload);
            $response = redirect()->route('records.sales.index')->with('success', 'Sales VAT report for '.$pendingSalesUpload->reporting_period->format('F Y').' was replaced successfully.');
            if ($result['skipped_debit_memo_count'] > 0) {
                $response->with('warning', 'Sales upload completed, but '.$result['skipped_debit_memo_count'].' DM row(s) were skipped because Debit Memo rows are not included in Sales VAT upload.');
            }

            return $response;
        } catch (\Throwable $throwable) {
            if ($pendingSalesUpload->fresh()?->status === PendingSalesUpload::STATUS_RETRYING) {
                $pendingSalesUpload->forceFill(['status' => PendingSalesUpload::STATUS_PENDING])->save();
            }
            throw $throwable;
        }
    }

    public function destroy(Request $request, PendingSalesUpload $pendingSalesUpload)
    {
        $this->pendingUploads->authorize($pendingSalesUpload, $request->user());
        $this->pendingUploads->cancel($pendingSalesUpload);

        return redirect()->route('records.sales.index')->with('success', 'Pending Sales upload cancelled.');
    }

    private function issueDialog(PendingSalesUpload $pending, array $queue): array
    {
        return ['title' => 'Sales upload needs Customer fixes', 'message' => 'Fix Customer BIR info before retrying this file.',
            'summary' => count($pending->issues ?? []).' issue(s) found. No records were imported or replaced.',
            'record_type' => 'sales', 'issues' => $pending->issues ?? [], 'pending_upload' => $queue];
    }
}
