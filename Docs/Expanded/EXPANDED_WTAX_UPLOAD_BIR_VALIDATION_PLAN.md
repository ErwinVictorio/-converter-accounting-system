# Expanded WTAX: Detect BIR Errors During Upload

Status: Planned only — not implemented.
Date: 2026-09-10

## Objective

Detect Expanded WTAX BIR errors during upload, before importing or replacing records, using the same reject-and-show-issues flow as Sales. For example, a payee TIN with fewer than nine digits must reject the upload immediately instead of first appearing as an error on Generate DAT.

Apply this to Quarterly and Annual uploads, for both supported workbook layouts: BIR Schedule and system export.

## Current behavior verified in code

- `app/Http/Controllers/VatInputController.php`: Sales calls `UploadBirInfoPreflight::checkSales()` before its replacement transaction and returns `uploadIssueDialog` when issues exist. Expanded calls `ExpandedWtaxUploadPreflight::check()` or `checkRange()` before its transaction, but does not run equivalent per-row BIR checks there.
- `app/Imports/ExpandedWtaxUploadPreflight.php`: checks required columns, company-name/TIN conflicts, and reporting month or date range. Its company identity check excludes short TINs from conflict detection; it does not reject those TINs as invalid.
- `app/Imports/ExpandedWtaxImport.php`: creates entries from workbook rows. BIR rows use the supplied ATC and amounts; system export rows expand nonzero rate columns into separate entries using existing ATC mapping and income derivation.
- `app/Services/BIR/BirExpandedWtaxRowValidator.php`: already validates TIN, payee identity, names, branch, ATC, rate, amounts, and amount consistency.
- `app/Http/Controllers/DatFileController.php`: runs that validator against saved rows to display issues and block DAT generation.
- `tests/Feature/ExpandedWtaxImportTest.php`: explicitly expects a blank ATC to be stored and then block DAT. This expectation must change for the upload endpoint.

## Required behavior

1. Keep existing request, workbook layout, period, annual full-year, and company/TIN conflict checks.
2. Read and validate every importable row before any database deletion or insertion.
3. If any blocking issue exists, reject the entire upload. Existing records must remain unchanged; do not partially import valid rows.
4. Display the worksheet row number, payee name, field, and clear correction message in the upload issue dialog.
5. Tell the user to correct the workbook and upload again. Expanded payee data comes from the workbook; do not send users to Customers or Suppliers by default.
6. If all checks pass, use the existing scoped transaction and replacement behavior.
7. Keep Generate DAT validation as a safeguard for older records and other entry paths.

Example dialog:

> Expanded WTAX upload needs BIR info fixes
>
> 8 issues found. No records were imported or replaced.
>
> Row 16 — BANSIL, ANNIE — Payee TIN must contain at least 9 digits.
>
> Correct the listed fields in the workbook and upload again.

The example row number is illustrative. Implementation must use actual worksheet positions, including header and guide rows, rather than saved-record or consolidated-row indexes. Show issue count separately from affected row count when multiple errors belong to one row.

## Validation scope

Reuse the current Expanded validator rules, including:

- Payee TIN: sufficient digits and not `000000000`; preserve existing base-TIN and branch-suffix handling.
- Required payee identity: company or individual, appropriate required names, and mutually exclusive company/individual name columns.
- Branch code under the existing importer normalization and validator rules.
- Existing name length and comma/ampersand checks on the normalized BIR row. This plan does not extend the Sales text-validation relaxation to Expanded.
- ATC: required and compliant with the existing allowed-code, payee-type, and rate rules.
- Tax rate and monetary values: existing numeric and rate rules and current amount-consistency tolerance.

Preserve legitimate negative reversals and optional middle names. Do not invent replacement TINs, ATCs, names, rates, or amounts to make invalid rows pass.

Review raw numeric cells before normalization where the importer currently converts malformed text to a number. Reject malformed nonblank numeric input instead of allowing conversion to hide it. Preserve existing accepted numeric formatting, formula evaluation, and blank-cell semantics; do not make optional blank system rate columns required.

## Implementation approach

### 1. Share row mapping between preflight and import

Extract the existing workbook-to-entry mapping into a narrowly scoped, side-effect-free helper, with a proposed name of `ExpandedWtaxRowMapper` under `app/Imports`.

- Preserve layout detection, heading mapping, skipped rows, name normalization, TIN normalization, branch defaults, formula handling, ATC selection, rounding, and report-period assignment.
- Return the same entry attributes the importer currently persists, together with source worksheet row and, for system exports, source rate column.
- Let the importer persist mapped entries and let preflight inspect mapped entries without saving them.
- Validate the same BIR representation used by `ExpandedWtaxEntry::toBirExpandedRow()`, using an unsaved model if needed to preserve casts and normalization. Confirm this path has no database writes.
- Inspect raw numeric cells before lossy conversion. Keep this validation separate from the mapping arithmetic.

This avoids maintaining two divergent sets of import calculations and row-skipping rules. Do not validate by importing into the database and rolling back.

### 2. Add Expanded upload BIR preflight

Add a focused service, proposed as `app/Imports/ExpandedWtaxBirInfoPreflight.php`, that uses the shared mapper and `BirExpandedWtaxRowValidator`.

- Run after the existing structural and period preflight succeeds.
- Validate every mapped entry, including every applicable rate column of a system export row.
- Produce structured issues compatible with the existing upload dialog: worksheet row, name, field, message, and workbook correction guidance.
- Deduplicate identical identity errors for multiple entries generated from one worksheet row. Retain rate-column context for distinct ATC or amount errors.
- Do not truncate validation to only the first displayed errors. Any presentation limit must retain the true issue count and make omitted issues clear.

### 3. Connect both controller branches

In `VatInputController::import()`, call the new BIR preflight for both Annual and Quarterly Expanded uploads after existing preflight checks and before `DB::transaction()`.

On failure, return an error and `uploadIssueDialog` with `record_type: expanded`, structured issues, and the explicit statement that no records were imported or replaced. On success, preserve current company, branch, report-type, and period replacement boundaries.

### 4. Reuse the upload issue UI

Inspect and adapt `resources/js/Pages/RecordEntry.jsx` only where needed:

- Reuse its dialog and copy-issues behavior.
- Use Expanded labels and workbook correction guidance.
- Avoid the current Customers/Suppliers navigation for Expanded workbook issues; omit `fix_route` for these issues.
- Preserve Sales and Purchase dialog behavior.

`HandleInertiaRequests.php` already exposes `uploadIssueDialog`; change it only if an actual compatibility gap is found.

## Files expected to change during implementation

| File | Purpose |
| --- | --- |
| `app/Imports/ExpandedWtaxImport.php` | Consume shared row mapping while preserving valid import output. |
| `app/Imports/ExpandedWtaxRowMapper.php` (proposed) | Share pure row mapping and source context. |
| `app/Imports/ExpandedWtaxBirInfoPreflight.php` (proposed) | Validate mapped rows and produce structured upload issues. |
| `app/Http/Controllers/VatInputController.php` | Reject invalid uploads before both replacement transactions. |
| `resources/js/Pages/RecordEntry.jsx` | Support Expanded correction guidance if the existing generic rendering needs changes. |
| `tests/Feature/ExpandedWtaxImportTest.php` | Update old acceptance expectations and cover atomic rejection. |
| `tests/Feature/ExpandedWtaxUploadBirInfoValidationTest.php` (proposed) | Focused upload validation and dialog cases. |

Existing Expanded preflight, model conversion, and validator should remain authoritative. Adjust them only if required for shared mapping compatibility; do not globally relax their validation rules.

## Verification plan

- Reject short, blank, and all-zero TINs during upload and include correct worksheet row/name details.
- Cover invalid identity/name combinations, blank or invalid ATC, ATC/type/rate mismatch, malformed numeric input, and inconsistent amounts.
- Verify both Annual and Quarterly, and both supported workbook layouts.
- Seed existing records, upload a mixture of valid and invalid rows, and prove no records were inserted, deleted, or changed. Verify other companies, branches, periods, and report types remain unchanged.
- Confirm a corrected reupload succeeds and replaces only its intended scope.
- Confirm valid mapped attributes and imported totals match existing behavior, including formula cells, multiple system rate columns, zero/blank rate columns, branch suffixes, and negative reversals.
- Retain required-column, period mismatch, company/TIN conflict, and annual full-year regression coverage.
- Update the blank-ATC upload test to expect rejection. Retain separate DAT tests using seeded invalid legacy records to prove generation still blocks them.
- Run relevant Expanded import, validation, consolidation, and DAT tests, plus existing Sales/Purchase upload tests after any shared UI changes.
- Manually verify the issue dialog and copy action. Run the frontend build if UI code changes and tooling is available; explicitly report any unavailable checks.

## Boundaries and acceptance criteria

The work is complete when an invalid Expanded workbook is rejected during upload with actionable row-level errors, existing records remain intact, and valid uploads preserve their current results for both report types and layouts.

Do not change DAT generators, file format, field ordering, headers, delimiters, line endings, filenames, calculations, rounding, consolidation, attachment output, or historical stored records. No automatic cleanup or backfill is included.

### Mandatory scope restrictions

- **Huwag galawin ang format ng DAT file.** Preserve the exact existing layouts for all DAT types, including field counts and order, headers, delimiters, line endings, filenames, totals, calculations, and rounding. DAT generator files are outside the implementation scope.
- **Huwag galawin ang ibang files na hindi directly related sa Expanded WTAX upload validation.** Limit edits to the necessary upload preflight, shared import mapping, controller integration, upload issue dialog, focused tests, and this plan. The expected-files list is not permission to modify files that do not need changes.
- Do not perform unrelated refactoring, formatting, renaming, cleanup, dependency upgrades, or changes to other modules. Preserve unrelated behavior within shared files, including Sales and Purchase upload flows.
- Leave DAT attachment and PDF files, including `DatAttachmentReportBuilder.php`, unchanged.
- Before completing implementation, review the diff to confirm every changed file and hunk is necessary for this upload-validation task and that no DAT format or unrelated changes were introduced.

This document authorizes no implementation by itself. Only this Markdown plan has been created; application behavior remains unchanged.
