<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'payee',
        'addr',
        'phone',
        'mobile',
        'email',
        'contact',
        'credit_limit',
        'credit_terms',
        'tin',
        'industry',
        'vattype',
        'exptax',
        'status',
    ];

    protected $attributes = [
        'credit_limit' => 0.00,
        'credit_terms' => 0,
        'industry'     => 0,
        'vattype'      => 1,
        'exptax'       => 0,
        'status'       => 1,
    ];


    protected $casts = [
        'credit_limit' => 'decimal:2',
        'credit_terms' => 'integer',
        'industry'     => 'integer',
        'vattype'      => 'integer',
        'exptax'       => 'integer',
        'status'       => 'integer',
    ];
}
