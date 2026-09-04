# Upload BIR Info Preflight Dialog Plan

## Goal

Show Purchase and Sales BIR-info problems during Excel upload, before records are replaced/imported, so the user can fix missing TIN/address/name data before the month is saved.

The current Generate DAT page already reports these problems after records exist. This plan moves the same class of checks earlier into the upload flow and presents them in a readable dialog.

No DAT file layout should change.

## Current Behavior

Purchase and Sales upload already run `UploadWorkbookTypePreflight` before writing rows:

- `app/Http/Controllers/VatInputController.php`
- `app/Imports/UploadWorkbookTypePreflight.php`

That current preflight checks:

- selected upload type matches the workbook type
- selected reporting month matches the workbook period

After preflight passes, the controller deletes/replaces existing month rows and imports the workbook.

DAT validation currently happens later:

- Purchase Generate DAT validates saved `vat_inputs` rows through `BirPurchaseRowValidator`.
- Sales Generate DAT validates saved/consolidated `sales_vatsinputs` rows through `BirSalesRowValidator`.

That is why the user currently sees errors on Generate DAT instead of during upload.

## Required New Behavior

When uploading Purchase or Sales Excel:

1. Validate request fields and file type.
2. Run the existing workbook type/month preflight.
3. Run a new BIR-info preflight against the workbook rows.
4. If BIR-info issues exist, reject the upload before deleting existing month records.
5. Return structured issue details to the frontend.
6. The Upload page opens a dialog modal automatically.
7. The dialog lists each issue and where to fix it.

Example Purchase issue:

```text
Row 13 - BUREAU OF CUSTOMS
Problem: Vendor TIN must contain a valid first 9 digits and cannot be 000000000.
Fix in: Master Data > Suppliers
Match used: supplier name / vendor TIN
Needed fields: TIN, Address, City
```

Example Sales issue:

```text
Row 30 - ABC CUSTOMER INC
Problem: Customer Address1 is required.
Fix in: Master Data > Customers
Match used: customer name
Needed fields: TIN, Address, City
```

## Scope

Included:

- Purchase upload BIR-info preflight.
- Sales upload BIR-info preflight.
- Upload rejection dialog on `resources/js/Pages/RecordEntry.jsx`.
- Backend tests proving failed BIR-info preflight does not delete existing rows.
- Clear fix-location guidance per issue.

Not included:

- DAT format changes.
- Expanded WTAX DAT changes.
- Importation DAT changes.
- Automatic supplier-to-purchase sync, unless implemented as a separate feature.

## Backend Plan

### 1. Add a Purchase/Sales Upload BIR Info Preflight Service

Create a new service, for example:

```text
app/Imports/UploadBirInfoPreflight.php
```

Responsibilities:

- Read the uploaded workbook.
- Detect the selected upload type from the controller input, not by guessing.
- Simulate the same BIR-info source logic used by the real import.
- Return structured issues instead of writing rows.

Suggested public methods:

```php
public function checkPurchase($file, string $reportingPeriod): array
public function checkSales($file, string $reportingPeriod): array
```

The return value should be an array of issue objects:

```php
[
    [
        'row' => 13,
        'name' => 'BUREAU OF CUSTOMS',
        'record_type' => 'purchase',
        'field' => 'vendor_tin',
        'problem' => 'Vendor TIN must contain a valid first 9 digits and cannot be 000000000.',
        'fix_location' => 'Master Data > Suppliers',
        'fix_route' => '/suppliers',
        'needed_fields' => ['TIN', 'Address', 'City'],
        'match_basis' => 'supplier name / vendor TIN',
    ],
]
```

Keep this structured because the frontend needs to group and display the issues cleanly.

### 2. Purchase Preflight Rules

The Purchase preflight should mirror the important parts of `VatInputImport` without saving:

Runtime references:

- `app/Imports/VatInputImport.php`
- `app/Models/Supplier.php`
- `app\Services\BIR\BirPurchaseRowValidator.php`

For each importable Purchase row:

1. Read the raw Excel TIN from `vendor_tin`, `tin`, or `tin_number`.
2. Read the supplier name from `supplier_name`, `company_name`, or `companyname`.
3. Look up `suppliers` using the same priority as `VatInputImport::findSupplier()`:
   - full 12-digit TIN
   - first 9 digits of TIN
   - exact supplier name
4. Build the would-be saved BIR row:
   - if supplier exists, use `suppliers.tin`, `suppliers.name`, `suppliers.addr`, `suppliers.city`
   - otherwise use Excel fallback values
5. Run `BirPurchaseRowValidator`.
6. Convert validator errors into user-friendly upload issues.

Purchase fix guidance:

| Problem | Fix Location | Guidance |
| --- | --- | --- |
| Missing/invalid vendor TIN | `Master Data > Suppliers` | Add or correct the supplier TIN. |
| Missing Supplier Address1 | `Master Data > Suppliers` | Add supplier address and city. |
| Comma or ampersand in BIR text | `Master Data > Suppliers` or Excel row | Clean supplier name/address text. |
| Supplier not found and Excel lacks BIR info | `Master Data > Suppliers` | Add supplier master record or correct workbook TIN/name so it matches. |

Important:

- If no supplier is found but the Excel row already has valid TIN and address, the preflight may allow the upload.
- If no supplier is found and the Excel row has incomplete BIR info, tell the user to fix it in `Master Data > Suppliers` or correct the workbook identity.

### 3. Sales Preflight Rules

The Sales preflight should mirror the important parts of `SalesVatInputImport` without saving:

Runtime references:

- `app/Imports/SalesVatInputImport.php`
- `app/Models/Customer.php`
- `app\Services\BIR\BirSalesRowValidator.php`
- `app\Services\BIR\SalesSiCmConsolidator.php`

For each importable Sales row:

1. Detect whether the workbook row is Sales Summary or BIR Sales format using the existing importer rules.
2. Read the customer name.
3. Look up `customers` by `Customer::normalizeName()` and `customers.name_key`.
4. Build the would-be saved BIR row:
   - if customer exists, use `customers.tin`, `customers.name`, `customers.addr`, `customers.city`
   - otherwise use the same fallback rules as the importer
5. Validate with `BirSalesRowValidator`.
6. Convert validator errors into user-friendly upload issues.

Sales fix guidance:

| Problem | Fix Location | Guidance |
| --- | --- | --- |
| Missing/invalid customer TIN | `Master Data > Customers` | Add or correct the customer TIN. |
| Missing Customer Address1 | `Master Data > Customers` | Add customer address and city. |
| Comma or ampersand in BIR text | `Master Data > Customers` or Excel row | Clean customer name/address text. |
| Customer not found and Excel lacks BIR info | `Master Data > Customers` | Add customer master record or correct workbook customer name so it matches. |

Important:

- Sales Summary rows may reuse previous saved Sales BIR info in the current importer. The preflight should either mirror that fallback or intentionally require the customer master record. Prefer mirroring current behavior first to avoid blocking uploads that already work.
- BIR Sales rows may use workbook TIN/address fallback if no customer is found.

### 4. Controller Hook Point

Update `VatInputController::import()` only after the existing type/month preflight passes and before the delete/import transaction.

Purchase target flow:

```php
$issues = (new UploadWorkbookTypePreflight)->check($file, 'purchase', $reportingPeriod);
if ($issues !== []) {
    return back()->with('error', implode(' ', $issues));
}

$birIssues = (new UploadBirInfoPreflight)->checkPurchase($file, $reportingPeriod);
if ($birIssues !== []) {
    return back()->with('uploadIssueDialog', [
        'title' => 'Purchase upload needs BIR info fixes',
        'message' => 'Fix supplier BIR info before uploading this file.',
        'record_type' => 'purchase',
        'issues' => $birIssues,
    ]);
}

DB::transaction(... delete and import ...);
```

Sales target flow is the same but uses `checkSales()`.

Do not delete existing rows until both preflights pass.

### 5. Flash / Inertia Data Shape

Current flash sharing already supports `flash.error`, but the new dialog needs structured data.

Update the shared flash props if needed, likely in:

```text
app/Http/Middleware/HandleInertiaRequests.php
```

Suggested shape:

```php
'uploadIssueDialog' => fn () => $request->session()->get('uploadIssueDialog'),
```

Keep `flash.error` for short toast messages if desired:

```php
return back()
    ->with('error', 'Upload rejected. Fix BIR info before importing.')
    ->with('uploadIssueDialog', [...]);
```

## Frontend Plan

### 1. Reuse Existing Upload Dialog Pattern

Current file:

```text
resources/js/Pages/RecordEntry.jsx
```

It already has:

- Radix/shadcn dialog components
- `Upload Rejected` modal
- `Copy Errors`
- `flash.error` handling

Extend this instead of creating a separate modal system.

### 2. Dialog Behavior

When `flash.uploadIssueDialog` exists:

- Show short toast:

```text
Upload rejected. Fix BIR info before importing.
```

- Open dialog automatically.
- Display title and message from backend.
- Group issues by fix location:
  - `Master Data > Suppliers`
  - `Master Data > Customers`
  - `Excel workbook`
- Show row number, name, problem, needed fields, and match basis.
- Include a `Copy Errors` button.
- Include a link/button to the related master-data page:
  - `/suppliers` for Purchase
  - `/customers` for Sales

### 3. Suggested Dialog Layout

Header:

```text
Upload Rejected
Fix BIR info before uploading this file.
```

Summary:

```text
7 issue(s) found. No records were imported or replaced.
```

Issue card:

```text
Row 13 - BUREAU OF CUSTOMS
Problem: Vendor TIN must contain a valid first 9 digits and cannot be 000000000.
Fix in: Master Data > Suppliers
Needed fields: TIN, Address, City
Match used: supplier name / vendor TIN
```

Actions:

- `Copy Errors`
- `Open Suppliers` or `Open Customers`
- `Close`

### 4. Preserve Existing Expanded WTAX Dialog

The current Expanded WTAX dialog is string-based and detects messages beginning with:

- `Expanded withholding tax upload rejected.`
- `Expanded withholding tax annual upload rejected.`

Do not break that flow.

Preferred frontend behavior:

- If `flash.uploadIssueDialog` exists, render the new structured BIR-info dialog.
- Else if Expanded WTAX string details exist, render the existing Expanded WTAX dialog.
- Else show ordinary `toast.error(flash.error)`.

## User Message Wording

Purchase rejection:

```text
Purchase upload rejected. Fix supplier BIR info before uploading this file.
No records were imported or replaced.
```

Sales rejection:

```text
Sales upload rejected. Fix customer BIR info before uploading this file.
No records were imported or replaced.
```

Field-level wording:

```text
Vendor TIN must contain a valid first 9 digits and cannot be 000000000.
Supplier Address1 is required.
Customer TIN must contain a valid first 9 digits and cannot be 000000000.
Customer Address1 is required.
```

Fix-location wording:

```text
Fix in Master Data > Suppliers, then upload the file again.
Fix in Master Data > Customers, then upload the file again.
If the master record already exists, check that the workbook TIN/name matches it.
```

## Tests To Add

### Backend

Add focused tests around `VatInputController::import()` and/or the new preflight service.

Purchase:

- Rejects upload before import when matched supplier has invalid/missing TIN.
- Rejects upload before import when matched supplier has missing address.
- Rejects upload before import when no supplier match exists and workbook fallback BIR info is incomplete.
- Allows upload when supplier master data supplies valid TIN/address.
- Allows upload when no supplier exists but workbook row has valid BIR info.
- Failed BIR-info preflight does not delete existing `vat_inputs` rows for that month.
- Returned issue includes row number, supplier name, fix location `/suppliers`, and needed fields.

Sales:

- Rejects upload before import when matched customer has invalid/missing TIN.
- Rejects upload before import when matched customer has missing address.
- Rejects upload before import when no customer match exists and fallback BIR info is incomplete.
- Allows upload when customer master data supplies valid TIN/address.
- Failed BIR-info preflight does not delete existing `sales_vatsinputs` rows for that month.
- Returned issue includes row number, customer name, fix location `/customers`, and needed fields.

Regression:

- Wrong workbook type is still rejected before BIR-info preflight.
- Wrong reporting month is still rejected before BIR-info preflight.
- Expanded WTAX upload rejection dialog behavior remains unchanged.

### Frontend

If frontend testing is available:

- `RecordEntry.jsx` opens the BIR-info dialog when `flash.uploadIssueDialog` exists.
- Dialog shows grouped issues and fix-location links.
- `Copy Errors` copies all issue details.
- Existing Expanded WTAX upload rejection still opens its current dialog.

## Verification Commands

Recommended focused backend tests:

```bash
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php
php artisan test tests\Feature\VatInputImportTest.php tests\Feature\SalesVatInputImportTest.php
```

If new tests are added under a dedicated file:

```bash
php artisan test tests\Feature\UploadBirInfoPreflightTest.php
```

Syntax checks:

```bash
php -l app\Imports\UploadBirInfoPreflight.php
php -l app\Http\Controllers\VatInputController.php
php -l app\Http\Middleware\HandleInertiaRequests.php
```

Frontend build, if Node/npm is available:

```bash
npm run build
```

## Implementation Notes

- Keep the validation before any delete/replacement logic.
- Prefer sharing row-building logic with the existing importers if it can be done without a broad refactor.
- If sharing the importer logic would become large, duplicate only the narrow BIR-info mapping in the preflight service and cover it with tests.
- Keep issue details structured; avoid parsing long backend strings in React for this new flow.
- Keep all messages actionable: every issue should say what is wrong and where the user should fix it.
