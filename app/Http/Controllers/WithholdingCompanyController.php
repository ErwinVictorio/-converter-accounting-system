<?php

namespace App\Http\Controllers;

use App\Models\WithholdingCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Settings > Manage Companies: the withholding agent companies the Expanded WTAX
 * upload and the 1601EQ DAT can be filed for.
 *
 * Nothing here touches Sales, Purchases or Importation, and nothing here changes a
 * DAT layout -- it only decides which company's TIN, branch, registered name and
 * RDO the existing 1601EQ header and control record are built from.
 *
 * Two rules are enforced rather than left to the user:
 *
 *  - Deactivate, not delete, once a month has been filed under a company. The
 *    uploaded rows carry the agent TIN and branch themselves and are not linked by
 *    foreign key, so deleting the company would leave those months unregenerable
 *    from the dropdown while their data stayed in the table.
 *  - The TIN and branch may only be edited while no Expanded WTAX row references
 *    them. Editing them afterwards would silently disconnect every filed row from
 *    the company it was filed under; the answer is a new company record.
 */
class WithholdingCompanyController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));
        // The TIN is stored digits-only, so the term is matched on its digits --
        // but only when it has any. A term of "zebra" reduces to an empty string,
        // and LIKE '%%' would match every row.
        $searchDigits = preg_replace('/\D/', '', $search);

        $companies = WithholdingCompany::query()
            ->when($search !== '', function ($query) use ($search, $searchDigits) {
                $query->where(function ($scoped) use ($search, $searchDigits) {
                    $scoped->where('registered_name', 'LIKE', "%{$search}%")
                        ->orWhere('trade_name', 'LIKE', "%{$search}%");

                    if ($searchDigits !== '') {
                        $scoped->orWhere('tin', 'LIKE', "%{$searchDigits}%");
                    }
                });
            })
            ->orderBy('registered_name')
            ->orderBy('branch_code')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (WithholdingCompany $company) => [
                'id' => $company->id,
                'tin' => $company->tin,
                'branch_code' => $company->branch_code,
                'registered_name' => $company->registered_name,
                'trade_name' => $company->trade_name,
                'rdo_code' => $company->rdo_code,
                'address1' => $company->address1,
                'address2' => $company->address2,
                'is_active' => $company->is_active,
                'label' => $company->label,
                // Drives the UI: the row explains why its TIN fields are locked
                // instead of failing validation after the user has typed.
                'has_filed_rows' => $company->hasFiledRows(),
            ]);

        return Inertia::render('WithholdingCompanies', [
            'companies' => $companies,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        WithholdingCompany::create($validated);

        return back()->with('success', 'Withholding company added.');
    }

    public function update(Request $request, WithholdingCompany $company)
    {
        $validated = $this->validated($request, $company);

        $identityChanged = $validated['tin'] !== $company->tin
            || $validated['branch_code'] !== $company->branch_code;

        if ($identityChanged && $company->hasFiledRows()) {
            throw ValidationException::withMessages([
                'tin' => 'Expanded WTAX records already exist for ' . $company->tin . '-' . $company->branch_code
                    . '. Add a new company instead of changing its TIN or branch code.',
            ]);
        }

        $company->update($validated);

        return back()->with('success', 'Withholding company updated.');
    }

    /**
     * Hidden from the upload dropdown, still available to regenerate a month that
     * was already filed under it.
     */
    public function deactivate(WithholdingCompany $company)
    {
        $company->update(['is_active' => false]);

        return back()->with('success', $company->registered_name . ' deactivated.');
    }

    public function activate(WithholdingCompany $company)
    {
        $company->update(['is_active' => true]);

        return back()->with('success', $company->registered_name . ' reactivated.');
    }

    /**
     * Only ever a correction of a mistyped company. Once a month has been filed
     * under it the row stays and is deactivated instead.
     */
    public function destroy(WithholdingCompany $company)
    {
        if ($company->hasFiledRows()) {
            return back()->with(
                'error',
                'Cannot delete ' . $company->registered_name . ': Expanded WTAX records were filed under '
                . $company->tin . '-' . $company->branch_code . '. Deactivate it instead.'
            );
        }

        $company->delete();

        return back()->with('success', 'Withholding company deleted.');
    }

    /**
     * TIN and branch are validated on their digits, not their formatting, because
     * the model normalises both before they are stored -- so 008-791-976 and
     * 008791976 are the same company and must collide on the unique rule.
     */
    private function validated(Request $request, ?WithholdingCompany $company = null): array
    {
        $request->merge([
            'tin' => WithholdingCompany::normaliseTin($request->input('tin')),
            'branch_code' => WithholdingCompany::normaliseBranchCode($request->input('branch_code')),
        ]);

        $identity = Rule::unique('withholding_companies', 'tin')
            ->where(fn ($query) => $query->where('branch_code', $request->input('branch_code')))
            ->ignore($company?->id);

        $validated = $request->validate([
            'tin' => ['required', 'digits:9', $identity],
            'branch_code' => ['required', 'digits:4'],
            'registered_name' => ['required', 'string', 'max:150'],
            'trade_name' => ['nullable', 'string', 'max:150'],
            'rdo_code' => ['nullable', 'digits:3'],
            'address1' => ['nullable', 'string', 'max:150'],
            'address2' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'tin.unique' => 'A company with this TIN and branch code already exists.',
            'tin.digits' => 'Company TIN must be 9 digits.',
            'branch_code.digits' => 'Branch code must be 4 digits.',
            'rdo_code.digits' => 'RDO code must be 3 digits.',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        return $validated;
    }
}
