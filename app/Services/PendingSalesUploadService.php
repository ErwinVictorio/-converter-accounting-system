<?php

namespace App\Services;

use App\Models\PendingSalesUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PendingSalesUploadService
{
    public function __construct(private SalesUploadService $salesUploads) {}

    public function create(User $user, UploadedFile $file, string $reportingPeriod, array $issues, ?string $contents = null): PendingSalesUpload
    {
        if ($issues === [] || collect($issues)->contains(fn (array $issue) => ($issue['issue_class'] ?? null) !== 'customer')) {
            throw new \InvalidArgumentException('Only Customer-fixable Sales issues can create a pending upload.');
        }

        $token = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, ['xls', 'xlsx', 'csv'], true) ? $extension : 'xlsx';
        $contents ??= file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new \RuntimeException('Unable to read the rejected Sales workbook for retry.');
        }

        $sha256 = hash('sha256', $contents);
        $existing = PendingSalesUpload::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha256)
            ->whereDate('reporting_period', $reportingPeriod)
            ->where('status', PendingSalesUpload::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($existing && Storage::disk('local')->exists($existing->stored_path)) {
            $queue = $this->queueFromIssues($issues, $existing->token);
            $existing->forceFill([
                'issues' => $issues,
                'initial_customer_count' => count($queue['items']),
                'initial_row_count' => $queue['affected_rows'],
                'expires_at' => now()->addHours((int) config('bir.pending_sales_upload_hours', 24)),
            ])->save();

            return $existing;
        }

        $storedPath = "pending-sales-uploads/{$token}.{$extension}";

        if (! Storage::disk('local')->put($storedPath, $contents)) {
            throw new \RuntimeException('Unable to retain the rejected Sales workbook for retry.');
        }

        $queue = $this->queueFromIssues($issues, $token);

        try {
            return PendingSalesUpload::create([
                'token' => $token,
                'user_id' => $user->id,
                'original_name' => basename($file->getClientOriginalName()),
                'stored_path' => $storedPath,
                'mime_type' => $file->getClientMimeType(),
                'size' => strlen($contents),
                'sha256' => $sha256,
                'reporting_period' => $reportingPeriod,
                'status' => PendingSalesUpload::STATUS_PENDING,
                'issues' => $issues,
                'initial_customer_count' => count($queue['items']),
                'initial_row_count' => $queue['affected_rows'],
                'expires_at' => now()->addHours((int) config('bir.pending_sales_upload_hours', 24)),
            ]);
        } catch (\Throwable $throwable) {
            Storage::disk('local')->delete($storedPath);
            throw $throwable;
        }
    }

    public function authorize(PendingSalesUpload $pending, User $user): void
    {
        abort_unless((int) $pending->user_id === (int) $user->id, 404);
        $this->expireIfNeeded($pending);
        abort_unless($pending->status === PendingSalesUpload::STATUS_PENDING, 410);
    }

    public function refresh(PendingSalesUpload $pending): array
    {
        $preflight = $this->salesUploads->preflight($this->verifiedPath($pending), $pending->reporting_period->toDateString());
        $issues = $preflight['bir_issues'];
        $pending->forceFill(['issues' => $issues])->save();

        return $this->queue($pending, $issues);
    }

    public function queue(PendingSalesUpload $pending, ?array $issues = null): array
    {
        $issues ??= $pending->issues ?? [];
        $grouped = $this->queueFromIssues(array_values(array_filter(
            $issues,
            fn (array $issue) => ($issue['issue_class'] ?? null) === 'customer'
        )), $pending->token);
        $hasWorkbookIssues = collect($issues)->contains(fn (array $issue) => ($issue['issue_class'] ?? null) !== 'customer');

        return [
            'token' => $pending->token,
            'original_name' => $pending->original_name,
            'reporting_period' => $pending->reporting_period->format('Y-m'),
            'expires_at' => $pending->expires_at?->toIso8601String(),
            'initial_customer_count' => $pending->initial_customer_count,
            'initial_row_count' => $pending->initial_row_count,
            'affected_customers' => count($grouped['items']),
            'affected_rows' => $grouped['affected_rows'],
            'remaining_customers' => count($grouped['items']),
            'resolved_customers' => max(0, $pending->initial_customer_count - count($grouped['items'])),
            'has_workbook_issues' => $hasWorkbookIssues,
            'ready_to_retry' => $issues === [],
            'items' => $grouped['items'],
            'retry_url' => route('pending-sales-uploads.retry', $pending),
            'cancel_url' => route('pending-sales-uploads.destroy', $pending),
        ];
    }

    public function verifiedPath(PendingSalesUpload $pending): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($pending->stored_path)) {
            throw ValidationException::withMessages(['pending_upload' => 'The retained Sales workbook is no longer available. Upload the file again.']);
        }
        $path = $disk->path($pending->stored_path);
        if (! hash_equals($pending->sha256, hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['pending_upload' => 'The retained Sales workbook failed its integrity check. Upload the file again.']);
        }

        return $path;
    }

    public function cancel(PendingSalesUpload $pending): void
    {
        Storage::disk('local')->delete($pending->stored_path);
        $pending->forceFill(['status' => PendingSalesUpload::STATUS_CANCELLED, 'issues' => null])->save();
    }

    public function complete(PendingSalesUpload $pending): void
    {
        Storage::disk('local')->delete($pending->stored_path);
        $pending->forceFill(['status' => PendingSalesUpload::STATUS_COMPLETED, 'issues' => null])->save();
    }

    public function cleanupExpired(): int
    {
        $count = 0;
        PendingSalesUpload::query()
            ->whereIn('status', [PendingSalesUpload::STATUS_PENDING, PendingSalesUpload::STATUS_RETRYING])
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($uploads) use (&$count) {
                foreach ($uploads as $pending) {
                    Storage::disk('local')->delete($pending->stored_path);
                    $pending->forceFill(['status' => PendingSalesUpload::STATUS_EXPIRED, 'issues' => null])->save();
                    $count++;
                }
            });

        return $count;
    }

    private function expireIfNeeded(PendingSalesUpload $pending): void
    {
        if ($pending->expires_at?->isFuture()) {
            return;
        }
        if (in_array($pending->status, [PendingSalesUpload::STATUS_PENDING, PendingSalesUpload::STATUS_RETRYING], true)) {
            Storage::disk('local')->delete($pending->stored_path);
            $pending->forceFill(['status' => PendingSalesUpload::STATUS_EXPIRED, 'issues' => null])->save();
        }
    }

    private function queueFromIssues(array $issues, string $token): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $key = (string) ($issue['identity_key'] ?? 'row:'.($issue['row'] ?? 'unknown'));
            if (! isset($groups[$key])) {
                $customerId = $issue['customer_id'] ?? null;
                $groups[$key] = [
                    'queue_key' => $key,
                    'customer_id' => $customerId,
                    'mode' => $customerId ? 'edit' : 'create',
                    'display_name' => $issue['name'] ?: 'Unnamed customer',
                    'affected_rows' => [], 'missing_fields' => [], 'problems' => [],
                    'prefill' => $issue['prefill'] ?? ['tin' => '', 'name' => '', 'addr' => '', 'city' => ''],
                ];
            }
            $groups[$key]['affected_rows'][] = (int) ($issue['row'] ?? 0);
            $groups[$key]['missing_fields'][] = (string) ($issue['field'] ?? 'bir_info');
            $groups[$key]['problems'][] = (string) ($issue['problem'] ?? 'Customer BIR information is incomplete.');
        }
        foreach ($groups as &$group) {
            $group['affected_rows'] = array_values(array_unique(array_filter($group['affected_rows'])));
            sort($group['affected_rows']);
            $group['missing_fields'] = array_values(array_unique($group['missing_fields']));
            $group['problems'] = array_values(array_unique($group['problems']));
            $group['fix_url'] = route('customers.index', ['pending_sales_upload' => $token, 'queue_item' => $group['queue_key']]);
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
