# Expanded WTAX Annual and Quarterly Guide

This document describes the implemented Annual and Quarterly report-type changes for Expanded WTAX upload and DAT generation.

## Summary

Expanded WTAX now has a `Type of Report` selector in both workflows:

- `Import Data`
- `Generate DAT File`

The available report types are:

- `Quarterly`
- `Annual`

`Quarterly` is the existing 1601EQ/QAP monthly workflow. `Annual` is available for date-range upload handling, but annual DAT generation is guarded until the exact annual BIR DAT layout is confirmed.

## Import Data

Screen:

- `resources/js/Pages/RecordEntry.jsx`

Route:

- `POST /vat-import`

Controller:

- `app/Http/Controllers/VatInputController.php`

### Non-Expanded Uploads

For `Purchase` and `Sales`, the screen keeps the existing `Reporting Month` field.

No `Type of Report` field is shown for these upload types.

### Expanded WTAX Uploads

When `File Type` is `Expanded WTAX`, the screen shows:

- `Type of Report`
- `Known Company`
- `Company TIN`
- `Branch Code`

The `Type of Report` choices are:

- `Quarterly`
- `Annual`

### Quarterly Upload

When `Quarterly` is selected:

- The screen shows `Reporting Month`.
- The upload behaves like the existing monthly Expanded WTAX upload.
- The workbook row date must fall inside the selected month.

Accepted workbook date columns:

| Layout | Date Column Checked |
| --- | --- |
| BIR 1601EQ Schedule 1 Template | `Reporting_Month` |
| System Expanded WTAX Export | `Date` |

Replacement scope:

- withholding agent TIN
- withholding agent branch code
- selected reporting month

This means re-uploading Fortress Steel July 2026 only replaces Fortress Steel July 2026 rows.

### Annual Upload

When `Annual` is selected:

- The screen shows `Start Date`.
- The screen shows `End Date`.
- The screen hides `Reporting Month`.

Validation rules:

- `start_date` is required.
- `end_date` is required.
- `end_date` must not be earlier than `start_date`.
- Start and end dates must stay inside the same filing year.
- Every workbook row date must fall inside the selected date range.

Accepted workbook date columns:

| Layout | Date Column Checked |
| --- | --- |
| BIR 1601EQ Schedule 1 Template | `Reporting_Month` |
| System Expanded WTAX Export | `Date` |

Storage behavior:

- Annual upload does not store every row under one annual marker.
- Each imported row is stored under the month from its own workbook date.
- Example: a row dated `07/03/2026` is stored as `2026-07-31`; a row dated `08/04/2026` is stored as `2026-08-31`.

Replacement scope:

- withholding agent TIN
- withholding agent branch code
- months covered by the selected start and end dates

Example:

```text
Start Date: 2026-01-01
End Date: 2026-12-31
```

The upload can replace Expanded WTAX rows for that withholding agent from January 2026 through December 2026.

## Generate DAT File

Screen:

- `resources/js/Pages/GenerateDatFile.jsx`

Routes:

- `GET /generate-datfile`
- `GET /download-datfile`

Controller:

- `app/Http/Controllers/DatFileController.php`

### Non-Expanded DAT Generation

For `Purchase`, `Sales`, and `Importation`, the screen keeps the existing `Reporting Month` field.

No `Type of Report` field is shown for these DAT types.

### Expanded WTAX DAT Generation

When `DAT Type` is `Expanded WTAX`, the screen shows:

- `Type of Report`
- `Known Company`
- `Company TIN`
- `Branch Code`

### Quarterly DAT Generation

When `Quarterly` is selected:

- The screen shows the existing `Reporting Month` dropdown.
- The app lists available months for the selected withholding agent.
- The app validates rows before download.
- The app generates the existing 1601EQ/QAP DAT format.

Current filename format:

```text
{TIN}{BRANCH_CODE}{MMYYYY}1601EQ.DAT
```

Example:

```text
00879197600000720261601EQ.DAT
```

### Annual DAT Generation

When `Annual` is selected:

- The screen shows `Start Date`.
- The screen shows `End Date`.
- The app validates that the date range is complete and valid.
- The app does not generate an annual DAT file yet.

Current guarded message:

```text
Annual Expanded WTAX DAT generation is not enabled yet. Confirm the annual BIR DAT layout for {START_DATE} to {END_DATE} before generating a file.
```

This guard is intentional. The current validated generator is for 1601EQ/QAP. Annual output must not reuse the monthly 1601EQ filename or body format unless the BIR annual format is confirmed.

## Backend Fields

Expanded WTAX requests can now include:

```text
report_type = quarterly | annual
start_date
end_date
```

Backward compatibility:

- If `record_type = expanded` and `report_type` is missing, the backend treats it as `quarterly`.
- Existing monthly requests using only `reporting_month` still work.

## Preflight Checks

Class:

- `app/Imports/ExpandedWtaxUploadPreflight.php`

Methods:

| Method | Used By | Purpose |
| --- | --- | --- |
| `check()` | Quarterly upload | validates required columns and selected month |
| `checkRange()` | Annual upload | validates required columns and selected date range |

Both methods run before existing rows are deleted, so a rejected upload does not erase already imported rows.

## Import Behavior

Class:

- `app/Imports/ExpandedWtaxImport.php`

New behavior:

- The importer can use the selected reporting month, which is the Quarterly behavior.
- The importer can use each workbook row date, which is the Annual behavior.

Constructor flag:

```php
new ExpandedWtaxImport($period, $withholdingAgent, true)
```

When the third argument is `true`, the importer stores each row under the end of the month from the row's own date.

## Files Changed

Frontend:

- `resources/js/Pages/RecordEntry.jsx`
- `resources/js/Pages/GenerateDatFile.jsx`

Backend:

- `app/Http/Controllers/VatInputController.php`
- `app/Http/Controllers/DatFileController.php`
- `app/Imports/ExpandedWtaxImport.php`
- `app/Imports/ExpandedWtaxUploadPreflight.php`

Tests:

- `tests/Feature/ExpandedWtaxImportTest.php`
- `tests/Feature/ExpandedWtaxDatFileTest.php`

Planning:

- `Docs/Expanded/EXPANDED_WTAX_ANNUAL_QUARTERLY_PLAN.md`

## Verification

Focused Expanded WTAX tests:

```bash
php artisan test tests/Feature/ExpandedWtaxImportTest.php tests/Feature/ExpandedWtaxDatFileTest.php tests/Feature/ExpandedWtaxConsolidationTest.php tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php tests/Unit/BirExpandedWtaxRowValidatorTest.php
```

Current verified result:

```text
108 passed (821 assertions)
```

Frontend build was not verified in this environment because `npm` is not available in the current PowerShell path.

## Still Needed Before Annual DAT Output

Confirm the official annual BIR DAT details:

- annual form type
- filename rule
- header record
- detail record
- control record
- date fields
- consolidation rules
- accepted Excel source layout

After those are confirmed, implement annual DAT generation in a separate generator instead of changing the current 1601EQ/QAP generator.
