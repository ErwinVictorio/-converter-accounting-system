# Expanded re-upload: reset active DM references

Date: 2026-10-13
Status: Implemented. Automated verification passed; browser workflow verification remains pending because the browser debugging connection timed out.

## Desired behavior

After a successful regular Expanded replacement for a month, allow the same DM references to be applied to the newly imported records. Delete only the matching active duplicate-reference entries; preserve historical DM application details.

Example: September regular upload -> apply DM -> September regular re-upload restores workbook amounts -> upload the same DM -> review and confirm against new records -> further duplicate DM uploads are blocked again until another successful regular replacement.

## Original cause and integration points (before implementation)

- `VatInputController::import()` validates quarterly Expanded workbooks before a transaction deletes and reimports records for the selected agent TIN, branch, report type and reporting period. It does not reset DM references.
- `expanded_dm_references.identity` uniquely identifies company/branch/report/month/rate/reference. `ExpandedWtaxDmUploadController::duplicateIssues()` checks these entries, which currently survive regular replacement.
- `expanded_dm_applications` retains scope, original references inside JSON details, source before/after amounts, user, digest and confirmation token. Preserve it as the audit history.
- The completed-token shortcut currently returns success without examining whether the application has been superseded. This must change along with the reference reset.

## Scope of reset

Match normalized withholding-agent TIN + branch + report type + reporting month. Reset **all rates and suppliers** in that scope because the regular upload replaces the whole selected month, not only one DM rate or visible table page.

Do not reset another company, branch, month, or report type. Annual regular uploads must not clear quarterly DM references. DM currently supports quarterly monthly records only; leave annual reset behavior outside this implementation.

The reset happens only as part of successful regular replacement. File selection, cancellation, rejected validation, preview, and a failed import must leave records, active references and history unchanged.

## Implementation plan

1. Add a focused lifecycle service, proposed `ExpandedWtaxDmReferenceResetService`, to identify applications using the exact normalized scope and reset their active references. Read the stored JSON scope using the application's supported database mechanism; do not infer scope from supplier names or reference text.
2. Add nullable `superseded_at` to `expanded_dm_applications` through a new migration. Existing applications remain active by default; do not modify the original table-creation migration or clear historical references during migration.
3. Inside the quarterly regular-upload transaction, perform the existing replacement/import, mark matching active applications superseded, and delete only `expanded_dm_references` rows linked to those applications. Commit all operations together. Any error must restore the old records, references, and application status.
4. Retain each superseded application's audit JSON, references in its details, amounts, user, file digest, and token. No blanket truncate, global reference deletion, or automatic second deduction.
5. On subsequent DM upload, generate a fresh preview from the newly imported records. Fresh confirmation inserts a new application and new active references using the existing unique identity rule. This allows one application per replacement cycle and preserves duplicate protection within that cycle.
6. Reject confirmation of a superseded application's old token with a clear instruction to upload/review again. Preserve idempotent success for retries of a still-active completed application. Apply this rule in both the initial completed-token lookup and the unique-conflict retry branch.
7. Continue to reject old uncommitted previews via the existing record snapshot. Test replacement with identical workbook values too: changed source IDs must invalidate the preview. If a shared upload-generation value is needed to cover a discovered gap, bind the preview to it rather than clearing unrelated users' cache entries.
8. Serialize regular replacement and DM confirmation using the same company/branch/report/month lock, acquired inside the transaction before record locks and held through commit. It must cover all rates, work across requests/processes, and have explicit busy/timeout handling. Use a database-backed scope row lock or a verified shared lock mechanism; do not rely solely on locking existing payee rows, since a scope can be empty and replacement changes row IDs. Test on MySQL as well as transaction rollback in isolated SQLite tests.
9. Show a concise success message after a regular replacement that cleared references: `Expanded records replaced. Previous DM deductions were reset; upload the DM file again if needed.` No extra confirmation step is needed for the reset attached to the already-authorized replacement flow.

## Existing months already re-uploaded

Do not automatically delete all old references when deploying this change. Old audit snapshots alone are not sufficient proof that current balances are unadjusted.

The ordinary recovery path is to upload the original, unadjusted regular workbook again after the fix, then review/apply the DM. This resets the exact scope atomically using the new behavior. Warn in documentation that a regular workbook already containing net DM-adjusted amounts must not have the same deduction applied again.

## Preserved behavior

- Income Payment and Tax Withheld continue to adjust together using the selected rate and current rounding/allocation rules.
- Negative post-deduction amounts remain confirmable with the existing warning.
- DM-only and full ACCOUNT DETAILS layouts remain supported.
- Existing supplier matching, report-period checks, authorization and duplicate protection remain active.
- Purchase, Sales, Importation, annual Expanded replacement, and DAT serialization/validation are unchanged.

## Verification and acceptance

| Scenario | Expected result |
| --- | --- |
| Apply DM, then upload same DM without regular replacement | Duplicate blocked; balances unchanged. |
| Apply DM, successfully replace same month, upload same DM | Review and confirmation allowed once against new balances. |
| Month has DM applications at multiple rates | All active DM references for that exact replaced scope cleared. |
| Same month in another company/branch or annual report | References and records untouched. |
| Regular file fails preflight or user cancels | No reset and no record changes. |
| Import or reset fails inside transaction | Full rollback of replacement, reference deletion and superseded status. |
| Reuse completed token after its application is superseded | Rejected; no misleading success and no amount changes. |
| Confirm preview created before identical-value regular replacement | Stale preview rejected. |
| Confirm new token twice or retry after network failure | One deduction only; active-token retry is idempotent. |
| Two confirmations or confirmation and replacement overlap | Shared scope lock serializes operations; no stale deduction or orphaned active reference. |
| Inspect history after reset | Old application, original DM details and superseded timestamp retained. |
| New deduction results in negative balances | Warning shown; confirmation permitted; totals remain consistent. |

Extend `ExpandedWtaxDmUploadTest` and regular Expanded import tests. Replace the current assertion that references always survive replacement with separate coverage for manual record deletion versus an authorized successful regular re-upload. Run relevant Expanded/DAT/record regression tests, frontend build if UI copy changes, and browser-check regular re-upload -> DM review -> confirm -> duplicate rejection.

## Delivery sequence

Implement migration and reset service -> wire atomic replacement and shared locking -> handle superseded tokens -> add focused tests -> verify browser workflow -> update documentation. Begin with isolated test data; no manual live reference deletion is part of this plan.

## Implementation and verification - 2026-10-13

- Added `ExpandedWtaxDmReferenceResetService` and a new migration for nullable `superseded_at` plus `expanded_dm_scopes` generation/locking rows.
- Quarterly monthly regular replacement now locks the exact scope, replaces records, deletes linked active references, marks audit applications superseded, and advances the generation in one transaction.
- DM previews carry the generation. Confirmation and completed-token retries use the same scope lock; stale previews and superseded tokens are rejected.
- Added regression coverage for successful replacement/reapplication, duplicate protection, exact scope across rates, invalid regular uploads, full rollback after reset failure, and identical replacement invalidating previews.
- Relevant Expanded upload, import, consolidation, DAT, and record-page tests: **129 passed (1,443 assertions)**. Pint and `git diff --check` passed.
- Applied only the new migration to the local MySQL database. Existing business counts remained unchanged: 3 DM applications, 3 references, 228 Expanded entries. No manual reference cleanup or business adjustment was performed.
- A two-connection MySQL transaction probe confirmed that a concurrent writer to the same scope waits and receives the expected lock timeout; both probe transactions were rolled back.
- Browser verification was attempted against an isolated SQLite database/server, but the Edge debugging connection timed out at `Page.enable` before any upload. End-to-end browser verification is not claimed.

### Using the fix for an already affected month

1. Re-upload the original regular Expanded workbook for the correct company, branch, and month.
2. After successful replacement, upload ACCOUNT DETAILS again and review/confirm the DM deduction.
3. Further duplicate DM uploads remain blocked until the next successful regular replacement.

Use the original unadjusted regular workbook: applying the same DM to a workbook that already includes its deduction would deduct twice. Historical application details remain stored with the superseded timestamp.