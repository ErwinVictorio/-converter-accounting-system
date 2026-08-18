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
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', 'AND', $value);

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }
}
