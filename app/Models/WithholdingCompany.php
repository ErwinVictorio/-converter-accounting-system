<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One withholding agent company the Expanded WTAX module can file for.
 *
 * This is the company that files the 1601EQ return -- never a payee. Its TIN,
 * branch code, registered name and RDO code are what reach the DAT header and
 * control record, so all four are normalised on the way in rather than on the way
 * out: the generator only truncates, and a stored value with dashes in it would
 * silently lose its last digits.
 *
 *   tin         9 digits, dashes and spaces removed
 *   branch_code 4 digits, left-padded ('1' -> '0001'), blank -> '0000'
 *   rdo_code    3 digits, or null when not stated
 *
 * tin + branch_code is the identity, unique in the table, and the same pair
 * expanded_wtax_entries stores on every uploaded row. Deactivating is preferred
 * over deleting because those rows are not linked by foreign key: an inactive
 * company disappears from the upload dropdown but a month already filed under it
 * can still be regenerated.
 */
class WithholdingCompany extends Model
{
    protected $table = 'withholding_companies';

    protected $fillable = [
        'tin',
        'branch_code',
        'registered_name',
        'trade_name',
        'rdo_code',
        'address1',
        'address2',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['label'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * What the dropdowns show: FORTRESS STEEL INC. (008791976-0000).
     */
    public function getLabelAttribute(): string
    {
        return trim($this->registered_name . ' (' . $this->tin . '-' . $this->branch_code . ')');
    }

    /**
     * The dropdown shape. 'name' is kept alongside 'registered_name' because the
     * upload and generate screens have always read 'name', and the uploaded-rows
     * fallback can only supply that one.
     */
    public function toDirectoryEntry(): array
    {
        return [
            'tin' => (string) $this->tin,
            'branch_code' => (string) $this->branch_code,
            'name' => (string) $this->registered_name,
            'registered_name' => (string) $this->registered_name,
            'trade_name' => (string) ($this->trade_name ?? ''),
            'rdo_code' => (string) ($this->rdo_code ?? ''),
            'address1' => (string) ($this->address1 ?? ''),
            'address2' => (string) ($this->address2 ?? ''),
            'is_active' => (bool) $this->is_active,
            'source' => 'managed',
        ];
    }

    public function setTinAttribute($value): void
    {
        $this->attributes['tin'] = static::normaliseTin($value);
    }

    public function setBranchCodeAttribute($value): void
    {
        $this->attributes['branch_code'] = static::normaliseBranchCode($value);
    }

    public function setRdoCodeAttribute($value): void
    {
        $rdo = substr(preg_replace('/\D/', '', (string) $value), 0, 3);

        $this->attributes['rdo_code'] = $rdo === '' ? null : $rdo;
    }

    public function setRegisteredNameAttribute($value): void
    {
        $this->attributes['registered_name'] = trim((string) $value);
    }

    public static function normaliseTin(?string $value): string
    {
        return substr(preg_replace('/\D/', '', (string) $value), 0, 9);
    }

    public static function normaliseBranchCode(?string $value): string
    {
        $digits = substr(preg_replace('/\D/', '', (string) $value), 0, 4);

        return $digits === '' ? '0000' : str_pad($digits, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Whether any Expanded WTAX row has already been filed under this identity.
     * Editing the TIN or branch of such a company would strand those rows, so the
     * controller refuses it and asks for a new company record instead.
     */
    public function hasFiledRows(): bool
    {
        return ExpandedWtaxEntry::query()
            ->where('withholding_agent_tin', $this->tin)
            ->where('withholding_agent_branch_code', $this->branch_code)
            ->exists();
    }
}
