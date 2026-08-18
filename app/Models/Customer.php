<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'name',
        'name_key',
        'addr',
        'city',
        'tin',
    ];

    public static function normalizeName(?string $value): string
    {
        return preg_replace('/\s+/', '', strtoupper(trim((string) $value))) ?? '';
    }
}
