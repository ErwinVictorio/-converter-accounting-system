<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesVatInput extends Model
{
    protected $table = 'sales_vatsinputs';

    protected $fillable = [
        'document_no',
        'document_date',
        'terms',
        'days',
        'due_date',
        'agent_name',
        'customer_name',
        'document_refs',
        'gross_amount',
        'discount',
        'charges',
        'net_amount',
        'output_vat',
        'taxable_net_of_vat',
        'customer_tin',
        'customer_type',
        'company_name',
        'last_name',
        'first_name',
        'middle_name',
        'address1',
        'address2',
        'exempt_sales',
        'zero_rated_sales',
        'reporting_period',
        'is_adjusted',
    ];

    protected $casts = [
        'document_date' => 'date:m/d/Y',
        'due_date' => 'date:m/d/Y',
        'gross_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'charges' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'output_vat' => 'decimal:2',
        'taxable_net_of_vat' => 'decimal:2',
        'exempt_sales' => 'decimal:2',
        'zero_rated_sales' => 'decimal:2',
        'reporting_period' => 'date:m/d/Y',
        'is_adjusted' => 'boolean',
    ];

    public function toBirSalesRow(): array
    {
        return [
            'customer_type' => $this->customer_type,
            'customer_tin' => $this->customer_tin,
            'company_name' => $this->customer_type === 'individual'
                ? ''
                : ($this->company_name ?: $this->customer_name),
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'exempt_sales' => $this->exempt_sales,
            'zero_rated_sales' => $this->zero_rated_sales,
            'taxable_sales' => $this->taxable_net_of_vat,
            'output_vat' => $this->output_vat,
        ];
    }
}
