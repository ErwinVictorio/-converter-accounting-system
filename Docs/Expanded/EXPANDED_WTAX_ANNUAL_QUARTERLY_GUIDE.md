# Expanded WTAX Annual and Quarterly Guide

This document describes the implemented Annual and Quarterly report-type changes for Expanded WTAX upload and DAT generation.

## Summary

Expanded WTAX now has a `Type of Report` selector in both workflows:

- `Import Data`
- `Generate DAT File`

The available report types are:

- `Quarterly`
- `Annual`

`Quarterly` is the existing 1601EQ/QAP monthly workflow. `Annual` is the 1604E Schedule 3 workflow using the confirmed annual template in `Docs/Expanded/Anual-format/1604E_Schedule_3_template.xls`.

Uploaded rows now carry a stored `report_type` value, so the app can tell whether a row came from a Quarterly upload or an Annual upload.

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
- `start_date` must be January 1 and `end_date` must be December 31 of that same year.
- Every workbook row date must fall inside the selected taxable year.

Annual Expanded WTAX requires a full taxable year. Select January 1 as Start Date and December 31 of the same year as End Date.

A partial covered period is refused, not widened:

```text
01/01/2026 - 07/31/2026   rejected
02/01/2026 - 12/31/2026   rejected
01/01/2026 - 01/31/2027   rejected
01/01/2026 - 12/31/2026   accepted
```

Rejected message:

```text
Annual Expanded WTAX must cover one full taxable year: January 1 to December 31 of the same year.
```

The rule lives in `app/Services/BIR/AnnualCoveredPeriodValidator.php` and runs before the workbook is read and before any stored annual row is deleted, so a rejected upload leaves the rows already on file exactly as they were.

Accepted workbook date columns:

| Layout | Date Column Checked |
| --- | --- |
| BIR 1601EQ Schedule 1 Template | `Reporting_Month` |
| System Expanded WTAX Export | `Date` |

Storage behavior:

- Annual rows are saved with `report_type = annual`.
- Annual upload does not store every row under one annual marker.
- Each imported row is stored under the month from its own workbook date.
- Example: a row dated `07/03/2026` is stored as `2026-07-31`; a row dated `08/04/2026` is stored as `2026-08-31`.

Replacement scope:

- withholding agent TIN
- withholding agent branch code
- months covered by the selected taxable year

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
- The screen shows whether annual Expanded WTAX rows exist for the selected covered period.
- The app validates that the covered period is one full taxable year.
- The app validates annual rows before download.
- The app generates a 1604E Schedule 3 DAT file.

Annual Expanded WTAX requires a full taxable year. Select January 1 as Start Date and December 31 of the same year as End Date.

The screen states the rule under the date fields, and the download button stays disabled while the covered period is not one full taxable year:

```text
Annual filing must cover one full taxable year: January 1 to December 31.
```

A partial covered period is refused rather than widened into a full-year file:

```text
Annual Expanded WTAX must cover one full taxable year: January 1 to December 31 of the same year.
```

When annual rows exist, the screen shows:

```text
{COUNT} annual expanded withholding tax rows found for the selected covered period.
```

When no annual rows exist, the screen shows:

```text
No annual expanded withholding tax records found for the selected covered period.
```

Annual filename format:

```text
{TIN}{BRANCH_CODE}{MMDDYYYY}1604E.dat
```

Example:

```text
0087919760000123120261604E.dat
```

The selected taxable year decides which rows are included: January 1 to December 31 of that year. The DAT period end date is that same `12/31/YYYY`, so the file matches the Tax Year entered in the 1604E validator.

Annual record body:

```text
H1604E,{TIN},{BRANCH},{MM/DD/YYYY}
D3,1604E,{TIN},{BRANCH},{MM/DD/YYYY},{SEQ},{PAYEE_TIN},{PAYEE_BRANCH},{COMPANY},{SURNAME},{FIRST},{MIDDLE},{ATC},{INCOME},{RATE},{TAX}
C3,1604E,{TIN},{BRANCH},{MM/DD/YYYY},{TOTAL_TAX}
```

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

Database indicator:

```text
expanded_wtax_entries.report_type
```

Stored values:

| Value | Meaning |
| --- | --- |
| `quarterly` | row belongs to the existing 1601EQ/QAP quarterly/monthly flow |
| `annual` | row came from an Annual upload and is only counted in the Annual flow |

The Expanded WTAX records table shows this value as a `Report Type` column.

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
- `resources/js/lib/annualCoveredPeriod.js`

Backend:

- `app/Http/Controllers/VatInputController.php`
- `app/Http/Controllers/DatFileController.php`
- `app/Imports/ExpandedWtaxImport.php`
- `app/Imports/ExpandedWtaxUploadPreflight.php`
- `app/Models/ExpandedWtaxEntry.php`
- `app/Services/BIR/AnnualCoveredPeriodValidator.php`
- `app/Services/BIR/ReliefExpandedWtaxAnnualDatGenerator.php`

Database:

- `database/migrations/2026_08_28_000000_add_report_type_to_expanded_wtax_entries_table.php`

Tests:

- `tests/Feature/ExpandedWtaxImportTest.php`
- `tests/Feature/ExpandedWtaxDatFileTest.php`
- `tests/Unit/AnnualCoveredPeriodValidatorTest.php`
- `tests/Unit/ReliefExpandedWtaxAnnualDatGeneratorTest.php`

Planning:

- `Docs/Expanded/EXPANDED_WTAX_ANNUAL_QUARTERLY_PLAN.md`
- `Docs/Expanded/EXPANDED_WTAX_ANNUAL_FULL_YEAR_VALIDATOR_PLAN.md`

## Verification

Focused Expanded WTAX tests:

```bash
php artisan test tests/Unit/AnnualCoveredPeriodValidatorTest.php tests/Feature/ExpandedWtaxImportTest.php tests/Feature/ExpandedWtaxDatFileTest.php tests/Feature/ExpandedWtaxConsolidationTest.php tests/Feature/RecordPagesTest.php tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php tests/Unit/ReliefExpandedWtaxAnnualDatGeneratorTest.php tests/Unit/BirExpandedWtaxRowValidatorTest.php
```

Current verified result:

```text
143 passed
```

`tests/Unit/ReliefExpandedWtaxAnnualDatGeneratorTest.php` carries the 1604E byte-for-byte sample check. The frontend build was verified with `npx vite build`.
