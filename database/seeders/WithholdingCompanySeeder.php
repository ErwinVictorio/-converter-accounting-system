<?php

namespace Database\Seeders;

use App\Models\WithholdingCompany;
use Illuminate\Database\Seeder;

/**
 * Puts the company that was hard-coded in config/bir.php into the table Settings >
 * Manage Companies edits:
 *
 *     php artisan db:seed --class=WithholdingCompanySeeder
 *
 * Optional, not required. WithholdingCompanyDirectory still reads
 * config('bir.companies') as a fallback, so the Known Company dropdowns and the
 * 1601EQ header work before this ever runs. Running it is what makes the company
 * editable from the UI.
 *
 * Kept out of DatabaseSeeder on purpose -- a full `db:seed` also re-runs the
 * supplier and customer seeders.
 *
 * firstOrCreate on the tin+branch identity, not updateOrCreate: re-running must
 * never overwrite an address or RDO the user has since corrected in the UI.
 */
class WithholdingCompanySeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('bir.companies', []) as $company) {
            $tin = WithholdingCompany::normaliseTin($company['tin'] ?? '');

            if ($tin === '') {
                continue;
            }

            $branchCode = WithholdingCompany::normaliseBranchCode($company['branch_code'] ?? '0000');

            $record = WithholdingCompany::firstOrCreate(
                ['tin' => $tin, 'branch_code' => $branchCode],
                [
                    'registered_name' => $company['registered_name'] ?? $company['name'] ?? $tin,
                    // Only a genuinely different trade name is worth storing; config
                    // carries the same string in both keys for Fortress Steel.
                    'trade_name' => ($company['name'] ?? null) === ($company['registered_name'] ?? null)
                        ? null
                        : ($company['name'] ?? null),
                    'rdo_code' => $company['rdo_code'] ?? null,
                    'address1' => $company['address1'] ?? null,
                    'address2' => $company['address2'] ?? null,
                    'is_active' => true,
                ]
            );

            $this->command?->line($record->wasRecentlyCreated
                ? "Added {$record->label}"
                : "{$record->label} already managed -- left untouched.");
        }
    }
}
