# Expanded DM review and eligible-only application plan

Date: 2026-10-14
Status: Implemented locally on 2026-10-14 after user approval. Production deployment and production data changes were not performed.

## 1. Objective

Make DM review explain exactly what will change and allow the user to apply valid, unapplied DM entries while leaving unmatched, ambiguous, and already-applied entries untouched. Preserve negative-result confirmation, source allocation, audit history, and regular re-upload reference reset.

An upload is still a preview. Only explicit confirmation saves the reviewed eligible subset. Saving that subset is one atomic transaction, not a series of independently committed rows.

## 2. Current local behavior and production evidence

Verified local integration points:

- `ExpandedWtaxDmReader` parses full and DM-only ACCOUNT DETAILS workbooks, validates the selected period/rate, requires positive DM Debit, excludes recognized PV entries, and rejects repeated DM references inside a file.
- `ExpandedWtaxDmAdjustmentService::query()` scopes records by withholding company TIN, agent branch, quarterly reporting month, and percentage. `review()` matches normalized supplier names to a unique valid TIN and one consolidated payee/ATC group.
- The service currently combines missing names, invalid TINs, and multiple TINs into one error. It calculates targets before the controller checks already-applied references. Its total includes every parsed DM row, including unmatched rows.
- `ExpandedWtaxDmUploadController` issues a review token only when there are no issues. Confirmation currently inserts references for every parsed DM row.
- `ExpandedDmUploader.jsx` displays repeated red issue paragraphs and supplier amounts. Confirmation currently sends `acknowledge_warnings: true` directly.
- `ExpandedWtaxDmReferenceResetService` provides a scope lock and generation. Successful regular replacement supersedes applications and clears active references for that exact company/branch/report/month, across rates. Old previews and superseded completed tokens are rejected.

Production screenshot observations are not a production database diagnosis: unmatched suppliers and previously applied references appear together; ACERSTEEL has a negative proposed result. The local implementation cannot establish which supplier records or deployed code version production currently contains. No production write/testing is part of this plan.

## 3. Scope and preserved rules

In scope: structured per-entry classification, supplier review groups, eligible-only calculations and confirmation, accurate summary counts/totals, clearer errors, retry-safe partial application, and focused regression/UI checks.

Preserve:

- Both Tax Withheld and Income Payment deductions, existing rounding, and ID-order source allocation.
- A valid matched record with zero or negative amounts remains eligible; a negative result requires acknowledgment, not exclusion.
- Missing records are never treated as zero records or automatically created.
- Positive input DM Debit remains required. Accepting negative resulting balances does not authorize negative input Debit.
- Company/branch/month/report/rate boundaries, authorization, unique reference identities, audit history, and replacement lifecycle.
- Existing DAT serialization and validation, annual Expanded handling, Purchase, Sales, and Importation.

Out of scope: fuzzy automatic matching, manual supplier reassignment, master-data cleanup, automatic reversal, deletion of production history, and changing the regular importer's treatment of zero-valued workbook lines. A zero worksheet line is not proof that a persisted Expanded record exists.

## 4. Classification contract

Classify each parsed DM entry exactly once. Use structured codes, not parsing of human-readable error strings.

| Code | Meaning | Apply behavior |
| --- | --- | --- |
| ready | Unique valid matching group; reference not applied | Include |
| already_applied | Active identity already exists for exact scope and rate | Skip |
| no_matching_record | No normalized supplier-name match in scoped records | Skip |
| invalid_tin | Name matched, but its TIN is missing or invalid | Skip |
| ambiguous_tin | Name resolves to more than one TIN | Skip |
| ambiguous_group | Unique TIN resolves to multiple ATC/payee groups | Skip |
| conflicting_identity | Consolidated group contains conflicting TIN/branch identity | Skip |

Negative balance and BIR validation findings are separate warning flags on eligible target groups, not skip classifications. A group can be ready and have a negative-balance warning.

Order: validate workbook -> look up active references -> classify already-applied entries -> match remaining entries -> calculate only eligible entries. An already-applied entry remains skipped even if current supplier matching has changed. Keep one primary reason per entry; supplemental details must not double-count it.

For clearer matching errors, distinguish no name candidate, invalid candidate TIN, multiple candidate TINs, and conflicting ATC/branch groups. Include row numbers, original supplier name, normalized reference, selected month and rate, and actionable guidance. Do not assume a missing match proves a particular root cause.

## 5. Workbook errors versus skippable entries

Keep malformed/unreadable workbook, wrong layout, invalid date/period, account-rate mismatch, malformed reference, repeated reference inside the same workbook, missing supplier, nonpositive/invalid Debit, and conflicting Debit/Credit as whole-file rejection. No token and no writes.

Partial application applies only to well-formed parsed entries that cannot currently match or were already applied. Do not silently drop malformed financial rows or sum invalid amounts into a supposedly complete file total.

A repeated reference within one file remains an error even when amounts differ; do not merge it or take the first occurrence. A reference already applied in an earlier application is skipped by identity even if the newly uploaded amount/name differs; never apply the difference automatically. Explain that changed previously applied documents require separate reconciliation.

## 6. Review UI

Show selected company/branch, month, percentage, filename, and: "Preview only. No adjustments have been saved."

Summary cards:

- Total DM amount in file, with parsed DM entry count.
- Eligible deduction, with eligible entry count.
- Skipped amount, with skipped entry count and breakdown by already applied versus needs review.

Provide sections or filters: All suppliers, Ready to apply, Needs review, Already applied. Use text status labels in addition to color.

Display one expandable supplier group per resolved identity (TIN/branch/ATC/rate), not merely per display name. Unresolved entries can group by normalized supplier name; do not merge distinct resolved identities that share a name. A supplier containing both eligible and skipped references shows counts and a mixed-status label; each reference retains its own status in details.

Main columns: Supplier, Status, Current Tax, Eligible DM deduction, New Tax. Show Income Payment before/deduction/after in details along with TIN, ATC, rate, source count, DM reference, file row, amount, and skip reason.

- New Tax = Current Tax minus eligible deduction only.
- Unmatched groups display unavailable balances as an em dash, never zero.
- Already-applied-only groups have no proposed adjustment; avoid presenting their historical Debit as a new deduction.
- Multiple unmatched rows for one supplier produce one concise summary with expandable row/reference details rather than repeated paragraphs.
- Warn visibly for eligible negative results, including both resulting tax and income totals. Separate BIR warnings from matching problems; acknowledging them does not repair DAT issues.
- Keep totals and the confirm action accessible with a bounded scrolling content area; verify desktop and narrow-screen layouts and keyboard operation.

Before confirming, show an exact statement such as: "3 DM entries will be applied; 10 entries will be skipped. Eligible deduction: PHP X."

Primary action: `Apply eligible DM only` for mixed reviews; `Confirm Deduction` when all entries qualify. Require an explicit acknowledgment of skipped entries for mixed reviews and warning acknowledgment when warnings exist. Never silently send an acknowledgment that the UI did not obtain. One review modal is sufficient; no additional repetitive confirmation dialog.

If no entries qualify, show `No eligible DM entries to apply`, with Close and correction guidance. Do not expose an apply token/button. Cancel, close, or file reselection makes no database changes.

After success, retain a result summary: applied entry count and amount, skipped count/amount, and reasons in expandable details. Link to Expanded records for the same scope. Clearly state that skipped entries were not saved as deductions and may be reviewed again after correction.

## 7. Amount and count invariants

Use integer cents internally and decimal strings in API payloads. Preserve current cumulative rounding for income allocation.

- file_dm_total = eligible_total + skipped_total
- dm_entry_count = eligible_count + skipped_count
- skipped_total = already_applied_total + needs_review_total
- eligible_total = sum of eligible target deductions = saved application total_deduction
- Only eligible entries affect proposed amounts, warnings, source changes, and new reference rows.
- PV count and Debit stay separate and are not part of DM totals.
- Count DM entries/references, supplier groups, and Expanded source rows separately and label them accurately.

Illustrative fixture, not an inference about production: file total PHP 10,000; already applied PHP 2,000; unmatched PHP 3,000; eligible PHP 5,000. Apply only PHP 5,000. Re-uploading the file without corrections then yields no eligible entries. After adding a valid match for the previously unmatched supplier, only its PHP 3,000 becomes eligible.

## 8. Backend design and response contract

Add a focused review/classification layer, proposed `ExpandedWtaxDmReviewService`, that shares reference identity construction and batch active-reference lookup with confirmation. Avoid duplicating identity logic across controller and services.

Return a versioned response, proposed `review_version: 4`, containing:

- scope, file metadata, apply_available, token;
- summary with file/eligible/skipped amounts and counts plus skip breakdown;
- entries with stable server-generated key, source row, reference, supplier, amount, status, reason code/message, and resolved target key when applicable;
- target groups with eligible-only before/deduction/after amounts and eligible entry keys;
- negative_balance_warnings and bir_warnings;
- file-level blocking issues separately from skippable entry findings.

Refactor the adjustment service to consume the eligible subset only. Preserve calculation behavior for that subset. Prefer returning applied total explicitly rather than overloading an all-file total. Update frontend and controller together; stale clients must receive a clear refresh/review-again error instead of applying a different contract.

The server creates a token only when storage/lifecycle schema is available, workbook validation succeeds, and at least one entry qualifies. Cache ownership, normalized scope, digest, parsed entries, exact eligible entry keys and identities, classification fingerprint, source snapshot, generation, acknowledged-review contract version, and expiry.

Do not accept browser-submitted amounts, eligible IDs, or scope overrides at confirmation. The backend determines all changes from the stored review.

## 9. Confirmation, concurrency, and retries

Inside the existing transaction:

1. Authenticate token ownership and review version; acquire the same company/branch/report/month scope lock used by regular replacement before locking source rows.
2. Preserve active completed-token idempotency. Reject a superseded completed token as today.
3. Check generation, source snapshot, current active references, and current classification against the reviewed fingerprint and exact eligible set.
4. If any relevant state changed, reject with "Records or DM status changed. Review the file again." Do not silently shrink or expand the applied subset after the user confirmed it.
5. Validate explicit skip/warning acknowledgment requirements against the server-stored review.
6. Recompute eligible-only amounts using locked records, then insert one application, references only for applied entries, and all eligible record updates atomically.
7. Roll back everything on any failure. Retain unique identity constraints as the final concurrency guard.
8. Return applied and skipped summary. Repeated confirmation of the same active token returns the same stored outcome without another deduction.

Do not mark skipped references applied and do not create separate applied-ledger rows for them. Concurrent overlapping reviews must not double-deduct; the later request needs a fresh review when its eligible set has changed.

## 10. Audit and schema compatibility

Prefer the existing JSON details field; no new migration is expected for this feature. Add a details version and store:

- Original file total/count and parsed entry classifications.
- Applied references, applied total, target mapping and before/after amounts.
- Skipped references, amounts, codes/reasons, and counts.
- Warning/skip acknowledgments and existing user, scope, digest, token, timestamp.

`expanded_dm_applications.total_deduction` continues to mean the amount actually saved, never the full uploaded file total. `expanded_dm_references` contains only entries actually applied by that application.

Retain readers for existing unversioned application details. Completed retries of historical applications must remain usable without expecting new JSON keys. Do not backfill historical outcomes by guessing. All-skipped previews create no application or reference rows.

Keep the regular replacement reset service unchanged unless compatibility tests reveal a necessary narrow adjustment: it must still supersede the whole historical application and clear only its active applied references. Skipped history is informational, never active duplicate protection.

## 11. Correction and re-upload workflow

- Already applied: no action needed unless investigating an incorrect historical application. Never clear references alone to force another deduction.
- No match: check original supplier name, selected company/branch/month/rate, and whether a regular Expanded record actually exists. Correct through existing authorized workflows.
- Invalid/ambiguous identity: correct the underlying regular records or master-data source; no automatic guessed mapping.
- After correction: re-upload the same DM workbook and review again. Previously applied references remain skipped; newly resolved entries can qualify.
- If correction requires a full regular monthly replacement, existing reset behavior creates a new application cycle. Re-upload the complete intended DM workbook against original unadjusted regular amounts; prior deductions are not automatically carried forward.
- Warn that replacing regular records with already DM-adjusted workbook amounts and applying the same DM again would double-deduct.

## 12. Files expected to change during implementation

| File | Planned responsibility |
| --- | --- |
| app/Services/ExpandedWtaxDmReviewService.php (proposed) | Classification, reference lookup/identity reuse, eligible subset and summary |
| app/Services/ExpandedWtaxDmAdjustmentService.php | Eligible-only calculations; retain negative/rounding behavior |
| app/Http/Controllers/ExpandedWtaxDmUploadController.php | Versioned preview, exact subset confirmation, applied-only ledger, result summary |
| resources/js/Components/ExpandedDmUploader.jsx | Grouped statuses, separate totals, explicit acknowledgments, result details |
| tests/Feature/ExpandedWtaxDmUploadTest.php | Mixed outcomes, retry, stale-state, audit and rollback cases |
| Focused service/frontend tests using existing project tooling | Classification/totals and UI behavior where practical |
| Docs/Expanded/EXPANDED_WTAX_DM_UPLOADER_PLAN.md | Cross-reference final implemented behavior after delivery |

Reader/layout rules and reset service should remain intact unless focused tests demonstrate required changes. No DAT generator changes are planned.

## 13. Verification matrix

| Scenario | Required result |
| --- | --- |
| All entries ready | Same amounts as existing behavior; full application |
| Ready plus unmatched | Only ready entries saved after explicit skip acknowledgment |
| Ready plus already applied, same supplier | Deduction excludes applied reference before calculation |
| All skipped / no Expanded records | Clear grouped reasons; no apply token or database writes |
| Name absent / invalid TIN / multiple TINs / multiple ATCs | Distinct reason codes and actionable messages |
| Matched zero or negative balance | Eligible; warning acknowledged; negative values persisted |
| Eligible negative plus skipped entries | Both acknowledgments required; skipped entries affect neither warning nor deduction |
| Same supplier with multiple references and mixed statuses | One resolved group, accurate per-reference details and totals |
| Same name with distinct valid identities | No accidental grouping or auto-matching |
| Previously applied reference with changed amount/name | Skip, no difference adjustment |
| Repeated reference within uploaded file | Whole-file rejection |
| Malformed amount/layout/date or rate mismatch | Whole-file rejection, no partial mutation |
| Matching punctuation normalization | Existing name normalization retained |
| Re-upload after unmatched data is corrected | Only newly eligible entries saved |
| Regular monthly replacement after partial application | Exact-scope reset works; fresh DM review allowed; audit preserved |
| Old preview / changed snapshot / changed classification | Reject; require fresh review |
| Repeated confirm / network retry | Same stored outcome; one application and deduction |
| Overlapping concurrent confirmations | No double deduction; stale request rejected |
| Confirmation overlaps regular replacement | Shared lock/generation prevents stale application |
| Mid-save failure | All updates, application and references rolled back |
| All amounts/counts | File = eligible + skipped; persisted total = eligible |
| Unauthorized/expired token or old review version | Reject without writes |
| Historical completed application | Backward-compatible retry; superseded token still rejected |
| Cancel/close and mobile/keyboard review | No writes; usable scroll, labels, focus and actions |
| DAT and unrelated import regressions | Existing formatting, validation and behavior retained |

Run focused DM tests first, then Expanded import/consolidation/DAT/record-page regressions. Run formatter, production frontend build, and diff checks. Verify browser flow using isolated data: mixed preview -> acknowledge -> apply eligible -> verify stored totals -> re-upload -> verify applied references skipped -> correct unresolved fixture -> apply remaining entries. Exercise database locking with MySQL separately from SQLite transaction tests. Record actual results and any unavailable checks; do not claim browser or production verification from backend tests alone.

## 14. Delivery, production rollout, and rollback

1. Implement classification and backend invariants with focused fixtures.
2. Implement versioned preview/confirmation and applied-only audit/reference writes.
3. Build review UI and explicit confirmation acknowledgments.
4. Complete automated regressions, build, and isolated browser/MySQL checks.
5. Update documentation with verified behavior and known limits.
6. Before a separately authorized production deployment, record deployed revision, verify existing lifecycle migration/schema and PHP/frontend compatibility, and take a recoverable database backup. Do not deploy or modify production under this planning request.
7. Deploy backend and frontend together; invalidate old review contracts by version and require users with open dialogs to review again. Historical completed-token idempotency must remain intact.
8. Initial production check should be a read-only review of a known workbook and scope, not an unsolicited financial deduction. Let an authorized operator confirm any real adjustment.
9. If rollback is required, stop new DM confirmations and restore the prior compatible code/assets. Do not erase partial-application audit rows/references or automatically reverse amounts. Investigate saved outcomes before any separately authorized data repair. Keep lifecycle tables/migrations used by existing applications.

## 15. Completion criteria

Every valid parsed DM entry has a visible status; no already-applied or unresolved amount enters a proposed or saved deduction; eligible entries can be confirmed together with appropriate warnings and explicit skip acknowledgment; skipped entries remain retryable; history and replacement lifecycle remain consistent; relevant tests/build and isolated browser checks are documented.

The implementation below is complete locally. No production testing, migration, deployment, or financial adjustment was performed.

## 16. Implementation results (2026-10-14)

- Added `ExpandedWtaxDmReviewService` with shared identity construction, explicit matching reason codes, duplicate-first classification, eligible-only calculation, grouped supplier details, and reconciled file/eligible/skipped summaries.
- Reused the matching method from the adjustment service; existing rounding, allocation and negative-result behavior remain intact.
- Review contract and cache version are now 4. Confirmation requires the current client version, exact eligible keys, fingerprint, generation, and source snapshot under the existing shared lock. Historical completed-token retries remain supported.
- Confirmation requires explicit skip/warning acknowledgments when applicable. Only ready entries receive active references. Application JSON retains classifications, skipped reasons, acknowledgments, eligible changes and versioned summaries; application total is only the saved deduction.
- Rebuilt the review UI with supplier statuses/filter, expandable per-reference details, separate totals, visible negative/BIR warnings, explicit checkboxes, and an eligible-only action. No-eligible reviews have no save action.
- Added scope-aware result links. `RecordController` and `ExpandedWtaxRecords.jsx` now support and preserve optional company/branch/report/rate filters in addition to month/search, display the active scope, and provide a link back to all records. Existing unfiltered listing behavior remains unchanged.
- No new database migration is needed. Production must already have the DM application/reference tables and the 2026-10-13 scope-generation/superseded lifecycle migration. Missing lifecycle schema makes saving unavailable.

### Verification evidence

- Expanded upload/import/consolidation/DAT/record regression suite: **139 tests passed (1,560 assertions)**.
- Focused DM suite after additional cases: 34 tests, 293 assertions passed. Includes mixed outcomes, later matching correction, zero/negative results, changed duplicate documents, old-client rejection, historical retries, stale reference-only changes, scope links, rollback, and regular replacement after partial application.
- Vite production build passed. PHP formatting and whitespace checks completed.
- Isolated SQLite browser workflow passed: mixed ready/already-applied/unmatched preview; reconciled totals; skip and negative-warning acknowledgment; cancel without saving; desktop/mobile review; eligible-only save; same-file re-upload with no eligible entries; correction of unmatched supplier; apply only the newly eligible amount; scoped records link.
- Browser fixture amounts: initial WORLD BEST tax 100, eligible DM 150 -> tax -50 and income -5000. Previously applied DM 50 and unmatched AMADA DM 25 were skipped. After adding an isolated AMADA source with tax 100, only DM 25 applied -> AMADA tax 75; WORLD BEST remained -50.
- MySQL shared-scope lock probe passed using two connections; concurrent insertion timed out as expected. Both probe transactions rolled back. This probe verified locking mechanics, not a production concurrency load test.
- Browser fixtures used only `storage/app/private/dm-eligible-browser.sqlite`; no production records were used or changed. Initial browser harness timing/assertion errors were corrected and the complete sequence rerun successfully.

### Deployment note

Deploy backend and rebuilt frontend assets together. Users with existing review dialogs should refresh and review again. Do not clear active references or alter balances as part of deployment. Keep the documented regular re-upload lifecycle for intentional monthly replacement.