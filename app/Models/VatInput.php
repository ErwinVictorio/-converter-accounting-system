<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VatInput extends Model
{

  protected $table = 'vat_inputs';


  protected $fillable = [
    'supplier_name',
    'tin_number',
    'vendor_type',
    'company_name',
    'last_name',
    'first_name',
    'middle_name',
    'address1',
    'address2',
    'is_imported',
    'exempt',
    'zero_rated',
    'purchase_imported',
    'purchase_local',
    'services',
    'capital_goods',
    'other_than_capital_goods',
    'taxable_net_of_vat',
    'vat_rate',
    'input_vat',
    'total_purchases',
    'others',
    'total',
    'date_uploaded',
    'is_broker',
    'is_adjusted'
  ];

  protected $casts = [
    'date_uploaded' => 'date:m/d/Y',
    'exempt' => 'decimal:2',
    'zero_rated' => 'decimal:2',
    'purchase_imported' => 'decimal:2',
    'purchase_local' => 'decimal:2',
    'services' => 'decimal:2',
    'capital_goods' => 'decimal:2',
    'other_than_capital_goods' => 'decimal:2',
    'taxable_net_of_vat' => 'decimal:2',
    'vat_rate' => 'decimal:2',
    'input_vat' => 'decimal:2',
    'total_purchases' => 'decimal:2',
    'others' => 'decimal:2',
    'total' => 'decimal:2',
    'is_broker' => 'boolean',
    'is_adjusted' => 'boolean',
  ];

  public function toBirPurchaseRow(): array
  {
    return [
      'vendor_type' => $this->vendor_type,
      'vendor_tin' => $this->tin_number,
      'company_name' => $this->vendor_type === 'individual'
        ? ''
        : ($this->company_name ?: $this->supplier_name),
      'last_name' => $this->last_name,
      'first_name' => $this->first_name,
      'middle_name' => $this->middle_name,
      'address1' => $this->address1,
      'address2' => $this->address2,
      'exempt' => $this->exempt,
      'zero_rated' => $this->zero_rated,
      'services' => $this->services,
      'capital_goods' => $this->capital_goods,
      'other_than_capital_goods' => $this->other_than_capital_goods,
      'input_vat' => $this->input_vat,
    ];
  }
}
