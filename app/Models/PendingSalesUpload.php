<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingSalesUpload extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'token', 'user_id', 'original_name', 'stored_path', 'mime_type', 'size', 'sha256',
        'reporting_period', 'status', 'issues', 'initial_customer_count', 'initial_row_count', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['issues' => 'array', 'reporting_period' => 'date', 'expires_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
