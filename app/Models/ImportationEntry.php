<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportationEntry extends Model
{
    protected $table = 'importation_entries';

    protected $fillable = [
        'sequence_number',
        'tax_month',
        'import_entry_no',
        'assessment_date',
        'supplier',
        'importation_date',
        'country',
        'total_landed_cost',
        'dutiable_value',
        'charges',
        'exempt',
        'taxable_goods',
        'vat_rate',
        'vat_payable',
        'or_number',
        'payment_date',
        'vat_input_id',
    ];

    protected $casts = [
        'sequence_number' => 'integer',
        'tax_month' => 'date:Y-m-d',
        'assessment_date' => 'date:Y-m-d',
        'importation_date' => 'date:Y-m-d',
        'payment_date' => 'date:Y-m-d',
        'total_landed_cost' => 'decimal:2',
        'dutiable_value' => 'decimal:2',
        'charges' => 'decimal:2',
        'exempt' => 'decimal:2',
        'taxable_goods' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_payable' => 'decimal:2',
        'vat_input_id' => 'integer',
    ];

    public function vatInput(): BelongsTo
    {
        return $this->belongsTo(VatInput::class, 'vat_input_id');
    }

    /**
     * The 12 detail fields of a RELIEF Importation ("I") DAT line, plus vat_rate
     * which the header carries once for the whole month.
     *
     * total_landed_cost is absent on purpose: the entry form collects it and
     * derives charges + taxable_goods from it, but BIR's layout has no field for
     * it. Adding it here would change the detail field count and break the file.
     */
    public function toBirImportationRow(): array
    {
        return [
            'import_entry_no' => $this->import_entry_no,
            'assessment_date' => $this->assessment_date,
            'supplier' => $this->supplier,
            'importation_date' => $this->importation_date,
            'country' => $this->country,
            'dutiable_value' => $this->dutiable_value,
            'charges' => $this->charges,
            'exempt' => $this->exempt,
            'taxable_goods' => $this->taxable_goods,
            'vat_rate' => $this->vat_rate,
            'vat_payable' => $this->vat_payable,
            'or_number' => $this->or_number,
            'payment_date' => $this->payment_date,
        ];
    }
}
