# Expanded WTAX zero amounts for DM matching

Status: Implemented on 2026-10-15. Verification details below.

## Goal

Import company rows with valid TINs and an explicitly entered zero tax amount so they remain available as source records for DM deductions.

## Current findings

- `ExpandedWtaxRowMapper::mapSystemRow()` skips zero rate cells. Blank cells and explicit zeros currently both parse to zero.
- The system layout identifies the tax rate by the amount column: 1%, 2%, 5%, 10%, or 15%.
- DM source queries filter by withholding agent, branch, month, quarterly report type, and tax rate. Matching then uses normalized supplier name and checks TIN/group ambiguity.
- DM allocation already permits a zero source to become negative and returns a confirmable negative-balance warning.
- BIR row validation allows zero income/payment amounts but requires a positive tax rate and valid ATC and identity.

## Proposed workbook rule

An explicit numeric zero (`0`, `00`, `0.00`, or a calculated numeric zero) in a rate column means an intentional record for that rate. A blank cell remains unused.

Example: valid TIN + company name + `0.00` under 2% creates one record with 2% rate, zero income payment, zero withheld tax, and the existing configured ATC for that rate/company type.

Zero amount does not mean zero tax rate. A zero only in the Total column cannot identify a rate. For the proposed workflow, enter the zero in the intended rate column. A row whose rate cells are all blank continues to be skipped. Explicit zeros in multiple rate columns intentionally create multiple rate records; this must be explained in upload guidance because some spreadsheets fill unused cells with zeros.

## Implementation steps

1. Update system-row mapping to retain the raw cell value and distinguish blank, explicit numeric zero, and nonzero amounts. Preserve current nonzero mapping and calculations. Do not turn malformed text or a dash into an intentional zero record.
2. Require both company name and valid TIN for the newly supported zero company rows. Report missing/invalid identity as workbook issues before replacement, rather than silently discarding an intended zero record. Keep existing individual-row behavior outside this change.
3. Keep mapping and preflight aligned. Validate the selected rate's existing ATC mapping and the ordinary period/identity rules for each zero record. Keep BIR-layout behavior intact and cover its existing zero support with a regression test.
4. Verify zero records appear in Expanded records with their rate and zero amounts, and remain available to DM review within the correct scope. Change DM services only if verification reveals a zero-specific blocker.
5. Retain negative-balance acknowledgment, preview-before-apply, cancel behavior, duplicate-DM protection, and existing re-upload/reset lifecycle. Show the zero starting balance and negative result in the current DM review UI.
6. Add brief upload guidance: enter `0.00` in the intended rate column; leave unused rate cells blank. No database migration or historical backfill is expected.

## Acceptance checks

- Numeric zero, text `00`/`0.00`, and a formula evaluating to zero import at the intended rate with valid company/TIN.
- Blank unused columns produce no extra records; positive and negative amount behavior stays unchanged.
- Multiple explicit zeros produce only the explicitly selected rate records.
- Missing name, invalid/missing TIN, invalid numeric text, missing ATC mapping, and wrong period are rejected before any existing records are replaced.
- A workbook containing only valid explicit-zero company rows persists those rows.
- At 2%, a zero source with a DM debit of 100 previews tax withheld of -100 and income payment of -5,000. Confirmation saves it; cancel leaves the source unchanged.
- A different rate, company, branch, or month cannot use that zero source. Existing ambiguous-match handling remains effective.
- Re-upload/reset and duplicate-reference tests pass with zero sources.
- Quarterly/annual upload regression tests and relevant DM tests pass. DAT format, field order, and unrelated Purchase/Sales flows stay unchanged; check zero and adjusted-negative records through existing DAT validation/generation.

## Scope

Primary files: `ExpandedWtaxRowMapper.php`, `ExpandedWtaxBirInfoPreflight.php`, relevant upload guidance, `ExpandedWtaxImportTest.php`, `ExpandedWtaxUploadBirInfoValidationTest.php`, and `ExpandedWtaxDmUploadTest.php`. Inspect related services for verification; avoid unnecessary service changes.

This plan chooses explicit zeros in rate columns as the rate-selection mechanism. Supporting entirely blank amount rows or zeros only in Total would require an additional explicit rate-selection design before implementation.

## Implementation and verification

- System mapping now retains explicitly numeric company zeros, while blank cells, malformed text, and existing individual-zero behavior remain unchanged. Existing preflight automatically validates newly mapped zero records, so no preflight/service changes were necessary.
- Added upload guidance explaining rate selection and blank unused columns.
- Added CSV/XLSX coverage for numeric/text/formula zeros, multiple rates, blank columns, invalid identity/numeric data, missing ATC mapping, period rejection, annual dates, and existing BIR-layout zero support.
- Added an actual system-upload-to-DM test at 2%, including scope isolation, negative preview, acknowledgment requirement, persistence, duplicate-reference protection, and re-upload/reset.
- Focused upload, DM, DAT, and validator run: 181 passed, 1 failed (1,587 assertions). The existing `test_the_worksheet_row_counts_a_title_row_above_the_headings` fails because `uploadIssueDialog` is absent. Reproduced independently by loading the original HEAD mapper through a temporary PHPUnit bootstrap; this failure predates the zero change and remains outside this implementation.
- RecordEntry JSX bundle check and `git diff --check` passed. Browser interaction and a full production frontend build were not run.
- No database migration, production upload, historical-data edits, DM service changes, or DAT serializer changes were performed.
