# Purchase DM Uploader - Full Implementation Plan

Updated: October 16, 2026
Status: Full scoped workflow implemented and verified on October 16, 2026. The sections below retain the agreed design; section 13 records delivery evidence and limits.

## 1. Business outcome and computation interpretation

Match a DM to an existing original Purchase entry and subtract its Credit amount. Process DM references only; skip all non-DM rows even when they contain Credit. Do not create new supplier Purchase entries from unmatched DMs.

Follow the Expanded DM pattern: review eligible entries, show original/deduction/remaining amounts, explicitly confirm, update the matched records, and prevent the same active DM from being deducted twice.

Implemented computation: the sample account is VAT INPUT TAX. Credit therefore represents a VAT deduction. The proposed calculation subtracts Credit from the selected VAT bucket and input VAT, then reduces the corresponding taxable base. This follows Expanded's paired tax/base reduction. It does NOT mean subtracting the VAT Credit directly from the Purchase screen's taxable Total or blindly from the database column total_purchases. If “total minus Credit” means that literal displayed-total subtraction instead, that is a different amount rule and must be resolved before implementing the calculation.

## 2. Existing evidence and boundaries

- Sample: Docs/purchase/ACCOUNT DETAILS (1).xlsx, Sheet1, September 2026.
- Headers: Date, Reference, Particulars, Other Info, Debit, Credit, Balance; Credit is column F.
- Account: 152201A VAT INPUT TAX - PURCHASES LOCAL.
- DM rows: 6 (10,839.37), 16 (20,571.43), 61 (3,096.96), 62 (0.06); total PHP 34,507.82. This is a workbook total, not verified eligible stored-record deductions.
- No TIN column exists in this workbook.
- VatInputImport stores raw VAT buckets separately from taxable bases for uses_vat_bucket_amounts=true records.
- PurchaseAmountPresenter displays Local/Services/Others raw VAT and calculates their combined visible Total as round((Local + Services + Others) / 0.12, 2). Imported is currently excluded from that visible Total.
- ExpandedWtaxDmAdjustmentService reduces Tax Withheld and the related Income Payment, handles grouped allocation/rounding, and surfaces negative-balance warnings.
- PurchaseUploadService already locks company/branch/month scope during replacement. DM application must coordinate with that lock.
- No historical company assignment, DAT serialization change, or Importation mirror mutation is part of this feature.

## 3. User flow

1. Select Purchase, filing company/branch, and reporting month.
2. Browse or drop one ACCOUNT DETAILS workbook in the separate (DM) Purchase Uploader.
3. Modal shows filename and scope, with required Select Purchase Type:
   - VAT INPUT TAX - PURCHASES LOCAL
   - VAT INPUT TAX - PURCHASES IMPORTED
   - VAT INPUT TAX - SERVICES
   - VAT INPUT TAX - OTHERS
4. Upload validates/parses the workbook and opens a review. It does not deduct yet.
5. Review shows matching results and before/deduction/after figures.
6. Confirm Deduction, or Apply eligible DM only when exceptions exist, saves the eligible subset.
7. Show applied/skipped counts and totals, skipped reasons, and View Purchase Records link using the same company/month scope.

Cancel/close before confirmation never changes Purchase records. Scope/type/file changes invalidate the review. Prevent duplicate submission while busy; preserve useful error context for retries. Replace UI-preview-only messaging only after real endpoints are connected.

## 4. Workbook parsing and validation

- Accept one non-empty .xlsx/.xls file up to 10 MB; validate server-side, not only by extension.
- Resolve required headers and preserve worksheet/source-row provenance. Never use the ledger Balance or Debit as the deduction amount.
- Recognize DM as a reference prefix/token, case-insensitively after trimming, such as DM (REG)#000252; do not accept arbitrary text merely containing “DM”. Preserve the original reference for display.
- Skip all non-DM transaction rows, including PV with Credit; report them separately from skipped DM deductions.
- Exclude titles, headers, totals, and beginning balances from transaction counts.
- DM must have a valid reference, supplier name, transaction date in the selected month, and positive Credit with supported precision. Invalid DM rows remain visible with a reason; zero Credit is skipped and negative Credit is not inverted into an addition.
- Use decimal/integer-cent calculations; reject ambiguous or invalid money cells rather than coercing to zero. Do not trust workbook formula caches for deduction values.
- Validate period and account heading against selection. Reject conflicting periods/types with an actionable message. First version supports one recognized account type per upload; mixed-account files must be split. Never silently relabel a Local workbook as Imported.
- Reject duplicate normalized DM references within a workbook pending correction; do not sum repeated copies or accept one arbitrarily.
- File-level structure/period/type failures block review; valid non-DM rows simply skip.

## 5. Matching keys and eligibility

### Scope first

Restrict to filing_company_tin + filing_company_branch_code + reporting month using Purchase's existing date_uploaded period convention. Keep unassigned historical records outside the scope.

Only normal original Purchase rows are eligible initially: is_adjusted=false, non-broker, excludingImportationMirrors(). Exclude records involved in existing broker-transfer adjustments until an explicit reconciliation path is implemented, because changing their source balance can invalidate Undo/history behavior.

### Supplier resolution

Match workbook Particulars to stored supplier_name with the same documented normalization as Expanded: uppercase, trim/collapse whitespace, normalize & to AND, replace punctuation with spaces. This is an exact normalized comparison, not fuzzy matching. Resolve against scoped Purchase records; master-data presence alone is not a target.

Validate the matched stored tin_number identity. Missing/invalid identity, normalization collisions, multiple supplier identities, conflicting amount modes/rates, or incompatible source groups are skipped with a visible reason. Never choose the first result. Supplier branch identity must be read from the actual stored representation; do not invent a supplier branch field.

Group multiple compatible original rows only when company/month, normalized supplier, stored tax identity, original-record classification, amount mode, and rate agree. The selected type chooses the bucket inside that target group; a zero selected bucket does not itself make a uniquely matched original record disappear. Imported classification must remain compatible with its source records.

### Separate duplicate key

Active duplicate identity: filing company + filing branch + month + Purchase Type + normalized DM Reference. Same reference with altered supplier/Credit in that scope is a conflict, not a fresh deduction. Distinct references for one supplier may apply. Different account types have independent identities; mixed-account upload is not supported initially.

The reference prevents repeat application; supplier identity locates the original record. Neither filename nor Credit amount is a matching key.

## 6. Computation

### Initial supported amount mode

Enable deduction only for verified VAT-bucket records with preserved raw bucket values and a valid 12% stored rate, matching the current Purchase presenter. Missing/inferred raw values, explicit taxable-base mode, unknown legacy mode, or another rate are shown as unsupported and skipped until a dedicated adapter is specified. Do not fabricate raw VAT from an old base for this write path.

### Bucket mapping

| Selected type | VAT bucket reduced by Credit D | Base reduced by B | Related base category |
| --- | --- | --- | --- |
| Local | purchase_local_vat_amount | purchase_local | other_than_capital_goods |
| Imported | purchase_imported_vat_amount | purchase_imported | capital_goods |
| Services | services_vat_amount | services | services itself |
| Others | others_vat_amount | others | other_than_capital_goods |

For each compatible supplier/type group:

    D = sum(Credit of eligible, not-yet-applied DM references)
    B = round(D / (stored vat_rate / 100), 2)
    selected VAT bucket after = selected VAT bucket before - D
    selected base after = selected base before - B
    input_vat after = input_vat before - D
    taxable_net_of_vat after = taxable_net_of_vat before - B
    total after = total before - B
    total_purchases after = total_purchases before - B - D

Reduce the related capital_goods or other_than_capital_goods category by B for Imported or Local/Others respectively. For Services, services already IS the affected category: do not subtract B twice. Exempt, zero-rated, unrelated buckets, identities, and source period remain unchanged.

These delta formulas target verified VAT-bucket semantics from the importer. Do not require internal totals to equal recomputed bucket totals for DM eligibility. Subtract the agreed deltas from stored fields and preserve existing offsets; do not silently repair unrelated historical discrepancies. Preserve existing source rounding offsets instead of recomputing old imported bases from scratch.

Apply cents allocation in stable record-ID order, using positive available selected-bucket balances first; the final source absorbs any excess as in Expanded. Derive cumulative rounded base deductions so allocations sum to B exactly. Save per-source before/after values. Never allocate across incompatible rates or identities.

Recalculate displayed amounts through PurchaseAmountPresenter after application. For Local/Services/Others the visible Total is round((remaining Local VAT + remaining Services VAT + remaining Others VAT) / 0.12, 2). Imported DM updates the Imported bucket and stored related totals but does not change this existing local-only displayed Total rule.

### Example (illustrative stored record, not live database evidence)

Original Local VAT = PHP 12,000.00; Services/Others = 0; taxable base and visible Total = PHP 100,000.00; gross total_purchases = PHP 112,000.00.

DM Credit = PHP 1,200.00:

- Local VAT and input VAT after = PHP 10,800.00.
- Base deduction = PHP 10,000.00.
- Remaining Local base / visible Total = PHP 90,000.00.
- Remaining gross total_purchases = PHP 100,800.00.

The direct subtraction is 12,000 - 1,200 on VAT. The displayed taxable Total changes by the related base deduction.

For the supplied sample, WORLD BEST has three DM Credits totaling PHP 13,936.39; ACERSTEEL has one totaling PHP 20,571.43. Show those deductions only if each supplier resolves to eligible records; read actual starting balances from the database at review time.

### Negative balances

Follow Expanded's warning/acknowledgment pattern: show exact negative before/after consequences, require explicit acknowledgment, then allow the negative result where storage permits. Do not clamp to zero, create a new target, or silently drop the excess. Surface existing BIR validation warnings separately; a saved adjustment is not a claim that the resulting DAT will pass validation.

## 7. Review UI

- Summary cards: total valid DM Credit in file, eligible Credit, skipped DM Credit; non-DM count is separate. Invalid/unreadable amounts must not be presented as a known zero sum.
- Scope banner: company/branch, month, type, filename; clearly state Preview only, no adjustments saved.
- Supplier groups with expandable Date / Reference / source row / Credit / status / reason.
- Before / deduction / after for the selected VAT bucket, taxable base, input VAT, visible Total, and gross total where relevant. Use explicit labels rather than one ambiguous “Total”.
- Filters: All, Ready, Already applied, Needs review, Non-DM skipped.
- Explain no match, ambiguous identity, unsupported record, conflicting reference, invalid amount/date, and unavailable setup distinctly.
- No eligible rows: disable confirmation. With skipped DM rows: require eligible-only acknowledgment. Negative/BIR warnings require explicit acknowledgment.
- Failed application retains the review where safe; stale review requires rebuilding it. Successful application shows only committed results.

## 8. Persistence, concurrency, and audit

- Add dedicated Purchase DM batch/reference/source-change records; do not overload broker PurchaseAdjustment semantics or mark original rows as broker-adjusted.
- Audit company/branch/month/type, original and normalized reference, workbook digest/name, row/date/supplier/Credit, user, matched source IDs, rate, exact field deltas, before/after, timestamps, and active/reset state.
- Save only eligible applied references as active deductions. Skipped rows are review/audit information and must not reserve an active identity.
- Use a short-lived server-side review token bound to user, scope, calculation version, source snapshot, and generation. Do not trust client-supplied amounts or target IDs.
- Confirm inside one transaction using the existing Purchase company/month lock plus row locks. Recheck references, snapshots, generation, and eligibility. Changed balances/targets invalidate the review instead of silently applying a different result.
- Enforce active-reference uniqueness at the database level. Make confirm retry idempotent, including response-loss/double-click scenarios. Roll back all writes in an application if any save fails.
- Coordinate other Purchase mutations affecting targets (edit/delete/broker adjustments) with compatible locks or prevent those mutations while active DM references would make them inconsistent. Inventory these paths before implementation.

## 9. Normal re-upload and lifecycle

Normal Purchase replacement remains the normal import workflow. Within its existing company/month transaction, retire DM references only for original target records actually replaced; retain audit history, increment review generation, and invalidate pending reviews. The re-upload restores the new original workbook values; a later DM upload may apply again against that new generation. Do not retire references for untouched adjusted/importation records or other companies/months.

A failed re-upload must roll back both record replacement and reference reset. Document this behavior visibly near DM results/re-upload guidance. Standalone Undo DM is outside this delivery; existing broker Undo must not accidentally reverse DM amounts.

## 10. Proposed implementation files

- Extend resources/js/Components/PurchaseDmUploader.jsx from preview shell to review/apply flow.
- Retain RecordEntry.jsx scope integration; add scoped result navigation.
- Add PurchaseDmUploadController, request validation, and authenticated POST /purchase-dm/review and /purchase-dm/apply routes.
- Add PurchaseDmReader, PurchaseDmReviewService, PurchaseDmAdjustmentService, and PurchaseDmReferenceResetService (names provisional).
- Add dedicated ledger models/migrations; reuse existing Purchase scope locks and server-side review infrastructure where appropriate.
- Integrate reset with PurchaseUploadService and mutation coordination with the actual target-edit/delete/adjustment paths identified during implementation.
- Reuse PurchaseAmountPresenter and BIR validators. Keep DAT writer field order/format unchanged; changed amounts naturally flow through existing serialization.
- Update ViewInfoDialog only if needed for an explicit DM history/amount explanation; no unrelated ImportationRecords redesign.

## 11. Delivery sequence

1. Confirm amount interpretation in section 1, inventory actual schema/mutation paths, and capture relevant existing test baselines. No production data repair or migration is implied by this plan.
2. Implement parser/matching/calculation with isolated fixtures, including sample workbook and supported/unsupported amount modes.
3. Add ledger, scoped locks, snapshots, idempotent apply, and transactional re-upload reset.
4. Connect UI review/confirmation/results and show skipped reasons and warnings.
5. Verify regressions, browser flow, build, and scoped before/after reconciliation. Report any environment-blocked checks explicitly.

## 12. Acceptance tests

- Four sample DM entries yield file Credit PHP 34,507.82; PV Debit/Credit and Balance do not contribute.
- Same-supplier different references accumulate; already-applied references never deduct twice; duplicate/conflicting workbook references reject appropriately.
- Company/branch/month/type mismatch, no match, ambiguous names/TINs, unsupported rates/modes, and Importation mirrors cannot receive deductions.
- All four mappings produce correct raw VAT, base/category, input VAT, and gross totals; exempt/zero/unrelated buckets remain unchanged.
- Tiny Credit 0.06 and grouped rounding allocate exact cents. Zero original bucket and excess deduction follow explicit negative acknowledgment.
- Review/cancel has no record mutations; eligible-only apply leaves unresolved rows available for a corrected upload.
- Concurrent confirmations and normal re-upload cannot double-deduct or apply stale targets; failed transactions leave no partial references/amount changes.
- Successful scoped re-upload resets only replaced target references; other scopes and history remain intact.
- Existing Purchase display, broker adjustments/Undo, normal upload, Expanded DM, Importation, and DAT format tests remain valid.
- Browser verification covers file picker/drop, all four options, errors, filters, cancel, warning acknowledgment, result navigation, keyboard focus, and mobile layout; run frontend build and diff check.

## 13. Implementation status and verification

Implemented:

- PurchaseDmReader parses DM Credit, skips all non-DM transactions, validates account/period, and preserves source provenance. Formula amounts are not evaluated.
- PurchaseDmAdjustmentService resolves scoped supplier identities, reviews eligibility, computes all four paired VAT/base reductions, checks storage ranges, and reports negatives/BIR warnings.
- PurchaseDmUploadController provides authenticated review/apply with user-bound expiring tokens, snapshots, calculation version, scope generation, idempotent retries, and transactional audit writes.
- PurchaseDmReferenceResetService shares company/month locks with normal replacement, retires replaced references, preserves audit history, and guards active DM records against identity changes/broker transfers.
- PurchaseDmUploader now provides real upload/review/apply, before/deduction/after tables, filters, skipped reasons, acknowledgments, result summaries, and company/month-filtered Purchase navigation.
- Added four dedicated audit/scope tables. Applied only this migration to local MySQL c_and_c_database. No existing Purchase amount or company assignment was changed during setup.
- DAT serialization and Importation screens were not modified.

Validation:

- 113 focused/regression tests passed (965 assertions), including 19 Purchase DM tests, normal Purchase/company/retry behavior, broker adjustment/Undo, Expanded DM, and Purchase DAT tests.
- Real sample workbook parser check: 4 DM entries, PHP 34,507.82 total Credit.
- Production Vite build passed through VS Code's bundled Node; standalone npm is unavailable in this environment.
- Headless Edge against an isolated SQLite database passed type selection, exact review totals, negative and skipped acknowledgments, Cancel/re-review, mobile viewport containment, save, duplicate re-upload, and scoped Purchase Records navigation. Desktop/mobile screenshots are in storage/app/private/purchase-dm-desktop.png and purchase-dm-mobile.png.
- MySQL two-connection probe confirmed the shared scope lock blocks a concurrent writer. Both probe transactions rolled back.
- Local Purchase count stayed 68 before and after setup. SHA-256 of ordered Purchase rows remained 711167ea78b111e5f3e2e7502c41239f411ee901626c7842a1e2cc040a95cd31.
- PHP formatting and diff whitespace checks passed. Tests used isolated fixtures; the user's workbook was not applied to the live local Purchase balances.

Supported boundaries:

- The initial write path supports preserved VAT-bucket records at 12%. Legacy/inferred/explicit-base modes, conflicting identities, broker/transferred rows, and Importation mirrors are skipped with reasons.
- One account type/worksheet per DM file. Workbook heading must match the selected type.
- Negative results require explicit acknowledgment and may still fail existing DAT validation.
- A normal replacement covering only part of an active multi-source application is rejected instead of releasing references for amounts still applied to untouched sources.
- Raw per-source before/after audit is retained in purchase_dm_sources and application details. A standalone DM Undo/history-management screen is outside this delivery.
- Existing local historical rows without a filing company remain unassigned; matching will not claim them automatically.

### Purchase Type dropdown correction

The dropdown portal initially used z-index 50, below the dialog overlay/content at 100/101. Set the Purchase DM dropdown content to z-index 102 with an opaque background. The earlier programmatic-click browser check did not detect this layering issue. Follow-up browser verification used actual pointer coordinates and hit testing: all four types selected successfully on desktop and mobile. Frontend production build passed after the fix.

### Simplified matching and UI descriptions

Removed the strict internal-total equality gate at the user's request. Company/branch/month/supplier matching, supported VAT data, duplicate checks, and negative-balance acknowledgment remain. UI descriptions now use shorter labels and instructions. Added a regression test proving existing total offsets survive the deduction and duplicate protection still applies. Focused DM suite: 20 tests, 149 assertions passed.
