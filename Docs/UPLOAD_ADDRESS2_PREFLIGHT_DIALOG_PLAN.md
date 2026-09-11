# Purchase and Sales Upload Address2 Validation Plan

## Implementation Status

Implemented on 2026-09-11 in `UploadBirInfoPreflight.php`. Purchase, Sales Summary, and BIR Sales uploads now return an `address2` issue when the resolved City is blank after existing BIR text normalization. The existing controller, Inertia flash sharing, and dialog carry the issue without modification.

Verification: 38 upload tests passed (326 assertions), plus 26 Sales zero-rated and 24 DAT text-validation regression tests passed. PHP syntax and whitespace checks passed. Tests cover Inertia dialog props and preservation of saved rows; browser visual verification was not performed. Unmatched Purchase workbook fallback is tested directly through preflight because the existing importer uses MySQL `LEFT`, which is unsupported by the SQLite test database.

DAT validators/generators, import source priority, calculations, and the existing PDF renderer changes were left unchanged by this implementation.

## Goal

Reject a Purchase or Sales Excel upload when the import cannot resolve a non-blank `address2` for an importable supplier/customer row, and show the missing City/Address2 problem in the existing upload rejection dialog before any records are deleted or replaced.

In this application, BIR `address2` is the City field:

- Purchase: `suppliers.city` becomes `vat_inputs.address2` when a Supplier is matched.
- Sales: `customers.city` becomes `sales_vatsinputs.address2` when a Customer is matched.

This is an upload-validation change only. It must not change any DAT file format, generator, field order, calculation, stored amount, or master-data lookup rule.

## Confirmed Current Behavior

The current upload flow already has the required safe hook and dialog:

1. `VatInputController::import()` validates the selected workbook type and period.
2. `UploadBirInfoPreflight` resolves the Supplier/Customer information that would be used by the importer.
3. If the preflight returns issues, the controller flashes `uploadIssueDialog` and returns before the replacement transaction.
4. `RecordEntry.jsx` automatically opens the structured dialog, lists each worksheet row/problem, supports `Copy Errors`, and links to Suppliers or Customers.
5. Only after both preflights pass does the controller enter the `DB::transaction` that deletes and replaces the selected month.

The gap is that `BirPurchaseRowValidator` and `BirSalesRowValidator` require `address1`, but do not require `address2`. They only validate Address2 length and unsupported characters when a value exists. Therefore, a blank resolved City can currently pass upload preflight.

## Required Behavior

For every importable Purchase or Sales row:

1. Use the same master-data lookup and fallback priority already used by the real importer.
2. Determine the exact `address2` value that would be persisted.
3. Normalize/trim it using the existing BIR text handling.
4. If the final resolved value is blank, add a structured upload issue with field `address2`.
5. Return all issues together so the user can correct every affected row in one pass.
6. Reject before the delete/import transaction, preserving existing rows for the selected month.

Suggested Purchase problem text:

```text
Supplier Address2 (City) is required. Add the supplier City in Master Data > Suppliers, or correct the workbook identity so it matches the intended supplier.
```

Suggested Sales problem text:

```text
Customer Address2 (City) is required. Add the customer City in Master Data > Customers, or correct the workbook customer name so it matches the intended customer.
```

The dialog issue should continue to include:

- worksheet row number
- supplier/customer name
- `field: address2`
- problem text
- fix location and route
- needed fields
- lookup basis used

## Resolution Rules to Preserve

Validate the effective value that the current importer would save; do not introduce a different lookup result in preflight.

### Purchase

Mirror `VatInputImport::findSupplier()` and `supplierAddress()`:

1. Match by full normalized 12-digit Supplier TIN.
2. Otherwise match by the first 9 TIN digits.
3. Otherwise match by normalized Supplier name.
4. When matched, Address2 is `suppliers.city`.
5. When unmatched, retain the existing workbook fallback: the last comma-separated part of Address1, then the workbook Address2 column.
6. Reject only when the effective Address2 is still blank.

Continue skipping configured excluded suppliers such as Bureau of Customs before this validation.

### Sales Summary

Mirror `SalesVatInputImport::importSalesSummaryRow()`:

1. Match Customer by normalized `name_key`, preferring the latest matching row.
2. Use `customers.city` when available.
3. Otherwise retain the current eligible historical `sales_vatsinputs.address2` fallback.
4. Reject when the effective Address2 remains blank.

Continue skipping Debit Memo and total/guide rows before this validation.

### BIR Sales Workbook

Mirror `SalesVatInputImport::importBirSalesRow()`:

1. Match Customer by normalized name.
2. Use `customers.city` when available.
3. Otherwise retain the current workbook Address2 fallback.
4. Reject when the effective Address2 remains blank.

This plan does not require every workbook name to have a master-data match. A valid existing fallback may still supply Address2, matching current import behavior.

## Backend Implementation Plan

### 1. Extend `UploadBirInfoPreflight`

Target:

```text
app/Imports/UploadBirInfoPreflight.php
```

Add an upload-only required-Address2 check after each row's effective BIR values have been resolved:

- Purchase path in `checkPurchase()`.
- Sales Summary path in `salesSummaryIssues()`.
- BIR Sales path in `birSalesIssues()`.

Prefer assigning the resolved Sales `address1` and `address2` values to local variables, then use those same variables for both `BirSalesRowValidator` and the new blank-Address2 check. This prevents the validation source from drifting from the value that would be imported.

The new error must pass through `uploadBlockingErrors()`. It must not match the two deliberately allowed text-only categories:

- `must not exceed`
- `cannot contain comma or ampersand`

### 2. Map the Error to the Correct Dialog Field

Update both field classifiers in the same preflight class:

- `purchaseField()` maps an Address2/City-required error to `address2`.
- `salesField()` maps an Address2/City-required error to `address2`.

Keep the current structured issue destinations:

- Purchase: `Master Data > Suppliers`, route `/suppliers`.
- Sales: `Master Data > Customers`, route `/customers`.

Keep `needed_fields` as `TIN, Address, City` so the existing dialog remains useful for mixed BIR-info failures.

### 3. Do Not Change Global DAT Validators

Do not make Address2 globally required in:

```text
app/Services/BIR/BirPurchaseRowValidator.php
app/Services/BIR/BirSalesRowValidator.php
```

Reason: the requested behavior is specifically for Excel upload. Keeping the new rule in `UploadBirInfoPreflight` avoids broad changes to manual records, Generate DAT validation, and unrelated flows.

### 4. Reuse the Existing Controller and Dialog

No behavior change should be necessary in:

```text
app/Http/Controllers/VatInputController.php
app/Http/Middleware/HandleInertiaRequests.php
resources/js/Pages/RecordEntry.jsx
```

The controller already rejects any non-empty Purchase/Sales preflight issue list before `DB::transaction`, and the frontend already renders the structured fields required for the new Address2 issue.

During implementation, confirm these files still behave that way. Change them only if a focused test proves the new issue is not reaching or rendering in the dialog.

## Test Plan

Extend the focused upload test coverage, preferably in:

```text
tests/Feature/UploadWorkbookTypePreflightTest.php
```

### Purchase Cases

- A matched Supplier with a blank `city` produces an `uploadIssueDialog` issue whose `field` is `address2`.
- The issue contains the worksheet row, Supplier name, `/suppliers`, and `Master Data > Suppliers`.
- The response has no success flash.
- Existing same-month Purchase rows remain unchanged and the new workbook row is not imported.
- A matched Supplier with a non-blank `city` still uploads successfully.
- An unmatched Purchase row with a valid workbook-derived Address2 remains allowed.
- An unmatched Purchase row whose Address2 fallback is blank is rejected.
- Excluded Bureau of Customs rows do not produce Address2 issues.

### Sales Cases

- A Sales Summary row with no Customer/historical Address2 produces an issue whose `field` is `address2`.
- A matched Customer with a non-blank `city` still uploads successfully.
- A Sales Summary row with a valid eligible historical Address2 retains the current fallback behavior.
- A BIR Sales row with no matched Customer but a valid workbook Address2 remains allowed.
- A BIR Sales row whose effective Address2 is blank is rejected.
- Debit Memo, total, heading, and guide rows do not produce Address2 issues.
- Existing same-month Sales rows remain unchanged after rejection.

### Regression Cases

- Missing/invalid TIN and missing Address1 still appear in the same dialog.
- Sales amount reconciliation errors still appear in the same dialog.
- Text length and comma/ampersand rules remain allowed at upload as currently configured.
- Workbook type and reporting-period failures still happen before BIR-info checks.
- Importation and Expanded WTAX upload flows are unchanged.

## Acceptance Criteria

- Upload is rejected whenever an importable Purchase/Sales row would persist a blank Address2/City.
- The dialog identifies the exact row, name, `address2` field, problem, and master-data page to open.
- All detected missing Address2 rows appear in one response.
- No records for the selected month are deleted or replaced after a failed check.
- Valid Supplier/Customer and valid fallback cases continue to upload.
- No DAT generator, layout, delimiter, line ending, filename, total, calculation, or rounding logic changes.

## Verification Commands

```powershell
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php
php artisan test tests\Feature\SalesZeroRatedImportTest.php
php -l app\Imports\UploadBirInfoPreflight.php
php -l app\Http\Controllers\VatInputController.php
git diff --check
```

Frontend build is optional for this change because the current structured dialog should be reused without modification. If frontend code must change after verification, also run:

```powershell
npm run build
```

Report frontend build status separately if Node/npm is unavailable.
