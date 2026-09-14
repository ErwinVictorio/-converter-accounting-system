<?php

namespace App\Services;

use App\Imports\UploadBirInfoPreflight;
use App\Models\PendingPurchaseUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PendingPurchaseUploadService
{
    public function create(
        User $user,
        UploadedFile $file,
        string $reportingPeriod,
        array $issues,
        ?string $retainedContents = null
    ): PendingPurchaseUpload {
        $token = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, ['xls', 'xlsx', 'csv'], true) ? $extension : 'xlsx';
        $storedPath = "pending-purchase-uploads/{$token}.{$extension}";
        $contents = $retainedContents ?? file_get_contents($file->getRealPath());

        if ($contents === false || ! Storage::disk('local')->put($storedPath, $contents)) {
            throw new \RuntimeException('Unable to retain the rejected workbook for retry.');
        }

        $queue = $this->queueFromIssues($issues, $token);

        try {
            return PendingPurchaseUpload::create([
                'token' => $token,
                'user_id' => $user->id,
                'original_name' => basename($file->getClientOriginalName()),
                'stored_path' => $storedPath,
                'mime_type' => $file->getClientMimeType(),
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'reporting_period' => $reportingPeriod,
                'status' => PendingPurchaseUpload::STATUS_PENDING,
                'issues' => $issues,
                'initial_supplier_count' => count($queue['items']),
                'initial_row_count' => $queue['affected_rows'],
                'expires_at' => now()->addHours((int) config('bir.pending_purchase_upload_hours', 24)),
            ]);
        } catch (\Throwable $throwable) {
            Storage::disk('local')->delete($storedPath);
            throw $throwable;
        }
    }

    public function authorize(PendingPurchaseUpload $pending, User $user): void
    {
        abort_unless((int) $pending->user_id === (int) $user->id, 404);
        $this->expireIfNeeded($pending);

        abort_unless($pending->status === PendingPurchaseUpload::STATUS_PENDING, 410);
    }

    /**
     * Re-read the retained workbook so queue progress always reflects current Supplier data.
     */
    public function refresh(PendingPurchaseUpload $pending): array
    {
        $path = $this->verifiedPath($pending);
        $issues = (new UploadBirInfoPreflight)->checkPurchase(
            $path,
            $pending->reporting_period->toDateString()
        );

        $pending->forceFill(['issues' => $issues])->save();

        return $this->queue($pending, $issues);
    }

    public function queue(PendingPurchaseUpload $pending, ?array $issues = null): array
    {
        $grouped = $this->queueFromIssues($issues ?? $pending->issues ?? [], $pending->token);

        return [
            'token' => $pending->token,
            'original_name' => $pending->original_name,
            'reporting_period' => $pending->reporting_period->format('Y-m'),
            'expires_at' => $pending->expires_at?->toIso8601String(),
            'initial_supplier_count' => $pending->initial_supplier_count,
            'initial_row_count' => $pending->initial_row_count,
            'affected_suppliers' => count($grouped['items']),
            'affected_rows' => $grouped['affected_rows'],
            'remaining_suppliers' => count($grouped['items']),
            'resolved_suppliers' => max(0, $pending->initial_supplier_count - count($grouped['items'])),
            'ready_to_retry' => $grouped['items'] === [],
            'items' => $grouped['items'],
            'retry_url' => route('pending-purchase-uploads.retry', $pending),
            'cancel_url' => route('pending-purchase-uploads.destroy', $pending),
        ];
    }

    public function verifiedPath(PendingPurchaseUpload $pending): string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($pending->stored_path)) {
            throw ValidationException::withMessages([
                'pending_upload' => 'The retained workbook is no longer available. Upload the file again.',
            ]);
        }

        $path = $disk->path($pending->stored_path);

        if (! hash_equals($pending->sha256, hash_file('sha256', $path))) {
            throw ValidationException::withMessages([
                'pending_upload' => 'The retained workbook failed its integrity check. Upload the file again.',
            ]);
        }

        return $path;
    }

    public function cancel(PendingPurchaseUpload $pending): void
    {
        Storage::disk('local')->delete($pending->stored_path);
        $pending->forceFill([
            'status' => PendingPurchaseUpload::STATUS_CANCELLED,
            'issues' => null,
        ])->save();
    }

    public function complete(PendingPurchaseUpload $pending): void
    {
        Storage::disk('local')->delete($pending->stored_path);
        $pending->forceFill([
            'status' => PendingPurchaseUpload::STATUS_COMPLETED,
            'issues' => null,
        ])->save();
    }

    public function cleanupExpired(): int
    {
        $count = 0;

        PendingPurchaseUpload::query()
            ->whereIn('status', [PendingPurchaseUpload::STATUS_PENDING, PendingPurchaseUpload::STATUS_RETRYING])
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($pendingUploads) use (&$count) {
                foreach ($pendingUploads as $pending) {
                    Storage::disk('local')->delete($pending->stored_path);
                    $pending->forceFill([
                        'status' => PendingPurchaseUpload::STATUS_EXPIRED,
                        'issues' => null,
                    ])->save();
                    $count++;
                }
            });

        return $count;
    }

    private function expireIfNeeded(PendingPurchaseUpload $pending): void
    {
        if ($pending->expires_at?->isFuture()) {
            return;
        }

        if (in_array($pending->status, [PendingPurchaseUpload::STATUS_PENDING, PendingPurchaseUpload::STATUS_RETRYING], true)) {
            Storage::disk('local')->delete($pending->stored_path);
            $pending->forceFill([
                'status' => PendingPurchaseUpload::STATUS_EXPIRED,
                'issues' => null,
            ])->save();
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, affected_rows: int}
     */
    private function queueFromIssues(array $issues, string $token): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            $key = (string) ($issue['identity_key'] ?? 'row:'.($issue['row'] ?? 'unknown'));

            if (! isset($groups[$key])) {
                $supplierId = $issue['supplier_id'] ?? null;
                $groups[$key] = [
                    'queue_key' => $key,
                    'supplier_id' => $supplierId,
                    'mode' => $supplierId ? 'edit' : 'create',
                    'display_name' => $issue['name'] ?: 'Unnamed supplier',
                    'affected_rows' => [],
                    'missing_fields' => [],
                    'problems' => [],
                    'prefill' => $issue['prefill'] ?? ['tin' => '', 'name' => '', 'addr' => '', 'city' => ''],
                ];
            }

            $groups[$key]['affected_rows'][] = (int) ($issue['row'] ?? 0);
            $groups[$key]['missing_fields'][] = (string) ($issue['field'] ?? 'bir_info');
            $groups[$key]['problems'][] = (string) ($issue['problem'] ?? 'Supplier BIR information is incomplete.');
        }

        foreach ($groups as &$group) {
            $group['affected_rows'] = array_values(array_unique(array_filter($group['affected_rows'])));
            sort($group['affected_rows']);
            $group['missing_fields'] = array_values(array_unique($group['missing_fields']));
            $group['problems'] = array_values(array_unique($group['problems']));
            $group['fix_url'] = route('suppliers.index', [
                'pending_upload' => $token,
                'queue_item' => $group['queue_key'],
            ]);
        }
        unset($group);

        $items = array_values($groups);
        $rows = [];

        foreach ($items as $item) {
            $rows = [...$rows, ...$item['affected_rows']];
        }

        return ['items' => $items, 'affected_rows' => count(array_unique(array_filter($rows)))];
    }
}
