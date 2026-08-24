<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One expanded withholding tax line for the 1604E schedule.
 *
 * The BIR-facing shape is assembled here rather than in the generator so the
 * validator and the generator agree on which fields exist, exactly as
 * VatInput::toBirPurchaseRow() and ImportationEntry::toBirImportationRow() do.
 */
class ExpandedWtaxEntry extends Model
{
    protected $table = 'expanded_wtax_entries';

    protected $fillable = [
        'reporting_period',
        'transaction_date',
        'source_no',
        'reference_no',
        'payee_name',
        'payee_type',
        'payee_tin',
        'payee_branch_code',
        'company_name',
        'last_name',
        'first_name',
        'middle_name',
        'atc_code',
        'tax_rate',
        'income_payment',
        'tax_withheld',
        'source_row',
    ];

    protected $casts = [
        'reporting_period' => 'date:Y-m-d',
        'transaction_date' => 'date:Y-m-d',
        'tax_rate' => 'decimal:2',
        'income_payment' => 'decimal:2',
        'tax_withheld' => 'decimal:2',
        'source_row' => 'integer',
    ];

    /**
     * payee_name and payee_type are not written to the DAT, but the validator
     * needs the type to decide which name fields are required, and the row
     * errors read better when they can name the payee.
     */
    public function toBirExpandedRow(): array
    {
        return [
            'payee_name' => $this->payee_name,
            'payee_type' => $this->payee_type,
            'payee_tin' => (string) $this->payee_tin,
            'payee_branch_code' => (string) $this->payee_branch_code,
            'company_name' => $this->company_name,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'atc_code' => $this->atc_code,
            'income_payment' => $this->income_payment,
            'tax_rate' => $this->tax_rate,
            'tax_withheld' => $this->tax_withheld,
        ];
    }
}
