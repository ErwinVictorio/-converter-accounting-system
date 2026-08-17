<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'city',
        'tin',
        'addr',
    ];

}
