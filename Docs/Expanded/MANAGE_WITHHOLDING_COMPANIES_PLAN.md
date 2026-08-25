# Manage Withholding Companies Plan

## Goal

Create a Manage Company module so the `Known Company` dropdown is not limited to one hard-coded company.

Current issue:

- `FORTRESS STEEL INC.` is coming from config/default data.
- The company dropdown can show companies from existing uploaded rows, but there is no clean place to add, edit, or deactivate companies before upload/generation.

Target behavior:

- User can add multiple withholding agent companies.
- Upload Excel page displays those companies in `Known Company`.
- Generate DAT page displays the same companies in `Known Company`.
- Selected company supplies:
  - company TIN
  - branch code
  - registered name
  - RDO code

## Scope Guard

This plan is only for managing withholding agent companies used by Expanded WTAX.

Do not change:

- 1601EQ DAT format
- Sales DAT format
- Purchase DAT format
- Importation DAT format
- Expanded WTAX upload column format
- Expanded WTAX company + rate consolidation rule
- VAT dashboard computation

## Recommended Name

Use this module name in UI:

```text
Manage Companies
```

or more specific:

```text
Manage Withholding Companies
```

Recommended route:

```text
/withholding-companies
```

## Data Model

Create a new table:

```text
withholding_companies
```

Columns:

```text
id
tin
branch_code
registered_name
trade_name
rdo_code
address1
address2
is_active
created_at
updated_at
```

Validation rules:

- `tin` required, exactly 9 digits after removing dashes/spaces.
- `branch_code` required, 4 digits after padding.
- `registered_name` required.
- `rdo_code` nullable or exactly 3 digits.
- `address1` nullable.
- `address2` nullable.
- `is_active` boolean.
- Unique company identity:

```text
tin + branch_code
```

## Seeder / Migration Plan

Add migration:

```text
create_withholding_companies_table
```

Seed the existing config company:

```text
TIN: 008791976
Branch: 0000
Registered Name: FORTRESS STEEL INC.
RDO: 045
```

Do not remove `config/bir.php` immediately. Keep it as fallback until the DB-backed company module is stable.

## Backend Plan

### Model

Create:

```text
app/Models/WithholdingCompany.php
```

Model responsibilities:

- Normalize TIN to 9 digits.
- Normalize branch code to 4 digits.
- Normalize RDO to 3 digits when provided.
- Provide display label:

```text
FORTRESS STEEL INC. (008791976-0000)
```

### Controller

Create:

```text
app/Http/Controllers/WithholdingCompanyController.php
```

Actions:

```text
index
store
update
destroy or deactivate
```

Recommended: use deactivate instead of hard delete, because old uploaded rows may reference the company.

### Routes

Add routes:

```php
Route::get('/withholding-companies', [WithholdingCompanyController::class, 'index']);
Route::post('/withholding-companies', [WithholdingCompanyController::class, 'store']);
Route::put('/withholding-companies/{company}', [WithholdingCompanyController::class, 'update']);
Route::delete('/withholding-companies/{company}', [WithholdingCompanyController::class, 'destroy']);
```

If using deactivate:

```php
Route::patch('/withholding-companies/{company}/deactivate', ...);
```

## Existing Company Source Refactor

Current company sources are in:

- `VatInputController::birCompanies()`
- `DatFileController::expandedCompanies()`
- `DatFileController::selectedWithholdingAgent()`
- `DatFileController::companyForExpandedDownload()`

Refactor into one shared service:

```text
app/Services/BIR/WithholdingCompanyDirectory.php
```

Service methods:

```php
activeCompanies(): array
find(string $tin, string $branchCode): ?array
defaultCompany(): array
normaliseCompany(array $company): array
```

Source priority:

1. Active rows from `withholding_companies`
2. Existing `config('bir.companies')` fallback
3. Uploaded Expanded WTAX distinct companies as fallback-only suggestions

Important:

- The dropdown should primarily show managed active companies.
- Uploaded rows can still be used as fallback so old data does not disappear.

## Frontend Plan

### New Page

Create:

```text
resources/js/Pages/WithholdingCompanies.jsx
```

Page features:

- List companies.
- Add company.
- Edit company.
- Deactivate/reactivate company.
- Search by name or TIN.

Fields:

```text
Registered Name
Trade Name
TIN
Branch Code
RDO Code
Address 1
Address 2
Active
```

### Navigation

Add a sidebar parent menu:

```text
Settings
```

Under `Settings`, add this child menu item:

```text
Manage Companies
```

Recommended sidebar structure:

```text
Settings
  - Manage Companies
```

Do not place `Manage Companies` as a loose top-level sidebar item. It should live under `Settings` so future admin/configuration pages can be grouped there too.

### Upload Page

Update:

```text
resources/js/Pages/RecordEntry.jsx
```

Use managed company list for `Known Company`.

When a company is selected:

- Fill `Company TIN`.
- Fill `Branch Code`.
- Keep the selected company as the upload withholding agent.

### Generate DAT Page

Update:

```text
resources/js/Pages/GenerateDatFile.jsx
```

Use managed company list for `Known Company`.

When a company is selected:

- Fill `Company TIN`.
- Fill `Branch Code`.
- Reload available periods for that company.

## Behavior Rules

### Adding Company

When user adds a company:

- Store normalized TIN.
- Store padded branch code.
- Save registered name and RDO code.
- Immediately show it in Upload and Generate dropdowns.

### Editing Company

Allow editing:

- registered name
- trade name
- RDO code
- address
- active status

Be careful editing TIN/branch:

- If old uploaded rows already use the TIN/branch, changing those fields may disconnect old records from the dropdown.
- Recommended: allow TIN/branch edits only if no Expanded WTAX rows exist for that company.
- Otherwise require creating a new company record.

### Deleting Company

Prefer deactivate instead of delete.

Deactivated company:

- Hidden from new upload dropdown.
- Still usable for old generated periods if records exist.
- Can be reactivated.

## Tests

Add feature tests:

- Can list withholding companies.
- Can add company.
- TIN is normalized to 9 digits.
- Branch code is padded to 4 digits.
- Duplicate `tin + branch_code` is rejected.
- Inactive company does not appear as primary dropdown option.
- Existing config company is seeded or shown as fallback.
- Upload page receives managed companies.
- Generate DAT page receives managed companies.
- Selected company filters Expanded WTAX periods correctly.
- Download DAT still uses selected company TIN, branch, registered name, and RDO.

Keep existing focused Expanded WTAX tests passing:

```bash
php artisan test tests/Feature/ExpandedWtaxImportTest.php tests/Feature/ExpandedWtaxDatFileTest.php tests/Feature/ExpandedWtaxConsolidationTest.php tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php tests/Unit/BirExpandedWtaxRowValidatorTest.php
```

## Implementation Order

1. Create `withholding_companies` migration.
2. Create `WithholdingCompany` model.
3. Seed existing Fortress Steel company.
4. Create `WithholdingCompanyDirectory` service.
5. Refactor upload/generate controllers to use the service.
6. Create Manage Companies page and routes.
7. Add `Settings` menu in the sidebar.
8. Add `Manage Companies` under `Settings`.
9. Update Upload and Generate dropdowns to use managed companies.
10. Add tests.
11. Run focused Expanded WTAX tests.

## Done Criteria

This is done when:

- User can add a company from Manage Companies.
- Sidebar has a `Settings` menu.
- `Manage Companies` is displayed under `Settings`.
- Added company appears in `Known Company` dropdown on Upload Excel.
- Added company appears in `Known Company` dropdown on Generate DAT.
- Selecting a company fills TIN and branch.
- Generated DAT uses the selected company details.
- Existing Fortress Steel flow still works.
- No Sales/Purchase/Importation DAT formats are changed.
