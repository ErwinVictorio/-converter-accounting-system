# Upload and Record Period Validation

## Purpose

This note documents two related safeguards:

- Upload files are checked before import so a Sales workbook cannot be uploaded as Purchase, and a Purchase workbook cannot be uploaded as Sales.
- Record pages now have a month selector so users can filter each table by the reporting period stored for that module.

No DAT generator or DAT file layout was changed.

## Upload Preflight Validation

Runtime files:

- `app/Imports/UploadWorkbookTypePreflight.php`
- `app/Http/Controllers/VatInputController.php`

Before importing Sales or Purchase uploads, the controller reads the workbook and checks:

- selected file type matches the detected workbook type
- selected reporting month matches the workbook period when the workbook exposes one

The preflight detects workbook type from headers and title rows:

- Sales Summary: `SALES SUMMARY BY DOCUMENT NUMBER`, `Document No`, `Customer Name`
- BIR Sales: `CLIENT TIN`
- Purchase: `supplier_name`, `vendor_tin`, `purchase_imported`, `purchase_local`, `input_vat`, `total_purchases`
- Expanded WTAX remains protected by `ExpandedWtaxUploadPreflight`

Example rejection:

```text
Upload rejected. The selected file type is Purchase, but the workbook appears to be a Sales file.
```

Example period rejection:

```text
Upload rejected. The workbook period is May 2026, but the selected reporting month is September 2026.
```

The upload is rejected before any import or database write runs.

## Record Period Filters

Runtime files:

- `app/Http/Controllers/RecordController.php`
- `resources/js/Components/Records/RecordPeriodFilter.jsx`
- `resources/js/Pages/Records/PurchaseRecords.jsx`
- `resources/js/Pages/Records/SalesRecords.jsx`
- `resources/js/Pages/Records/ExpandedWtaxRecords.jsx`
- `resources/js/Pages/Records/ImportationRecords.jsx`

Each Record page now exposes an `All months` selector with available months and record counts.

The period source per table is:

| Page | Query key | Database field |
| --- | --- | --- |
| Purchase Records | `period` | `vat_inputs.date_uploaded` |
| Sales Records | `period` | `sales_vatsinputs.reporting_period` |
| Expanded WTAX Records | `period` | `expanded_wtax_entries.reporting_period` |
| Importation Records | `tax_month` | `importation_entries.tax_month` |

Search and period filters work together. Pagination preserves the selected filters through `withQueryString()`.

## Verification

Focused tests:

```bash
php artisan test tests\Feature\RecordPagesTest.php tests\Feature\UploadWorkbookTypePreflightTest.php
```

Verified result:

```text
19 passed (384 assertions)
```

PHP syntax check:

```bash
php -l app\Http\Controllers\RecordController.php
```

Frontend build was not verified because `npm` was not available in the shell.

## Planned Same Month Upload Replacement

Goal: every Excel upload may be accepted for a month/year that already has stored rows, but the upload must replace the existing stored rows for that same month/year instead of appending another set. This keeps the workflow flexible when the user corrects an Excel file while preventing doubled DAT/report totals.

No DAT generator or DAT file layout should change for this work.

### Current Runtime State

Runtime files:

- `app/Http/Controllers/VatInputController.php`
- `app/Http/Controllers/ImportationController.php`
- `app/Imports/VatInputImport.php`
- `app/Imports/SalesVatInputImport.php`
- `app/Imports/ImportationEntryImport.php`
- `app/Imports/ExpandedWtaxImport.php`
- `app/Imports/ExpandedWtaxUploadPreflight.php`

Current behavior:

- Sales upload validates workbook type/month, then imports into `sales_vatsinputs` for the selected `reporting_period`. It uses `updateOrCreate` per document/customer/month, but it does not remove old rows from the same month that are no longer present in the corrected workbook.
- Purchase upload validates workbook type/month, then imports into `vat_inputs` for the selected `date_uploaded`. It currently merges duplicate supplier rows by supplier/imported/month during import, but it does not clear the previous month first.
- Expanded WTAX quarterly upload already replaces existing rows scoped by `report_type`, `reporting_period`, withholding agent TIN, and branch code before importing.
- Expanded WTAX annual upload already replaces existing annual rows in the selected covered year/range scoped by withholding agent TIN and branch code before importing.
- Importation upload validates rows inside `ImportationEntryImport` and creates `importation_entries`; each entry is also mirrored to `vat_inputs` through `ImportationEntryWriter`. It currently rejects duplicate import entry numbers that already exist for the same tax month, so re-uploading a corrected workbook for an existing month cannot behave like replacement yet.

### Replacement Rules

Sales:

- After `UploadWorkbookTypePreflight` passes and before `SalesVatInputImport` runs, delete rows from `sales_vatsinputs` where `reporting_period` equals the selected month end.
- Keep the delete and import inside one `DB::transaction`.
- Scope deletion to non-adjusted upload rows only if manual/adjusted Sales rows must survive. Based on the current table, `is_adjusted = false` is the minimum safety scope.
- Keep DM skip behavior unchanged.

Purchase:

- After `UploadWorkbookTypePreflight` passes and before `VatInputImport` runs, delete rows from `vat_inputs` where `date_uploaded` equals the selected month end.
- Do not delete importation mirror rows from `vat_inputs` through this Purchase upload path. Those rows are owned by `importation_entries` and are already excluded from Purchase record/DAT views through existing mirror-aware logic.
- Suggested scope: `date_uploaded = selected month end`, `is_adjusted = false`, and `id` not in the active `importation_entries.vat_input_id` list.
- Keep the existing per-supplier consolidation inside `VatInputImport`.

Expanded WTAX:

- Keep the existing replacement behavior.
- Add/adjust tests only if needed to lock the behavior: same month/same agent replaces rows; same month/different withholding agent does not delete the other agent's rows.

Importation:

- Refactor `ImportationEntryImport` so it first prepares and validates all rows without writing them.
- Collect the distinct `tax_month` values found in the workbook.
- After the workbook is fully valid, delete existing `importation_entries` for those tax months inside the same transaction before creating replacement rows.
- Delete linked `vat_inputs` mirror rows together with their importation entries. Use the existing relationship/`vat_input_id` ownership; do not delete ordinary Purchase uploads from `vat_inputs`.
- After deleting each affected month, create the new rows and let `ImportationEntryWriter::create()` rebuild sequence numbers and mirror `vat_inputs` rows.
- Existing duplicate checks should still reject duplicates inside the same workbook. Duplicate checks against existing database rows must be bypassed or delayed for months that are about to be replaced, otherwise corrected uploads will still fail before replacement.

### Controller Flow

Target shape:

1. Validate request file/type/month inputs.
2. Run workbook preflight or full workbook row validation.
3. If any issue exists, return an error before deleting anything.
4. Start `DB::transaction`.
5. Delete only the records owned by the affected upload period/scope.
6. Import/create replacement rows.
7. Return success message that says the month was replaced, not merely imported.

Example success wording:

```text
Sales VAT report for May 2026 was replaced successfully.
Purchase VAT report for May 2026 was replaced successfully.
Importation upload completed. Replaced 2 month(s), 18 row(s) imported.
```

### Tests To Add

Sales:

- Existing May 2026 rows are removed when a corrected May 2026 workbook is uploaded.
- Other months remain untouched.
- Failed preflight does not delete existing May 2026 rows.

Purchase:

- Existing May 2026 purchase upload rows are removed before corrected May 2026 rows are inserted.
- Importation mirror rows with the same `date_uploaded` survive a Purchase replacement.
- Other months remain untouched.
- Failed preflight does not delete existing rows.

Expanded WTAX:

- Same quarter month/same withholding agent replaces rows.
- Same month/different withholding agent remains untouched.
- Failed preflight does not delete existing rows.

Importation:

- Existing importation rows and their linked `vat_inputs` mirrors are replaced for each tax month present in the workbook.
- Importation upload can replace an existing same-month same-entry-number record.
- Other tax months remain untouched.
- Duplicate import entry numbers inside the same workbook still fail.
- Failed workbook validation does not delete existing importation rows or linked `vat_inputs`.

Recommended focused command after implementation:

```bash
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php tests\Feature\ExpandedWtaxImportTest.php tests\Feature\ImportationEntryTest.php tests\Feature\RecordPagesTest.php
```
