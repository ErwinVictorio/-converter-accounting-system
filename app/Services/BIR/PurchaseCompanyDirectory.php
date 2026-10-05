<?php
namespace App\Services\BIR;

use App\Models\WithholdingCompany;
use Illuminate\Validation\ValidationException;

class PurchaseCompanyDirectory
{
    public function companies(bool $historical = false): array
    {
        $managed = WithholdingCompany::query()->orderBy('registered_name')->get();
        $configured = collect(config('bir.companies', []))->map(
            fn ($company) => app(WithholdingCompanyDirectory::class)->normaliseCompany($company)
        );
        // Managed deactivation takes precedence over the config fallback.
        return $managed->map(fn ($company) => $company->toDirectoryEntry())->toBase()
            ->merge($configured)->unique(fn ($company) => $company['tin'].'|'.$company['branch_code'])
            ->filter(fn ($company) => $historical || $company['is_active'])->values()->all();
    }

    public function resolve(?string $tin, ?string $branch, bool $historical = false): array
    {
        if (! preg_match('/^(\d{9}|\d{3}-\d{3}-\d{3})$/', (string) $tin)
            || ! preg_match('/^\d{1,4}$/', (string) $branch)) {
            throw ValidationException::withMessages(['filing_company_tin' => 'Select a valid Purchase filing company and branch.']);
        }
        $tin = WithholdingCompany::normaliseTin($tin);
        $branch = WithholdingCompany::normaliseBranchCode($branch);
        $company = collect($this->companies($historical))->first(
            fn ($company) => $company['tin'] === $tin && $company['branch_code'] === $branch
        );
        if (! $company) {
            throw ValidationException::withMessages(['filing_company_tin' => 'The selected Purchase filing company is unavailable.']);
        }
        return $company;
    }

    public function forDat(array $company): array
    {
        foreach (['registered_name', 'address1', 'address2', 'rdo_code'] as $field) {
            if (trim((string) ($company[$field] ?? '')) === '') {
                throw ValidationException::withMessages(['filing_company_tin' => "Complete the selected company's {$field} in Manage Companies before generating Purchase DAT."]);
            }
        }
        return [...$company, 'final_header_field' => '12'];
    }
}
