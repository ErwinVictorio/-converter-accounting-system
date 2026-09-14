<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseAdjustment extends Model
{
    protected $fillable = [
        'source_vat_input_id',
        'target_vat_input_id',
        'purchase_imported',
        'purchase_local',
        'services',
        'others',
    ];

    protected $casts = [
        'purchase_imported' => 'decimal:2',
        'purchase_local' => 'decimal:2',
        'services' => 'decimal:2',
        'others' => 'decimal:2',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(VatInput::class, 'source_vat_input_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(VatInput::class, 'target_vat_input_id');
    }
}
