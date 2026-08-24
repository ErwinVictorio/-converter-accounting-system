<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One expanded withholding tax line, as uploaded from the BIR Excel format.
 *
 * The columns mirror Docs/1601EQ_Schedule_1_template.xls one for one:
 *
 *   Reporting_Month -> reporting_period      ATC            -> atc_code
 *   Vendor_TIN      -> payee_tin             income_payment -> income_payment
 *   branchCode      -> payee_branch_code     ewt_rate       -> tax_rate
 *   companyName     -> company_name          tax_amount     -> tax_withheld
 *   surName/firstName/middleName -> last_name/first_name/middle_name
 *
 * income_payment, tax_rate and tax_withheld are stored exactly as the workbook
 * supplies them. Nothing here derives one from another: the uploaded file is
 * already computed, and BirExpandedWtaxRowValidator checks the relationship
 * without ever rewriting it.
 *
 * payee_name and payee_type are the only two columns the BIR format does not
 * carry. Both are composed at import from the columns above and hold no
 * information of their own -- payee_name is a display label and a sort key,
 * payee_type tells the validator which name fields a row must fill.
 *
 * The BIR-facing shape is assembled here rather than in the generator so the
 * validator and the generator agree on which fields exist, exactly as
 * VatInput::toBirPurchaseRow() and ImportationEntry::toBirImportationRow() do.
 */
class ExpandedWtaxEntry extends Model
{
    protected $table = 'expanded_wtax_entries';

    protected $fillable = [
        'reporting_period',
        'payee_name',
        'payee_type',
        'payee_tin',
        'payee_branch_code',
        'company_name',
        'last_name',
        'first_name',
        'middle_name',
        'atc_code',
        'tax_rate',
        'income_payment',
        'tax_withheld',
    ];

    protected $casts = [
        'reporting_period' => 'date:Y-m-d',
        'tax_rate' => 'decimal:2',
        'income_payment' => 'decimal:2',
        'tax_withheld' => 'decimal:2',
    ];

    /**
     * payee_name and payee_type are not written to the DAT, but the validator
     * needs the type to decide which name fields are required, and the row
     * errors read better when they can name the payee.
     */
    public function toBirExpandedRow(): array
    {
        return [
            'payee_name' => $this->payee_name,
            'payee_type' => $this->payee_type,
            'payee_tin' => (string) $this->payee_tin,
            'payee_branch_code' => (string) $this->payee_branch_code,
            'company_name' => $this->company_name,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'atc_code' => $this->atc_code,
            'income_payment' => $this->income_payment,
            'tax_rate' => $this->tax_rate,
            'tax_withheld' => $this->tax_withheld,
        ];
    }

    /**
     * Merges rows that share a reporting month, TIN, ATC and rate, summing the
     * income payment and the tax amount.
     *
     * This is the single consolidation rule in the system. The records list, the
     * Generate DAT screen's record count, the DAT download and the dashboard's
     * line count all read through it, for the same reason
     * VatInput::scopeExcludingImportationMirrors() exists: a figure on screen and
     * the file that gets filed must never disagree.
     *
     * Group order follows the order rows arrive in, so a caller that sorted its
     * query -- payee name then rate, the way the reference DAT is arranged --
     * gets consolidated rows in that same order.
     *
     * The payee name is deliberately not part of the key: two rows for the same
     * TIN must merge even when the name is spelled differently, and rows for two
     * different TINs must not merge even when the name matches. Where spellings
     * differ, the first row in the group supplies the name and branch code.
     *
     * @param  iterable<int, self>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function consolidate(iterable $rows): Collection
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = static::consolidationKey($row);

            if (! array_key_exists($key, $groups)) {
                $groups[$key] = $row->toBirExpandedRow() + [
                    // Stable across requests, so it works as a React key and as
                    // a paginator identity without an autoincrement id.
                    'id' => $key,
                    'reporting_period' => $row->reporting_period?->toDateString(),
                    'merged_rows' => 0,
                ];

                $groups[$key]['income_payment'] = 0.0;
                $groups[$key]['tax_withheld'] = 0.0;
            }

            $groups[$key]['income_payment'] += (float) $row->income_payment;
            $groups[$key]['tax_withheld'] += (float) $row->tax_withheld;
            $groups[$key]['merged_rows']++;
        }

        return collect($groups)
            ->map(function (array $group) {
                // Summation is the only arithmetic applied to an uploaded amount;
                // the rate is never used to re-derive either side.
                $group['income_payment'] = round($group['income_payment'], 2);
                $group['tax_withheld'] = round($group['tax_withheld'], 2);

                return $group;
            })
            ->values();
    }

    /**
     * The four fields of the consolidation key, each normalised so a formatting
     * difference cannot block a legitimate merge. A null ATC keys as an empty
     * string and so never merges with a real code -- such rows are unfilable
     * anyway and the validator reports them.
     */
    private static function consolidationKey(self $row): string
    {
        return implode('|', [
            $row->reporting_period?->format('Y-m') ?? '',
            substr(preg_replace('/\D/', '', (string) $row->payee_tin), 0, 9),
            strtoupper(trim((string) $row->atc_code)),
            number_format((float) $row->tax_rate, 2, '.', ''),
        ]);
    }
}
