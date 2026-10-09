# (DM) Expanded Uploader Plan

Date: 2026-10-09
Current computation update: The user superseded the tax-only rule. New DM confirmations reduce both Tax Withheld and Income Payment. Income deduction is DM Debit / (rate / 100), rounded to centavos cumulatively across source allocations. Existing income values are reduced, not replaced by a recomputed balance. Both before/after amounts are stored in the audit, and old preview tokens must be refreshed. Regular imports, DAT formats, and validators are unchanged. Previously applied tax-only batches are not automatically rewritten or deducted again; one such local batch was found during read-only inspection and needs separate historical reconciliation.

Status: Simplified review and transactional confirmation implemented. Audit/reference tables migrated locally; application tests used isolated records.

## Simplified flow completed (2026-10-09)

The user's instruction to apply the simplified plan authorizes automatic applied-reference tracking. This update supersedes the preview-only/pending-persistence notes below.

- Review shows Supplier, Current Tax, DM Deduction, New Tax, and Total deduction. TIN, ATC, source counts, references, excluded PV amounts, and detailed validation messages are collapsed under View details.
- Cancel and Confirm Deduction replace the separate warning checkbox. A short DAT warning remains visible; confirmation acknowledges it without changing validation.
- Confirmation locks scoped records, rechecks the review snapshot and matching, and saves Tax Withheld updates and audit/reference history in one transaction. Income Payment remains unchanged.
- A unique company/branch/report/month/rate/reference identity prevents repeated deduction even if the workbook filename or contents change. Repeating a completed confirmation token returns success without another deduction. Expired, stale, foreign-user, and ambiguous reviews cannot write.
- `expanded_dm_applications` stores user, file digest, scope, calculation/source before-after details and total; `expanded_dm_references` stores unique applied references. No DM rows are added to `expanded_wtax_entries`.
- Normal imports retain their existing replacement behavior. Applied-reference history survives replacement: a normal re-import may restore workbook totals, but it does not silently permit old DMs to be deducted again. Reversal/reset is not exposed; any correction requires separate reconciliation using the audit history.
- Local MySQL migration created only the two new tables. No business deductions were initiated by the agent against the local business database; browser application testing used `dm-browser.sqlite`.
- Verification: 118 tests passed (1,346 assertions), including transaction rollback, repeated confirmation, reference protection after replacement, stale/expired reviews, token ownership, and existing Expanded regressions. Pint and production build passed.
- Browser checks verified collapsed details, no extra checkbox, confirmation, updated WORLD BEST 9,523.50 and ACERSTEEL 1,275.97, isolated grand total 10,799.47, duplicate re-upload rejection, and mobile viewport fit. Local screenshot: `storage/app/private/dm-simple-review.png`.
- Tax-only adjustment intentionally still triggers the existing tax/base consistency warning and may block DAT generation. Hiding or changing that validation was not part of the authorized simplification.

## Execution update (2026-10-09)

This section supersedes the earlier proposed amount policy and planning-only status below.

- User explicitly selected **Tax Withheld only**. Income Payment must remain unchanged. Existing BIR/DAT validation remains intact; resulting mismatch errors are displayed in the DM review. This means adjusted records may be blocked from DAT generation under existing validation.
- Implemented the dedicated ACCOUNT DETAILS reader, authenticated review endpoint, company/month/rate-scoped unique name-to-TIN matching, review calculations, excluded PV totals, percentage modal, error states, and cancel-safe UI.
- Proposed source allocation is now implemented in the read-only calculation: ascending source ID, exhausting positive Tax Withheld amounts without taking a source below zero. The consolidated deduction is applied exactly once in the calculation. Negative-source groups and over-balance deductions require review.
- Initial support is quarterly records for one month. Annual mode displays the limitation instead of allocating an annual deduction silently.
- The actual supplied file reads as 3 DMs totaling 2,875.65 and 60 excluded PVs with Debit totaling 119,648.62. A synthetic 25-row WORLD BEST fixture verifies 10,684.86 minus 1,161.36 equals 9,523.50.
- Browser verification used an isolated SQLite database, the actual DM workbook, and a headless Edge session. Verified percentage validation, both supplier totals, excluded PV amounts, BIR warnings, and cancellation without runtime exceptions. Screenshot: `storage/app/private/dm-review.png` (local verification artifact).
- Existing Expanded import/consolidation/DAT/record regression tests: 105 passed, 1,267 assertions. Focused DM tests: 9 passed, 54 assertions. Production frontend build, Pint, and diff whitespace check passed. Browser review was rerun after final UI changes and passed.
- **Pending required answer:** whether adjustment audit/reference metadata may be saved to prevent duplicate deductions on re-upload. The user answered the separate amount question but has not yet answered this persistence question. Apply is explicitly disabled and the apply endpoint refuses writes until that choice is settled; preview tokens are temporary cache entries, not applied business records.
- Remaining work after that answer: transactional apply, stale/consumed-token handling, agreed repeat-upload/reversal behavior, corresponding write-path tests, and browser application verification. No migration or live adjustment has run.

## Requested behavior

Add a `(DM) Expanded Uploader` based on the supplied mockup:

1. Select or drag an Excel DM workbook into the uploader.
2. Open a modal with `Enter the Percentage`, an `Upload` button, and a cancel/close action.
3. Resolve each DM row's supplier name to a verified TIN, then use that TIN and the entered percentage to find matching existing Expanded records. The supplied ACCOUNT DETAILS workbook has no TIN column; the resolution step below is a proposed addition requiring agreement.
4. Deduct the workbook's `Debit` amount from the matching record's total.
5. Do not import the DM workbook rows as new Expanded records.

The percentage is a matching value for `tax_rate`. It must not automatically multiply the Debit amount. For example, input `2` means match `2.00%` records.

## Verified Excel formats (2026-10-09)

Both supplied workbooks were read locally with PhpSpreadsheet, without importing them or accessing database records. These findings describe the files, not current database contents.

### DM source: `ACCOUNT DETAILS.xlsx`

- Location: `Docs/Expanded/ACCOUNT DETAILS.xlsx`; worksheet `Sheet1`, 67 populated-range rows, columns A:G.
- Row 1: `ACCOUNT DETAILS`; row 2: period September 1-30, 2026.
- Row 3 headers: A `Date`, B `Reference`, C `Particulars`, D `Other Info`, E `Debit`, F `Credit`, G `Balance`.
- Row 4 is an account/opening-balance line: `221101A WITHHOLDING TAX PAYABLE - EXPANDED 1%`. It is not a transaction.
- Transactions begin at row 5. There is no TIN, branch, or ATC column, and `Other Info` is blank for the sample transactions.
- The file mixes payment vouchers and debit memos. A positive Debit alone does not identify a DM.

| Excel row | Reference | Particulars | Debit |
| --- | --- | --- | ---: |
| 7 | DM (REG)#000252 | WORLD BEST IND'L. SALES, INC. | 903.28 |
| 16 | DM (REG)#000257 | ACERSTEEL INDUSTRIAL SALES, INC. | 1,714.29 |
| 59 | DM (REG)#000290 | WORLD BEST IND'L. SALES, INC. | 258.08 |

There are **3 DM rows totaling 2,875.65**. Row 6 is `PV (REG)#006689`, BUREAU OF INTERNAL REVENUE, Debit **119,648.62**; exclude it from DM deductions. Credit and Balance are never deduction inputs.

### Regular source: `EXPANDED WTAX (4).xlsx`

- Location: `Docs/Expanded/EXPANDED WTAX (4).xlsx`; worksheet `Sheet1`, 133 populated-range rows, columns A:K.
- Row 1: `EXPANDED WTAX`; row 2: period September 1-30, 2026.
- Row 3 headers: A `No`, B `Date`, C `Supplier Name`, D `TIN`, E `Reference`, F `(1%)`, G `(2%)`, H `(5%)`, I `(10%)`, J `(15%)`, K `Total`.
- Rows 4-132 contain 129 detail rows; row 133 is `TOTAL:` and must not become a payee record.
- This matches the system-export layout handled by `ExpandedWtaxRowMapper::mapSystemRow()`. Each nonzero rate column supplies `tax_withheld`; the existing mapper derives `income_payment = round(tax_withheld / (rate / 100), 2)`. `Total` is not a separate amount to deduct for each rate.
- The current mapper uses the first nine TIN digits and stores system-export payee branch `0000`. Retain this regular-import behavior; do not assume a workbook branch suffix is preserved in the stored records.
- The footer shows 1% = **23,020.78**, 2% = **8,082.97**, 5% = **892.86**, 10% = **35,827.34**, and total = **67,823.95**.

### Proposed identity resolution for the supplied DM format

1. Read `Particulars` as the supplier label and select eligible existing Expanded candidates within company/agent branch, report type, period, and entered rate.
2. Compare names using a documented normalization compatible with stored payee names. Show original and matched names in review. Do not use fuzzy matching or silently choose among collisions.
3. Resolve a unique valid payee TIN from those candidates, then perform the requested TIN + rate target lookup with the existing scope and ambiguity checks.
4. If the name is missing, ambiguous, or maps to conflicting TINs, require correction or an explicit reviewed mapping. Do not manufacture a TIN.
5. If direct TIN matching without name resolution is required, the DM export needs a TIN column or a separately approved mapping input. The current file cannot supply that value itself.

The regular sample provides a reference for this proposal; production processing should use already imported records, without requiring the regular workbook to be uploaded again. Actual database name/TIN uniqueness remains unverified.

### Conditional sample result

The account label and existing regular-import mapping support treating DM Debit as a reduction of **Tax Withheld**. This is a proposed interpretation pending confirmation, including the companion Income Payment adjustment rule.

If the supplied regular workbook represents the complete pre-adjustment balances and all three DMs are applied at 1%, the file-based illustration is:

| Supplier / scope | TIN in regular workbook | Before Tax Withheld | DM deduction | After Tax Withheld |
| --- | --- | ---: | ---: | ---: |
| WORLD BEST IND'L. SALES, INC. (25 regular rows) | 000-227-599-000 | 10,684.86 | 1,161.36 | 9,523.50 |
| ACERSTEEL INDUSTRIAL SALES, INC. (1 regular row) | 007-086-184-000 | 2,990.26 | 1,714.29 | 1,275.97 |
| Complete 1% bucket | — | 23,020.78 | 2,875.65 | 20,145.13 |
| All-rate grand total | — | 67,823.95 | 2,875.65 | 64,948.30 |

These are illustrative arithmetic results, not applied changes or proof that the regular export has not already incorporated adjustments. Confirm that the base balances are unadjusted before enabling application.

### WORLD BEST walkthrough from the user's side-by-side example

The user supplied a filtered view of WORLD BEST in both workbooks. Use this as the concrete example for the supplier-level deduction:

1. Scope: September 2026, selected withholding agent/company, and entered rate **1%**.
2. Regular Expanded supplier: `WORLD BEST IND'L. SALES, INC.`, workbook TIN `000-227-599-000` (stored base TIN `000227599`). Its 25 regular rows total **10,684.86** in the `(1%)` column.
3. ACCOUNT DETAILS supplier: the same name in `Particulars`. Select only its DM references:
   - `DM (REG)#000252`, September 9: Debit **903.28**.
   - `DM (REG)#000290`, September 30: Debit **258.08**.
4. Total supplier DM deduction: **903.28 + 258.08 = 1,161.36**.
5. Expected adjusted supplier Tax Withheld total: **10,684.86 - 1,161.36 = 9,523.50**.

Apply the combined deduction once to the eligible supplier/rate total. Do not deduct 1,161.36 from each of the 25 source rows or multiply the Debit by 1% again. Source-record allocation remains an implementation decision; this example defines the resulting group total.

The regular workbook supplies the starting amount. ACCOUNT DETAILS supplies the DM Debit adjustments only: do not add its PV Credit entries again, and do not use its running Balance as WORLD BEST's total. A filtered supplier view still displays the account's running balances, not a freshly calculated supplier balance.

The screenshot clarifies the supplier example but does not establish a rule for changing the companion Income Payment, resolving ambiguous names, or persisting adjustments. Those decisions remain listed below.

### Parser requirements established by these samples

- Detect and validate row 3 headings; use the title, covered period, and account line as context, not transaction data.
- Process references beginning with the standalone `DM` document marker (sample: `DM (REG)#...`) and a valid positive Debit. Report excluded non-DM counts/amounts separately so the BIR payment is visibly excluded.
- Reject or flag malformed DM references, invalid dates, out-of-period transactions, and invalid DM Debit values. Do not silently skip a malformed DM as if it were an ordinary voucher.
- Cross-check the entered percentage against the account section's rate when present. For this 1% sample, another entered rate must produce a visible mismatch error. Detect additional account sections and reject unsupported mixed-rate files instead of assuming all rows use the first section.
- Ignore opening balances and recognized summary rows; preserve original worksheet row numbers and DM references in review.
- Keep the dedicated DM reader separate from the regular Expanded importer: their headings, row eligibility, and persistence behavior differ.

## Current implementation evidence

| Existing file | Relevant behavior |
| --- | --- |
| `resources/js/Pages/RecordEntry.jsx` | Import Data UI; submits ordinary imports to `/vat-import` and already handles Expanded company/report/period context. |
| `app/Http/Controllers/VatInputController.php` | Normal Expanded uploads validate then replace records within the selected scope. A DM operation must not use that replacement path. |
| `app/Models/ExpandedWtaxEntry.php` | Stores `payee_tin`, `tax_rate`, `income_payment`, and `tax_withheld`; consolidation groups by month, report type, agent identity, payee name identity, ATC, and rate. |
| `app/Http/Controllers/RecordController.php` | Builds consolidated Expanded rows and summary totals before pagination. |
| `resources/js/Pages/Records/ExpandedWtaxRecords.jsx` | Displays both Income Payment and Tax Withheld, plus merged-source and conflicting-TIN indicators. |
| `app/Imports/ExpandedWtaxBirInfoPreflight.php` | Existing normal-upload BIR preflight; retain it for normal imports. |

TIN + percentage is the requested lookup, but it is not the existing consolidation key. Multiple source rows can form one displayed total, and one TIN/rate can identify multiple ATCs or periods. The DM matcher must explicitly resolve this difference.

## Decisions to settle before implementation

| Question | Proposed treatment pending confirmation |
| --- | --- |
| Which total receives the Debit: Income Payment or Tax Withheld? | Do not choose silently. The screenshot says “total” and both fields exist. Confirm the target and whether the other amount must also change. |
| Does “not saved in the database” mean no new DM records, or no database writes at all? | Working interpretation: no new DM business rows, but update the matched existing records. If it means no writes at all, implement a temporary preview/export instead; persisted totals cannot change under that interpretation. |
| How will the missing DM TIN be resolved? | Layout is now verified above. Approve the proposed unique supplier-name-to-existing-TIN resolution, or supply a TIN-bearing export/mapping. No fuzzy or first-match fallback. |
| How should one deduction affect multiple source rows in a consolidated total? | Require an agreed deterministic allocation rule; never subtract the full Debit from every source row. |
| Which report periods are eligible? | Start with an explicit company, agent branch, report type, and reporting month. Annual or multi-month allocation needs a defined rule before enabling it. |
| Can operational audit/idempotency metadata be persisted? | Recommended for duplicate-upload protection and traceability, but confirm whether it fits the no-save requirement. Do not add a DM data table by default. |

These are business decisions for implementation, not blockers to preparing this plan.

## Proposed user flow

1. Add a clearly labeled `(DM) Expanded Uploader` entry in Import Data, separate from the normal Expanded import action.
2. Require company/agent, branch, report type, and period context before processing. Reuse existing selectors where appropriate.
3. Selecting or dropping a file opens the percentage modal. Show the filename and selected context so the target scope is visible.
4. Validate the percentage visibly in the modal and again on the server. Accept decimal rates supported by the existing system; reject blank, malformed, negative, over-100, or over-precision input. Decide explicitly whether zero is allowed.
5. `Upload` parses and matches the workbook without writing records. Recommended review screen: source row, TIN, rate, target ATC/period, original total, Debit, proposed total, and match status.
6. A final `Apply Deductions` action commits a valid review. This review step is a proposed safeguard beyond the supplied mockup, particularly for consolidated records.
7. Cancel/close before application leaves all stored amounts unchanged. Disable duplicate submissions while processing.
8. On success, show affected targets and total deducted, then refresh records and rate/grand-total cards from the server.

## Matching and calculation rules

- Limit candidates by selected withholding agent TIN + agent branch + report type + period before matching payee TIN and percentage. Never search globally across companies or periods.
- Normalize valid TIN formatting without losing leading zeros. Validate length and any branch suffix explicitly; do not truncate malformed identifiers into a match.
- Compare rates as normalized decimal values: `2`, `2.0`, and `2.00` represent the same rate.
- Require one unambiguous eligible target group. Multiple ATCs, payee branches, name-based groups, or conflicting source TINs become `Needs Review`; do not pick the first row.
- Multiple source records inside one legitimate consolidated group are distinct from multiple possible target groups. Resolve source allocation separately.
- For a confirmed target amount: `new_total = current_total - sum(valid DM Debit amounts assigned to that target)`.
- Use integer centavos or exact decimal arithmetic. For example, a confirmed target total of `10,000.00` and Debit of `500.00` produces `9,500.00`; the entered percentage only locates the target.
- Validate numeric Debit values, precision, sign, and range. Proposed first version rejects negative Debit and deductions exceeding the target balance; do not clamp results to zero or take absolute values silently.
- Preserve workbook row numbers for errors. Distinguish separate legitimate DM lines from repeated document references; do not deduplicate solely because amounts and TINs match.
- Unmatched, ambiguous, invalid, or inconsistent rows produce explicit errors. Proposed first release applies nothing until the complete workbook is valid; avoid silent partial application.
- Validate the resulting Income Payment/Tax Withheld relationship with the existing BIR validator. Do not change the validator or recompute the companion amount without an agreed business rule.

## Persistence and consistency design

Under the working interpretation, update only approved existing Expanded source records. Do not create new Expanded entries, run normal import deletion/replacement, or retain the workbook as business data.

Use a short-lived server-side review token bound to the user, scope, file digest, percentage, proposed changes, and original values. At application, recheck authorization, lock target records, and verify that source values and group membership have not changed. If stale, reject and require a fresh review. Apply all approved changes in one transaction and consume the token once.

Prevent retry/double-click deductions using the consumed token. Durable protection against uploading the same workbook again requires approved persistent operation metadata or a reliable DM document identity; a temporary token alone cannot guarantee it. Define correction/reversal and normal-upload replacement behavior before release, because a later normal import could overwrite previously applied deductions.

If absolutely no database writes are allowed, replace the commit phase with a temporary adjusted result/optional export. Clearly show that refreshing ordinary records will still show original values. This alternative does not fulfill persistent adjustment behavior and must be chosen explicitly.

## Proposed implementation scope

Names below are proposed, not existing files.

| Area | Planned change |
| --- | --- |
| Import Data UI | Update `RecordEntry.jsx`; extract a focused `ExpandedDmUploadDialog.jsx` if needed. |
| Routes/controller | Add dedicated authenticated review/apply endpoints in `routes/web.php`, handled by `ExpandedWtaxDmUploadController.php`. Reuse current access restrictions. |
| Workbook reader | Add `ExpandedWtaxDmReader.php` to read the agreed TIN/Debit layout without database writes. Validate content, file type/size, sheet and required headings. |
| Matching/calculation | Add `ExpandedWtaxDmAdjustmentService.php` for scoped matches, decimal calculations, preview, and transactional application. |
| Request validation | Add focused validation for file, percentage, company, report type, period, and review token. |
| Records and summaries | Reuse server aggregation after updates; expose adjustment details only if an audit approach is approved. |
| Tests | Add focused parser/service/feature coverage and retain existing Expanded regression checks. |

Do not change Purchase, Sales, Importation, normal Expanded workbook mapping, consolidation rules, or DAT/PDF/Excel formats. Approved amount updates may change downstream numeric totals; the serialization and generation rules must remain intact.

## Delivery sequence

1. Confirm amount meaning, no-save interpretation, sample workbook mapping, target scope, source allocation, and repeat-upload policy.
2. Build a pure parser for the verified ACCOUNT DETAILS layout and read-only matching/calculation review with explicit error statuses.
3. Add uploader and percentage modal, including cancel, validation, loading, and review states.
4. Implement transactional application only after the persistence and calculation decisions are settled.
5. Verify records, source details, summaries, and generated outputs remain consistent after a deduction.
6. Run focused automated checks and manually exercise the browser flow before marking the feature complete.

## Acceptance and verification

- File selection opens the percentage modal; cancelling creates no records and changes no balances.
- The supplied ACCOUNT DETAILS fixture yields exactly 3 DM rows and 2,875.65 in deductions; the 119,648.62 BIR payment, credit entries, and opening balance are excluded.
- The sample 1% account rejects a conflicting modal rate. Missing TIN resolution and ambiguous normalized supplier names produce visible review errors.
- With equivalent unadjusted test records and the approved Tax Withheld policy, the sample produces WORLD BEST 9,523.50, ACERSTEEL 1,275.97, the 1% bucket 20,145.13, and grand total 64,948.30. Use synthetic fixtures for automated tests rather than copying full business workbooks.
- A valid TIN + rate match in the selected scope receives exactly one intended deduction; another company/month/report type remains unchanged.
- Leading-zero/formatted TINs and fractional rates match correctly; malformed values are rejected visibly.
- Missing columns, unreadable workbooks, invalid Debit, no match, multiple matches, conflicting TINs, and excessive deductions leave records unchanged.
- Multiple DM lines targeting the same total are summed once. Consolidated groups do not receive the full deduction once per source row.
- Stale review, concurrent modification, double-click, network retry, and repeated upload follow the agreed duplicate policy.
- Expanded row count stays unchanged after applying DM deductions under the update-existing interpretation.
- Resulting amounts pass the existing BIR checks; rate totals and grand total agree across pagination, View Info, and relevant output generation.
- A failure during application rolls back every change in that operation.
- Run relevant `ExpandedWtaxImportTest`, `ExpandedWtaxConsolidationTest`, `ExpandedWtaxDatFileTest`, and `RecordPagesTest` coverage plus new DM tests. Run the frontend build and browser-check upload, review, apply, cancel, and validation states.

This document now tracks execution as well as the original proposal. See the execution update at the top for implemented behavior, unresolved decisions, and verification evidence.
