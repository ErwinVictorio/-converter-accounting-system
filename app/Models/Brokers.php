<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brokers extends Model
{
    protected $fillable = [
        'broker_name',
        'tin_number'
    ];
}
