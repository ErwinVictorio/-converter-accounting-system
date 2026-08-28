# Expanded WTAX Annual and Quarterly Report Type Plan

## Implementation Note

Status: implemented.

Implemented:

- `Import Data` shows `Type of Report` when `Expanded WTAX` is selected.
- `Quarterly` keeps the existing reporting-month upload behavior.
- `Annual` upload accepts `Start Date` and `End Date`, validates the range, and imports rows under each row's own month.
- Annual upload stores rows with `report_type = annual`, giving the records table and Generate screen an indicator.
- `Generate DAT File` shows the same `Type of Report` choice for Expanded WTAX.
- `Generate DAT File` shows the count of annual records found for the selected annual covered period.
- `Generate DAT File` enables Annual download when annual rows exist and pass validation.
- `Quarterly` DAT generation keeps the existing 1601EQ/QAP output.
- `Annual` DAT generation emits the confirmed 1604E Schedule 3 output.
- Annual generation uses a separate generator from Quarterly.

Superseded on one point: this plan speaks of an arbitrary selected date range for Annual. Annual Expanded WTAX requires a full taxable year. Select January 1 as Start Date and December 31 of the same year as End Date. A shorter or cross-year period is refused on both upload and download — see `Docs/Expanded/EXPANDED_WTAX_ANNUAL_FULL_YEAR_VALIDATOR_PLAN.md` and `app/Services/BIR/AnnualCoveredPeriodValidator.php`. Read "selected date range" below as that taxable year.

## Goal

Add a `Type of Report` choice only when `Expanded WTAX` is selected on:

- `Import Data`
- `Generate DAT File`

The Expanded WTAX flow will support two report modes:

- `Quarterly`
- `Annual`

`Quarterly` keeps the current behavior. `Annual` adds a start date and end date so the user can upload or generate using a custom covered period.

## Current Behavior

Expanded WTAX currently works as a month-scoped 1601EQ/QAP flow:

- Upload screen uses `Reporting Month`.
- Generate DAT screen uses `Reporting Month`.
- Upload replacement is scoped by:
  - withholding agent TIN
  - withholding agent branch code
  - reporting month
- DAT generation filters one selected month.
- Filename remains:

```text
{TIN}{BRANCH_CODE}{MMYYYY}1601EQ.DAT
```

## Target UI Behavior

### Import Data Screen

File:

- `resources/js/Pages/RecordEntry.jsx`

When `File Type` is not `Expanded WTAX`:

- Keep the current `Reporting Month` field.
- No annual/quarterly choice is shown.

When `File Type` is `Expanded WTAX`:

- Replace the visible `Reporting Month` field with `Type of Report`.
- Show choices:
  - `Quarterly`
  - `Annual`

If `Quarterly` is selected:

- Show the existing `Reporting Month` field.
- Keep the current upload behavior exactly the same.
- Existing Expanded WTAX workbook validation still requires the row `Date` or `Reporting_Month` to match the selected month.

If `Annual` is selected:

- Show:
  - `Start Date`
  - `End Date`
- Hide `Reporting Month`.
- Validate that:
  - start date is required
  - end date is required
  - end date is not before start date
  - both dates are within the same filing year, unless business confirms multi-year annual files are allowed
- The uploaded workbook rows must fall within the selected start/end range.

### Generate DAT File Screen

File:

- `resources/js/Pages/GenerateDatFile.jsx`

When `DAT Type` is not `Expanded WTAX`:

- Keep the current `Reporting Month` field.
- No annual/quarterly choice is shown.

When `DAT Type` is `Expanded WTAX`:

- Replace the visible `Reporting Month` field with `Type of Report`.
- Show choices:
  - `Quarterly`
  - `Annual`

If `Quarterly` is selected:

- Show the existing available-period dropdown.
- Keep the current 1601EQ/QAP download behavior exactly the same.

If `Annual` is selected:

- Show:
  - `Start Date`
  - `End Date`
- Generate from Expanded WTAX rows inside the selected date range and selected withholding agent.
- Do not use the existing 1601EQ monthly filename until the annual BIR output format is confirmed.

## Backend Plan

### 1. Request Fields

Add these request fields for Expanded WTAX only:

```text
report_type = quarterly | annual
start_date
end_date
```

For non-Expanded uploads/downloads, keep existing `reporting_month` / `period` behavior unchanged.

### 2. Upload Controller

File:

- `app/Http/Controllers/VatInputController.php`

Validation rules:

- `report_type` is required when `record_type = expanded`.
- `report_type` must be `quarterly` or `annual`.
- For `quarterly`, require `reporting_month`.
- For `annual`, require `start_date` and `end_date`.

Quarterly upload:

- Keep the current logic:
  - parse `reporting_month` to month end
  - preflight workbook by month
  - delete and replace rows for the selected company/month
  - import using `ExpandedWtaxImport`

Annual upload:

- Add annual preflight support that checks workbook rows against the selected date range.
- Decide whether stored rows keep their original row date/month or use a single annual period marker.
- Replacement must be scoped by:
  - withholding agent TIN
  - withholding agent branch code
  - report type
  - selected annual start/end date or filing year

### 3. Download Controller

File:

- `app/Http/Controllers/DatFileController.php`

Validation rules:

- `report_type` is required when `record_type = expanded`.
- For `quarterly`, require `period`.
- For `annual`, require `start_date` and `end_date`.

Quarterly download:

- Keep current `downloadExpanded()` behavior.
- Keep current period list logic in `expandedPeriods()`.
- Keep current `ReliefExpandedWtaxDatGenerator` output.

Annual download:

- Add a separate method, for example:

```text
downloadExpandedAnnual()
```

- Filter rows by selected withholding agent and date range.
- Validate rows before generation.
- Consolidate using the annual filing rules confirmed by the annual DAT format.
- Use a separate annual generator instead of changing the current 1601EQ generator.

## Data Model Plan

Preferred low-risk path:

- Keep existing `expanded_wtax_entries.reporting_period` for quarterly/monthly rows.
- Add nullable annual metadata only if needed:

```text
report_type
period_start
period_end
```

Recommended values:

```text
report_type = quarterly
period_start = selected month start
period_end = selected month end
```

For annual rows:

```text
report_type = annual
period_start = selected start date
period_end = selected end date
```

If annual upload reuses the same rows already uploaded monthly, do not duplicate rows. Instead, generate annual output from existing quarterly/monthly stored rows by date range.

## Important Format Guardrail

Do not change the current 1601EQ/QAP output while adding this UI.

Before annual DAT generation is enabled, confirm the exact annual BIR format:

- annual form type
- header/detail/control records
- filename rule
- date fields
- required consolidation key
- whether the source is the system Expanded WTAX export, the BIR Schedule template, or another annual template

The old `1604E` sample in `Docs/Expanded/0087919760000123120251604E.dat` should not automatically replace the current 1601EQ/QAP format without fresh validation evidence.

## Implementation Steps

1. Update the Expanded WTAX upload UI to show `Type of Report`.
2. Keep `Quarterly` wired to the current `Reporting Month` flow.
3. Add `Annual` UI with `Start Date` and `End Date`.
4. Update upload validation in `VatInputController`.
5. Add annual preflight checks without changing quarterly preflight behavior.
6. Update the Generate DAT UI with the same `Type of Report` behavior.
7. Update download validation in `DatFileController`.
8. Keep quarterly generation on the existing `downloadExpanded()` method.
9. Add a separate annual generation path after the annual BIR layout is confirmed.
10. Add focused tests for quarterly compatibility and annual date-range validation.

## Test Coverage

Minimum tests:

- Expanded upload defaults to `Quarterly` and still accepts the current monthly workflow.
- Quarterly upload still rejects rows outside the selected month.
- Annual upload requires start/end dates.
- Annual upload rejects an end date before the start date.
- Annual upload rejects rows outside the selected date range.
- Generate DAT quarterly still downloads the same 1601EQ/QAP layout.
- Generate DAT annual does not call the 1601EQ generator until the annual layout is implemented.

## Open Questions

1. Is `Annual` definitely the 1604E annual alphalist DAT, or another BIR file type?
2. Should annual generation use rows already uploaded monthly, or a separate annual Excel upload?
3. For annual upload, should re-upload replace the whole selected date range, the whole year, or only exact matching start/end dates?
4. Should `Quarterly` label still ask for `Reporting Month`, or should the UI display quarter labels while storing the selected month internally?
