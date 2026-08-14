<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VatInput extends Model
{

  protected $table = 'vat_inputs';


  protected $fillable = [
    'supplier_name',
    'tin_number',
    'is_imported',
    'purchase_imported',
    'purchase_local',
    'services',
    'others',
    'total',
    'date_uploaded',
    'is_broker'
  ];

  protected $casts = [
    'date_uploaded' => 'date:m/d/Y'
  ];
}
