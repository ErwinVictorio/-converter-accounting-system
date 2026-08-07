<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordEntry extends Model
{
    //
    protected $table = 'record_db';

    protected $fillable = [
        'resgister_name',
        'supplier_name',
        'supplier_address',
        'amount_of_gross_purchase',
        'exempt_purchase'
    ];
}
