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
        'report_type',
        'withholding_agent_tin',
        'withholding_agent_branch_code',
        'withholding_agent_name',
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
            'report_type' => $this->report_type ?: 'quarterly',
            'withholding_agent_tin' => (string) $this->withholding_agent_tin,
            'withholding_agent_branch_code' => (string) $this->withholding_agent_branch_code,
            'withholding_agent_name' => (string) $this->withholding_agent_name,
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
     * Rows sharing reporting month, withholding agent, payee identity, ATC and
     * rate become one DAT line, with the income payment and the tax amount
     * summed.
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
     * Identity is the payee's name rather than the payee's TIN, so one payee
     * billing at one rate is filed once even when the uploaded rows disagree
     * about the TIN. A detail line can only carry one TIN, so the group takes the
     * first usable one it finds along with that row's branch code, and records
     * every distinct value it saw in distinct_payee_tins /
     * distinct_payee_branch_codes. Those four metadata keys exist for the records
     * screen and for tests; the generator never reads them, so nothing about them
     * reaches the DAT.
     *
     * Two rows for the same payee at different rates, or under different ATCs,
     * still file separately -- that is what the BIR schedule asks for.
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
                    'report_type' => $row->report_type ?: 'quarterly',
                    'withholding_agent_tin' => $row->withholding_agent_tin,
                    'withholding_agent_branch_code' => $row->withholding_agent_branch_code,
                    'withholding_agent_name' => $row->withholding_agent_name,
                    'merged_rows' => 0,
                    'distinct_payee_tins' => [],
                    'distinct_payee_branch_codes' => [],
                ];

                $groups[$key]['income_payment'] = 0.0;
                $groups[$key]['tax_withheld'] = 0.0;
            }

            $groups[$key]['income_payment'] += (float) $row->income_payment;
            $groups[$key]['tax_withheld'] += (float) $row->tax_withheld;
            $groups[$key]['merged_rows']++;

            $tin = static::birTin($row->payee_tin);
            if ($tin !== '' && ! in_array($tin, $groups[$key]['distinct_payee_tins'], true)) {
                $groups[$key]['distinct_payee_tins'][] = $tin;
            }

            $branch = static::branchCode($row->payee_branch_code);
            if (! in_array($branch, $groups[$key]['distinct_payee_branch_codes'], true)) {
                $groups[$key]['distinct_payee_branch_codes'][] = $branch;
            }

            /*
             * A detail line carries one TIN, so the group keeps the first usable
             * one and the branch code written beside it on that same row. The
             * base row's own values are never blanked out: when no row in the
             * group has a filable TIN the unusable value survives, and
             * BirExpandedWtaxRowValidator names the payee and blocks the file.
             */
            if (! static::isFilableTin($groups[$key]['payee_tin']) && static::isFilableTin($row->payee_tin)) {
                $groups[$key]['payee_tin'] = (string) $row->payee_tin;
                $groups[$key]['payee_branch_code'] = (string) $row->payee_branch_code;
            }
        }

        return collect($groups)
            ->map(function (array $group) {
                // Summation is the only arithmetic applied to an uploaded amount;
                // the rate is never used to re-derive either side.
                $group['income_payment'] = round($group['income_payment'], 2);
                $group['tax_withheld'] = round($group['tax_withheld'], 2);

                $group['has_multiple_payee_tins'] = count($group['distinct_payee_tins']) > 1;
                $group['has_multiple_payee_branch_codes'] = count($group['distinct_payee_branch_codes']) > 1;

                return $group;
            })
            ->values();
    }

    /**
     * The six fields of the consolidation key, each normalised so a formatting
     * difference cannot block a legitimate merge. A null ATC keys as an empty
     * string and so never merges with a real code -- such rows are unfilable
     * anyway and the validator reports them.
     */
    private static function consolidationKey(self $row): string
    {
        return implode('|', [
            $row->reporting_period?->format('Y-m') ?? '',
            $row->report_type ?: 'quarterly',
            static::birTin($row->withholding_agent_tin),
            static::branchCode($row->withholding_agent_branch_code),
            static::payeeIdentity($row),
            strtoupper(trim((string) $row->atc_code)),
            number_format((float) $row->tax_rate, 2, '.', ''),
        ]);
    }

    /**
     * The payee as the BIR schedule identifies them: the company name for a
     * company, the three name columns for an individual, and payee_name when a
     * row carries neither -- payee_name is composed at import, so it is the last
     * label still standing.
     */
    private static function payeeIdentity(self $row): string
    {
        $identity = $row->payee_type === 'individual'
            ? static::normalisedName(implode(' ', [$row->last_name, $row->first_name, $row->middle_name]))
            : static::normalisedName($row->company_name);

        return $identity !== '' ? $identity : static::normalisedName($row->payee_name);
    }

    /**
     * Uppercase, ampersands spelled out, punctuation dropped and runs of spaces
     * collapsed -- the same shaping ReliefExpandedWtaxDatGenerator::birName()
     * applies on the way out, except that this one also collapses internal
     * spacing. The generator must not, because stored spacing is filed verbatim;
     * a key comparing two spellings of one payee must, or "PRINTSCAPE  PRINTING"
     * and "PRINTSCAPE PRINTING" would file twice.
     */
    private static function normalisedName(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = preg_replace('/[^A-Z0-9 ]/', ' ', $value);

        return trim(preg_replace('/ {2,}/', ' ', $value));
    }

    private static function birTin(?string $value): string
    {
        return substr(preg_replace('/\D/', '', (string) $value), 0, 9);
    }

    /**
     * Four digits, zero-padded, with a blank reading as the head office -- the
     * same reading ReliefExpandedWtaxDatGenerator::branchCode() files, so a group
     * cannot record a branch the DAT would not write.
     */
    private static function branchCode(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === ''
            ? '0000'
            : substr(str_pad($digits, 4, '0', STR_PAD_LEFT), 0, 4);
    }

    /**
     * The two TIN rules BirExpandedWtaxRowValidator enforces, asked here so a
     * group can tell a usable TIN from one that would block the file anyway.
     */
    private static function isFilableTin(?string $value): bool
    {
        $tin = static::birTin($value);

        return strlen($tin) === 9 && $tin !== '000000000';
    }
}
