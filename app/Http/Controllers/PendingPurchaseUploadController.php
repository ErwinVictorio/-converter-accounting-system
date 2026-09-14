<?php

namespace App\Http\Controllers;

use App\Models\PendingPurchaseUpload;
use App\Services\PendingPurchaseUploadService;
use App\Services\PurchaseUploadService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PendingPurchaseUploadController extends Controller
{
    public function __construct(
        private PendingPurchaseUploadService $pendingUploads,
        private PurchaseUploadService $purchaseUploads
    ) {}

    public function show(Request $request, PendingPurchaseUpload $pendingPurchaseUpload)
    {
        $this->pendingUploads->authorize($pendingPurchaseUpload, $request->user());

        return response()->json($this->pendingUploads->refresh($pendingPurchaseUpload));
    }

    public function refresh(Request $request, PendingPurchaseUpload $pendingPurchaseUpload)
    {
        $this->pendingUploads->authorize($pendingPurchaseUpload, $request->user());

        return response()->json($this->pendingUploads->refresh($pendingPurchaseUpload));
    }

    public function retry(Request $request, PendingPurchaseUpload $pendingPurchaseUpload)
    {
        $this->pendingUploads->authorize($pendingPurchaseUpload, $request->user());

        $claimed = PendingPurchaseUpload::query()
            ->whereKey($pendingPurchaseUpload->id)
            ->where('status', PendingPurchaseUpload::STATUS_PENDING)
            ->update(['status' => PendingPurchaseUpload::STATUS_RETRYING]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'pending_upload' => 'This pending upload is already being retried or is no longer available.',
            ]);
        }

        $pendingPurchaseUpload->refresh();

        try {
            $path = $this->pendingUploads->verifiedPath($pendingPurchaseUpload);
            $preflight = $this->purchaseUploads->preflight(
                $path,
                $pendingPurchaseUpload->reporting_period->toDateString()
            );

            if ($preflight['type_issues'] !== []) {
                $pendingPurchaseUpload->forceFill(['status' => PendingPurchaseUpload::STATUS_PENDING])->save();

                return back()->with('error', implode(' ', $preflight['type_issues']));
            }

            if ($preflight['bir_issues'] !== []) {
                $pendingPurchaseUpload->forceFill([
                    'status' => PendingPurchaseUpload::STATUS_PENDING,
                    'issues' => $preflight['bir_issues'],
                ])->save();

                return back()
                    ->with('error', 'Purchase upload still has supplier BIR information that must be fixed.')
                    ->with('uploadIssueDialog', $this->issueDialog(
                        $pendingPurchaseUpload,
                        $this->pendingUploads->queue($pendingPurchaseUpload, $preflight['bir_issues'])
                    ));
            }

            $result = $this->purchaseUploads->replace(
                $path,
                $pendingPurchaseUpload->reporting_period->toDateString()
            );
            $this->pendingUploads->complete($pendingPurchaseUpload);

            $response = redirect()
                ->route('records.purchases.index')
                ->with(
                    'success',
                    'Purchase VAT report for '.$pendingPurchaseUpload->reporting_period->format('F Y').' was replaced successfully.'
                );

            if ($result['skipped_supplier_count'] > 0) {
                $response->with(
                    'warning',
                    'Purchase upload completed, but '.$result['skipped_supplier_count'].' BUREAU OF CUSTOMS row(s) were skipped because they are not included in RELIEF Purchase DAT.'
                );
            }

            return $response;
        } catch (\Throwable $throwable) {
            if ($pendingPurchaseUpload->fresh()?->status === PendingPurchaseUpload::STATUS_RETRYING) {
                $pendingPurchaseUpload->forceFill(['status' => PendingPurchaseUpload::STATUS_PENDING])->save();
            }

            throw $throwable;
        }
    }

    public function destroy(Request $request, PendingPurchaseUpload $pendingPurchaseUpload)
    {
        $this->pendingUploads->authorize($pendingPurchaseUpload, $request->user());
        $this->pendingUploads->cancel($pendingPurchaseUpload);

        return redirect()->route('records.purchases.index')->with('success', 'Pending Purchase upload cancelled.');
    }

    private function issueDialog(PendingPurchaseUpload $pending, array $queue): array
    {
        return [
            'title' => 'Purchase upload needs BIR info fixes',
            'message' => 'Fix supplier BIR info before retrying this file.',
            'summary' => count($pending->issues ?? []).' issue(s) found. No records were imported or replaced.',
            'record_type' => 'purchase',
            'issues' => $pending->issues ?? [],
            'pending_upload' => $queue,
        ];
    }
}
