# Expanded WTAX Annual Full-Year Validator Plan

## Implementation Status

Status: implemented.

- `app/Services/BIR/AnnualCoveredPeriodValidator.php` holds the rule, with `isFullTaxableYear()` and an `errors()` array keyed `start_date` / `end_date`.
- `VatInputController::store()` refuses a partial period before the workbook is read and before any stored annual row is deleted.
- `DatFileController::download()` refuses it before `downloadExpandedAnnual()`, so no file is written and nothing is widened to `12/31`.
- Both controllers' older "must stay inside one filing year" cross-year checks were removed as redundant; a cross-year period now gets the single canonical message.
- `resources/js/lib/annualCoveredPeriod.js` mirrors the rule for `RecordEntry.jsx` and `GenerateDatFile.jsx`, which show the hint, show the error in its place, and disable the annual download.
- `tests/Unit/AnnualCoveredPeriodValidatorTest.php` covers the rule; upload and generation refusals are covered in `tests/Feature/ExpandedWtaxImportTest.php` and `tests/Feature/ExpandedWtaxDatFileTest.php`.

## Goal

Add a validator so `Annual` Expanded WTAX upload and DAT generation only accept one exact taxable year.

Valid annual covered period:

```text
Start Date: 01/01/YYYY
End Date:   12/31/YYYY
```

Example:

```text
Start Date: 01/01/2026
End Date:   12/31/2026
Tax Year:   2026
```

Invalid covered periods:

```text
01/01/2026 - 07/31/2026
02/01/2026 - 12/31/2026
01/01/2026 - 01/31/2027
```

## Reason

The BIR 1604E validator follows the taxable year entered in the validation screen. For a Tax Year of `2026`, the Annual file should represent the full taxable year ending `12/31/2026`.

The app should not silently adjust a partial covered period into a full-year DAT file. If the user selects less than one full year, the app should show an error and stop the upload or download.

## Target Rule

When `report_type = annual`, the covered period must pass all of these checks:

- `start_date` is required.
- `end_date` is required.
- `start_date` and `end_date` must be in the same calendar year.
- `start_date` must be January 1 of that year.
- `end_date` must be December 31 of that same year.
- `end_date` must not be earlier than `start_date`.

Recommended error message:

```text
Annual Expanded WTAX must cover one full taxable year: January 1 to December 31 of the same year.
```

## Upload Validation Plan

File:

- `app/Http/Controllers/VatInputController.php`

Add the full-year check after request validation parses annual `start_date` and `end_date`.

Current annual validation only checks:

- dates are required
- end date is after or equal to start date
- dates are inside one filing year

Change it to reject partial annual periods before preflight and before deleting existing rows.

Important behavior:

- If annual upload is invalid, do not delete existing annual rows.
- `ExpandedWtaxUploadPreflight::checkRange()` should only run after the selected period is already confirmed as a full taxable year.
- Workbook row dates still must fall inside the selected full-year range.

## DAT Generation Validation Plan

File:

- `app/Http/Controllers/DatFileController.php`

Add the same full-year check before `downloadExpandedAnnual()`.

Current annual download validation only checks:

- dates are required
- end date is after or equal to start date
- dates are inside one filing year

Change it so Annual DAT download is blocked unless the selected covered period is exactly:

```text
YYYY-01-01 to YYYY-12-31
```

Important behavior:

- Do not auto-convert `01/01/2026 - 07/31/2026` into `01/01/2026 - 12/31/2026`.
- Do not generate Annual DAT for partial-year rows.
- The generated 1604E file should use the selected `end_date`, which must already be `12/31/YYYY`.

## Frontend Plan

Files:

- `resources/js/Pages/RecordEntry.jsx`
- `resources/js/Pages/GenerateDatFile.jsx`

Add user-facing guidance when `Annual` is selected:

```text
Annual filing must cover one full taxable year: January 1 to December 31.
```

Client-side checks should block obvious invalid dates before submit/download:

- missing start date
- missing end date
- different years
- start date is not January 1
- end date is not December 31

Backend validation remains the source of truth.

## Suggested Helper

To avoid duplicating the rule, add a private helper in both controllers or a shared small service:

```text
isFullTaxableYear(startDate, endDate)
```

Expected logic:

```text
same year
start month/day = 01/01
end month/day = 12/31
```

If using a shared service, suggested file:

```text
app/Services/BIR/AnnualCoveredPeriodValidator.php
```

The service can return:

- `true` / `false`
- or an error message array for controller validation

## Test Plan

### Upload Tests

File:

- `tests/Feature/ExpandedWtaxImportTest.php`

Add tests:

- Annual upload accepts `2026-01-01` to `2026-12-31`.
- Annual upload rejects `2026-01-01` to `2026-07-31`.
- Annual upload rejects `2026-02-01` to `2026-12-31`.
- Annual upload rejects cross-year dates like `2026-01-01` to `2027-01-31`.
- Rejected annual upload does not delete existing annual rows.

### Generate Tests

File:

- `tests/Feature/ExpandedWtaxDatFileTest.php`

Add tests:

- Annual generation accepts `2026-01-01` to `2026-12-31`.
- Annual generation rejects `2026-01-01` to `2026-07-31`.
- Annual generation rejects `2026-02-01` to `2026-12-31`.
- Annual generation rejects cross-year dates.
- Annual DAT filename and header use the selected `12/31/YYYY` end date.

### Regression Tests

Keep these passing:

- Quarterly Expanded WTAX upload still accepts selected reporting month.
- Quarterly 1601EQ DAT output is unchanged.
- Annual 1604E generator still matches the annual sample layout.

## Documentation Updates After Implementation

Update:

- `Docs/Expanded/EXPANDED_WTAX_ANNUAL_QUARTERLY_GUIDE.md`
- `Docs/Expanded/EXPANDED_WTAX_FORMAT_GUIDE.md`
- `Docs/Expanded/EXPANDED_WTAX_1604E_ANNUAL_DAT_PLAN.md`

Required wording:

```text
Annual Expanded WTAX requires a full taxable year. Select January 1 as Start Date and December 31 of the same year as End Date.
```

Also remove wording that suggests Annual can be generated from a partial selected covered period.
