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

    public static function normalizeName(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', 'AND', $value);

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }
}
