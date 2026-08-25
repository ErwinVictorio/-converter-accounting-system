<?php

namespace App\Services\BIR;

use App\Models\ExpandedWtaxEntry;
use App\Models\WithholdingCompany;
use Illuminate\Support\Collection;

/**
 * The one place the Expanded WTAX screens ask "which companies can we file for?".
 *
 * Before this, VatInputController::birCompanies() and
 * DatFileController::expandedCompanies() each built the same list from the same two
 * sources with their own copy of the TIN/branch normalisation, so the upload
 * dropdown and the generate dropdown could disagree about a company the moment one
 * of the two was touched.
 *
 * Three sources, in priority order:
 *
 *   1. Active rows in withholding_companies -- what Manage Companies maintains.
 *   2. config('bir.companies') -- kept as a fallback so a fresh install (or a
 *      database whose table is still empty) still offers Fortress Steel and still
 *      supplies the RDO code the DAT header needs.
 *   3. Distinct withholding agents already present in expanded_wtax_entries --
 *      suggestions only, so a month uploaded before this module existed does not
 *      vanish from the dropdown.
 *
 * Later sources never override an earlier one: uniqueness is on tin|branch_code and
 * the first occurrence wins.
 *
 * companyForDat() is deliberately separate from the dropdown list. It looks up
 * inactive companies too -- deactivating a company must not make a month already
 * filed under it unregenerable -- and it is the only method that reaches for the
 * address and RDO the 1601EQ header carries.
 */
class WithholdingCompanyDirectory
{
    /**
     * Companies offered in the Known Company dropdowns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeCompanies(): array
    {
        return $this->managed()
            ->merge($this->configured())
            ->merge($this->uploaded())
            ->filter(fn (array $company) => $company['tin'] !== '')
            ->unique(fn (array $company) => $company['tin'] . '|' . $company['branch_code'])
            ->values()
            ->all();
    }

    /**
     * One company by identity, or null. Reads the dropdown list, so an inactive
     * managed company is not found here -- companyForDat() is what still finds it.
     */
    public function find(string $tin, string $branchCode): ?array
    {
        $tin = WithholdingCompany::normaliseTin($tin);
        $branchCode = WithholdingCompany::normaliseBranchCode($branchCode);

        return collect($this->activeCompanies())->first(
            fn (array $company) => $company['tin'] === $tin && $company['branch_code'] === $branchCode
        );
    }

    /**
     * What the screens select when the request names no company: the first managed
     * active company, falling back to the configured one.
     */
    public function defaultCompany(): array
    {
        return $this->activeCompanies()[0]
            ?? $this->normaliseCompany(config('bir.companies.008791976', []));
    }

    /**
     * The withholding agent a request is asking about: the matching company when
     * there is one, otherwise the normalised TIN and branch as typed, so a company
     * that exists only in already-uploaded rows still resolves.
     */
    public function resolve(?string $tin, ?string $branchCode): array
    {
        $default = $this->defaultCompany();
        $tin = WithholdingCompany::normaliseTin($tin ?: ($default['tin'] ?? ''));
        $branchCode = WithholdingCompany::normaliseBranchCode($branchCode ?: ($default['branch_code'] ?? '0000'));

        return $this->find($tin, $branchCode) ?: [
            'tin' => $tin,
            'branch_code' => $branchCode,
            'name' => $this->fallbackName($tin, $branchCode),
        ];
    }

    /**
     * Everything the 1601EQ header and control record need for one agent.
     *
     * Managed row first (including an inactive one), then config, then whatever the
     * caller already knows. Nothing is invented: a company with no RDO on file
     * yields an empty RDO field, which is what the reference DAT does for an agent
     * whose RDO the system has never been told.
     */
    public function companyForDat(array $withholdingAgent): array
    {
        $tin = WithholdingCompany::normaliseTin($withholdingAgent['tin'] ?? '');
        $branchCode = WithholdingCompany::normaliseBranchCode($withholdingAgent['branch_code'] ?? '0000');

        $managed = WithholdingCompany::query()
            ->where('tin', $tin)
            ->where('branch_code', $branchCode)
            ->first();

        $configured = config("bir.companies.{$tin}", []);
        $fallbackName = (string) ($withholdingAgent['name'] ?? $tin);

        $name = $managed?->registered_name
            ?? $configured['name']
            ?? $fallbackName;

        return [
            'tin' => $tin,
            'branch_code' => $branchCode,
            'name' => $name,
            'registered_name' => $managed?->registered_name
                ?? $configured['registered_name']
                ?? $fallbackName,
            'address1' => (string) ($managed?->address1 ?? $configured['address1'] ?? ''),
            'address2' => (string) ($managed?->address2 ?? $configured['address2'] ?? ''),
            'rdo_code' => (string) ($managed?->rdo_code ?? $configured['rdo_code'] ?? ''),
        ];
    }

    /**
     * The dropdown shape, from a config-style array.
     */
    public function normaliseCompany(array $company): array
    {
        $tin = WithholdingCompany::normaliseTin($company['tin'] ?? '');

        return [
            'tin' => $tin,
            'branch_code' => WithholdingCompany::normaliseBranchCode($company['branch_code'] ?? '0000'),
            'name' => (string) ($company['name'] ?? $company['registered_name'] ?? $tin),
            'registered_name' => (string) ($company['registered_name'] ?? $company['name'] ?? $tin),
            'rdo_code' => (string) ($company['rdo_code'] ?? ''),
            'address1' => (string) ($company['address1'] ?? ''),
            'address2' => (string) ($company['address2'] ?? ''),
            'is_active' => true,
            'source' => 'config',
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function managed(): Collection
    {
        return WithholdingCompany::query()
            ->active()
            ->orderBy('registered_name')
            ->orderBy('branch_code')
            ->get()
            ->map(fn (WithholdingCompany $company) => $company->toDirectoryEntry())
            // toBase(), because an Eloquent collection that maps to nothing stays an
            // Eloquent collection, and merging plain arrays into one asks each of
            // them for getKey(). An empty table is the common case here.
            ->toBase();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function configured(): Collection
    {
        return collect(config('bir.companies', []))
            ->map(fn (array $company) => $this->normaliseCompany($company))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function uploaded(): Collection
    {
        return ExpandedWtaxEntry::query()
            ->select([
                'withholding_agent_tin',
                'withholding_agent_branch_code',
                'withholding_agent_name',
            ])
            ->distinct()
            ->get()
            ->map(fn (ExpandedWtaxEntry $entry) => [
                'tin' => (string) $entry->withholding_agent_tin,
                'branch_code' => (string) $entry->withholding_agent_branch_code,
                'name' => (string) $entry->withholding_agent_name,
                'registered_name' => (string) $entry->withholding_agent_name,
                'rdo_code' => '',
                'address1' => '',
                'address2' => '',
                'is_active' => true,
                'source' => 'uploaded',
            ])
            ->toBase();
    }

    /**
     * A name for an agent that is in none of the three sources. Config is consulted
     * by TIN alone here, the way it always was, so a branch this system has never
     * seen still shows the company's name rather than its TIN.
     */
    private function fallbackName(string $tin, string $branchCode): string
    {
        $managed = WithholdingCompany::query()
            ->where('tin', $tin)
            ->where('branch_code', $branchCode)
            ->first();

        return (string) ($managed?->registered_name ?? config("bir.companies.{$tin}.name", $tin));
    }
}
