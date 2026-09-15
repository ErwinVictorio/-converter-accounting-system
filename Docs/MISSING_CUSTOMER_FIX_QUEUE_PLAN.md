# Missing Customer Fix Queue and Retry Sales Upload Plan

## Implementation Status

Implemented on 2026-09-15.

The implementation adds a Sales-specific pending workbook, Customer-only issue classification, grouped Add/Edit Customer repair context, fresh queue validation, exact-file retry, duplicate active-upload reuse, checksum and ownership protection, 24-hour expiry, and scheduled cleanup. Initial upload and retry now share `SalesUploadService`, preserving the existing replacement transaction and DM-row behavior.

Verification completed:

- New pending Sales upload suite: 7 tests, 65 assertions.
- Focused Sales upload, zero-rated, Purchase queue, record-page, and DAT ordering regressions: 101 tests, 1,398 assertions.
- Four pending Sales routes, migration dry run, real local migration, cleanup command, hourly schedule registration, PHP syntax, Pint formatting, and `git diff --check` passed.
- Frontend production compilation was not run because Node/npm is unavailable in the current shell.

No Sales amount formula, zero-rated classification, consolidation, DAT generator, DAT layout, filename, total, rounding, ZIP, or PDF attachment implementation was changed.

This document proposes a Sales-specific Customer repair queue based on the existing Purchase Supplier fix queue. It is intentionally separate from the Purchase implementation because Sales validation can report both Customer master-data problems and workbook amount/zero-rated problems. Only Customer-fixable BIR issues belong in this queue.

The sections below record the implemented design and compatibility boundaries.

## Goal

Turn a rejected Sales upload with missing or invalid Customer BIR information into a guided repair workflow:

1. Retain the exact rejected Sales workbook privately.
2. Show one grouped queue item per affected Customer.
3. Open a server-authorized, prefilled Add Customer or Edit Customer form.
4. Revalidate the retained workbook after every Customer save.
5. Retry the original workbook without requiring the user to select it again.
6. Replace same-month Sales rows only after every normal Sales safeguard passes again.

The workflow must preserve the existing safety rule: a rejected, mixed-error, expired, corrupt, or still-invalid Sales upload must not delete or replace saved Sales rows.

## Current Confirmed Behavior

- `VatInputController::import()` validates the request and normalizes the selected reporting month to month end.
- `UploadWorkbookTypePreflight` rejects the wrong workbook type or reporting period before import.
- `UploadBirInfoPreflight::checkSales()` supports both Sales Summary and BIR Sales layouts.
- Sales preflight can return Customer BIR issues and `sales_amounts` issues from amount/zero-rated validation.
- `SalesVatInputImport` imports SI/CM rows, skips DM rows, and retains the existing skipped-DM success warning.
- Same-month non-adjusted Sales rows are deleted only inside the existing `DB::transaction`, immediately before import.
- `CustomerController` supports Customer create/update and synchronizes matching saved Sales rows after a Customer save.
- `ManageCustomer.jsx` already provides Customer create/edit forms and field validation display.
- The existing Purchase queue already demonstrates private retention, ownership, opaque tokens, checksum verification, expiry, refresh, retry locking, cancellation, and scheduled cleanup.

## Critical Sales Difference

Sales preflight issues must be classified before a pending queue is created.

### Customer-fixable issues

These can enter the guided queue:

- `customer_tin`
- `company_name`
- `address1`
- `address2`
- another BIR identity field only when the server can prove it is repairable through Customer master data

### Workbook-only issues

These cannot be fixed from Manage Customers:

- `sales_amounts`
- inconsistent Net Amount, Net of VAT, VAT, Exempt Sales, or Zero Rated Sales
- missing or conflicting zero-rated classification
- malformed workbook rows or headings
- wrong workbook type or reporting period

If any workbook-only issue exists, do not create a pending Customer queue. Return the normal structured error dialog and instruct the user to correct and re-upload the workbook. This avoids retaining a file that can never become retryable through Customer edits.

## Scope

Included:

- Sales Summary and BIR Sales uploads rejected only by Customer-fixable BIR issues.
- Private temporary retention of the exact rejected workbook.
- A user-owned pending Sales upload record with expiry and status.
- Grouping repeated worksheet issues into Customer-level queue items.
- Server-authorized Add/Edit Customer prefill.
- Fresh queue refresh after Customer create/update.
- Retry using the retained workbook and original reporting month.
- Preservation of the existing Sales replacement boundary and DM warning.
- Cleanup of completed, cancelled, and expired pending uploads.
- Focused backend, frontend, security, and regression coverage.

Not included:

- Purchase, Expanded WTAX, or Importation queue changes.
- Editing Excel workbook amounts inside the application.
- Retaining uploads with `sales_amounts` or other workbook-only errors.
- Guessing Customer TIN, address, city, tax status, or zero-rated eligibility.
- Changing Customer matching priority without separate approval.
- Changing existing Customer-to-historical-Sales synchronization behavior.
- Changing Sales consolidation, amount normalization, zero-rated classification, stored values, rounding, or DM handling.
- Any DAT header, detail field, delimiter, filename, total, ZIP, or PDF attachment change.

## Required User Flow

### 1. Initial Sales Upload

1. The user selects a Sales workbook and reporting month.
2. The server validates the request, workbook type, and reporting period.
3. The server runs the complete Sales BIR and amount preflight.
4. If a workbook-only issue exists:
   - reject the upload using the existing issue dialog
   - do not retain the workbook
   - do not delete or import any Sales rows
5. If all issues are Customer-fixable:
   - store the exact workbook on the private disk
   - create a pending Sales upload owned by the authenticated user
   - return a grouped Customer queue in `uploadIssueDialog.pending_upload`
   - do not delete or import any Sales rows
6. The dialog shows the affected Customer count, affected worksheet rows, required fields, `Fix Customer`, and `Open Fix Queue` actions.

### 2. Fix a Customer

- When the issue matches a confirmed Customer, open the Edit Customer dialog for that exact server-selected Customer.
- When no Customer matches, open Add Customer with safe workbook prefill.
- Never accept a client-supplied Customer ID as authority for the edit target.
- Show the retained filename, reporting month, affected worksheet rows, missing fields, and remaining Customer count.

Safe prefill rules:

- Sales Summary normally provides a Customer name but not a reliable Customer TIN/address/city; prefill only the name and leave missing required values blank.
- BIR Sales may safely provide TIN, company/individual name components, Address1, and Address2; use only values actually read from that row.
- If a confirmed Customer exists, use its saved master-data values for edit mode.
- Do not convert an individual BIR Sales name into a company identity automatically. The current Customer model is company-oriented, so individual-customer handling must remain explicit and must not silently change type semantics.

After Customer create/update:

1. Preserve the pending token and queue key in the redirect context.
2. Re-run the retained workbook's complete Sales preflight.
3. Reclassify all returned issues.
4. Mark an item resolved only when fresh preflight no longer reports it.
5. If a new workbook-only issue appears, keep the upload non-retryable and show that the workbook must be corrected and uploaded again; do not present it as fixable from Customers.
6. Move to the next unresolved Customer when practical.

### 3. Retry Upload

Enable `Retry Upload` only when a fresh preflight returns no issues.

Retry must:

1. authorize the authenticated owner
2. confirm the pending record is still `pending` and unexpired
3. atomically claim it as `retrying`
4. verify the private file exists and matches its stored SHA-256 checksum
5. rerun workbook type and reporting-period validation
6. rerun the complete Sales BIR and amount preflight
7. enter the Sales replacement transaction only when every check passes
8. preserve the existing non-adjusted same-month deletion boundary
9. run the same `SalesVatInputImport` behavior as initial upload
10. preserve the zero-import/all-DM failure and partial DM warning

On success, mark the pending upload `completed` and delete the retained workbook after the database transaction commits. On validation or import failure, roll back Sales changes and return the pending record to a safe retryable state only when the retained file remains valid.

## Data Design

### Pending Sales Uploads Table

Create `pending_sales_uploads` rather than adding Sales semantics to `pending_purchase_uploads`.

| Column | Purpose |
| --- | --- |
| `id` | Internal primary key. |
| `token` | Unique opaque UUID exposed to the UI. |
| `user_id` | Owner used for every authorization check. |
| `original_name` | Display-only sanitized original filename. |
| `stored_path` | Generated private-disk path. |
| `mime_type` | Validated MIME type for diagnostics. |
| `size` | Retained byte size. |
| `sha256` | Integrity check for exact-file retry. |
| `reporting_period` | Original normalized month-end date. |
| `status` | `pending`, `retrying`, `completed`, `expired`, or `cancelled`. |
| `issues` | Latest structured preflight issues, used only as a UI cache. |
| `initial_customer_count` | Initial grouped Customer count. |
| `initial_row_count` | Initial unique worksheet-row count. |
| `expires_at` | Expiry boundary, default 24 hours. |
| timestamps | Audit timing. |

Requirements:

- Cast `issues` to array, `reporting_period` to date, and `expires_at` to datetime.
- Bind routes using `token`, not sequential ID.
- Index `token`, `user_id + status`, and `expires_at`.
- Store under `pending-sales-uploads/{uuid}.{extension}` on the private `local` disk.
- Do not create a public download route.
- Treat cached issues as presentation data only; fresh preflight decides validity.
- Use a Sales-specific expiry config such as `bir.pending_sales_upload_hours`, defaulting to 24.

## Queue Identity and Item Shape

Current Sales master-data lookup matches `Customer::name_key`. Preserve that behavior unless Customer matching is separately redesigned.

Group in this order:

1. confirmed `customer_id`
2. normalized Customer name using `Customer::normalizeName()`
3. worksheet row when there is no safe nonblank identity

Do not group unrelated blank-name rows. Do not introduce Purchase-style TIN-first matching only for the queue because it would drift from the Sales importer/preflight lookup.

Each Customer-fixable issue should be enriched with:

```text
customer_id            nullable confirmed master-data match
identity_key           customer ID, normalized name, or row fallback
raw_tin                safe workbook TIN when present
raw_name               workbook Customer name
raw_address1           safe workbook Address1 when present
raw_address2           safe workbook Address2 when present
row                    worksheet row
field                  customer_tin, company_name, address1, or address2
problem                actionable existing validation message
match_basis            confirmed Customer or normalized-name lookup
issue_class            customer or workbook
```

Grouped queue response:

```text
queue_key
customer_id
mode                    edit or create
display_name
affected_rows
missing_fields
problems
prefill                 tin, name, addr, city
fix_url
```

## Backend Plan

### 1. Extract a Shared Sales Upload Service

Create `SalesUploadService` and move the current Sales execution rules out of the controller without changing them.

Suggested responsibilities:

- `preflight(file, reportingPeriod)`:
  - run `UploadWorkbookTypePreflight` for Sales
  - run `UploadBirInfoPreflight::checkSales()` only when type/period passes
  - classify issues as Customer-fixable or workbook-only
- `replace(file, reportingPeriod)`:
  - delete only non-adjusted Sales rows for the same month end
  - import through `SalesVatInputImport`
  - preserve the all-DM/no-import failure
  - return skipped-DM count for the existing warning

Use the service from both initial Sales upload and pending retry. Do not duplicate the transaction or warning logic in the pending controller.

### 2. Enrich Sales Preflight Metadata

Extend the Sales issue-building path without changing validation outcomes:

- Customer BIR issues receive confirmed `customer_id`, stable `identity_key`, safe `prefill`, and `issue_class: customer`.
- Amount and zero-rated issues retain `field: sales_amounts` and receive `issue_class: workbook`.
- Wrong type/period and unreadable-file failures remain outside the queue.

The classifier should use an explicit allowlist of Customer-fixable fields. Unknown fields default to workbook-only so the queue fails closed.

### 3. Create Pending Sales Upload Service

Create `PendingSalesUploadService` with the same proven safety responsibilities as the Purchase service:

- private file retention
- sanitized display filename
- ownership and expiry authorization
- checksum verification
- queue grouping and progress
- fresh full Sales preflight on refresh
- immediate completed/cancelled file deletion
- expired record cleanup

Before retention, confirm that the initial issue set is nonempty and entirely Customer-fixable. If a user submits the same bytes for the same reporting month repeatedly, reuse only a still-valid pending record owned by that user or safely replace its retained file; never revive completed, expired, or cancelled records.

### 4. Routes and Controller

Add authenticated token-bound routes:

```text
GET    /pending-sales-uploads/{pendingSalesUpload}
POST   /pending-sales-uploads/{pendingSalesUpload}/refresh
POST   /pending-sales-uploads/{pendingSalesUpload}/retry
DELETE /pending-sales-uploads/{pendingSalesUpload}
```

Controller responsibilities:

- `show`: authorize, check expiry, refresh, and redirect to the current Customer queue item.
- `refresh`: run fresh complete preflight and return the latest queue/progress.
- `retry`: atomically claim the pending row, revalidate everything, and invoke `SalesUploadService::replace()` only when clean.
- `destroy`: mark cancelled and delete the private file.

Use `404` for another user's token and `410` for unavailable/expired workflow state without disclosing another user's upload metadata.

### 5. Customer Fix Context

Extend `CustomerController::index()` to accept:

```text
/customers?pending_sales_upload={token}&queue_item={queue_key}
```

The server must authorize and rebuild the queue, then provide `customerFixContext` containing:

- pending upload label, token, reporting month, and expiry
- initial/resolved/remaining Customer counts
- current server-selected queue item
- safe prefill
- retry and cancel URLs

Customer store/update requests may carry only the pending token and queue key as workflow metadata. Continue using the existing Customer validation and duplicate-TIN safeguards for the actual fields.

After save, redirect back into the authorized context. Do not mark an item fixed merely because the Customer write succeeded.

### 6. Cleanup Scheduling

- Add Sales cleanup to the existing pending-upload cleanup command, or create a combined cleanup service used by the same hourly schedule.
- Check expiry during every show, refresh, retry, and cancel request; the scheduler is housekeeping, not the security boundary.
- Delete private files immediately after successful completion or cancellation.
- Keep minimal database audit metadata while clearing cached issues when appropriate.

## Frontend Plan

### RecordEntry Upload Dialog

For an entirely Customer-fixable rejection:

- show grouped Customer count and worksheet-row count
- show `Fix Customer` on each queue item
- show `Open Fix Queue`
- explain that no Sales records were imported or replaced

For workbook-only or mixed issues:

- keep the normal detailed issue list
- do not show `Open Fix Queue` or `Retry Upload`
- state clearly that the workbook must be corrected and uploaded again

### ManageCustomer

Add a contextual banner similar to the Purchase queue:

- `Sales upload fix queue: <filename>`
- reporting month
- fixed and remaining Customer counts
- affected worksheet rows and fields
- next-item navigation
- `Cancel Queue`
- `Retry Upload`, disabled until fresh preflight is clean

Create mode should prefill only safe available values. Edit mode must open only the confirmed server-selected Customer. Button text may use `Save and Check Again` while queue context is active.

Preserve ordinary Customer management behavior when no authorized queue context exists.

## Security and Concurrency

- Require authentication on every pending route.
- Authorize `user_id` server-side for every operation.
- Use opaque UUID route binding.
- Never expose `stored_path` or provide workbook download access.
- Verify SHA-256 immediately before retry.
- Claim `pending -> retrying` atomically so double-clicks cannot run two replacements.
- Lock or conditionally update the pending row before retry.
- Never trust a Customer ID, queue item, reporting month, file path, or ready flag supplied by the browser.
- Rebuild the queue from the retained workbook and current database state.
- Return to `pending` after a recoverable failure only if the file remains intact and unexpired.

## Atomicity and Compatibility Boundaries

The following must remain unchanged:

- Same-month Sales replacement targets only non-adjusted `sales_vatsinputs` rows.
- No deletion occurs until all fresh preflights pass.
- Delete and import stay in one database transaction.
- Failed retry leaves existing Sales rows intact.
- `SalesVatInputImport` remains the importer for initial and retry paths.
- SI/CM import and DM skip behavior remain unchanged.
- `SalesAmountNormalizer` formulas, one-cent tolerance, VAT treatment, and zero-rated classification remain unchanged.
- `SalesSiCmConsolidator` behavior remains unchanged.
- Customer save synchronization remains existing behavior and is not expanded by this feature.
- DAT generation and attachment rendering are outside this change.

## Test Plan

### Pending Upload and Security

- Customer-only rejection retains one private workbook and creates one pending record.
- Workbook amount-only and mixed Customer/amount failures do not create a pending record.
- Existing same-month Sales rows remain unchanged after every rejected initial upload.
- Another user cannot show, refresh, retry, or cancel the token.
- Expired, cancelled, or completed tokens cannot retry.
- Missing or checksum-mismatched retained files cannot retry.
- Cancel and expiry delete the private file.
- Double retry performs at most one replacement.

### Queue Grouping and Prefill

- Repeated rows for one confirmed Customer group into one item.
- Normalized-name matches open the correct Customer in edit mode.
- Unmatched Sales Summary rows prefill name only.
- Unmatched BIR Sales rows prefill only safe uploaded identity/address values.
- Blank or unsafe identities remain row-specific.
- A client-supplied Customer ID cannot redirect edit mode to another Customer.
- A successful Customer save remains unresolved when fresh preflight still fails.

### Retry and Replacement

- Retry uses the exact retained workbook and original reporting month.
- Fresh type/period failure blocks replacement.
- Fresh Customer or amount failure blocks replacement.
- Successful retry replaces only same-month non-adjusted Sales rows.
- Adjusted Sales rows survive retry.
- Import exception rolls back deletion and imported rows.
- Completed retry deletes the private workbook.
- All-DM retained workbook preserves the existing no-import failure.
- Mixed SI/CM and DM retry preserves the skipped-DM warning.

### Sales Regression

- Sales Summary and BIR Sales imports still work normally without queue context.
- Pure and mixed zero-rated validation remains unchanged.
- Sales consolidation remains unchanged.
- Existing Customer create/update and historical Sales synchronization tests pass.
- Sales records, DAT, ordering, attachment, and upload period/type regression suites pass.

Suggested focused commands:

```text
php artisan test tests\Feature\PendingSalesUploadTest.php
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php tests\Feature\SalesVatInputImportTest.php tests\Feature\SalesZeroRatedImportTest.php
php artisan test --compact --filter=Sales
php artisan route:list --name=pending-sales-uploads
php artisan migrate --pretend
vendor\bin\pint --test
git diff --check
```

Run filesystem-heavy upload tests sequentially or with isolated Laravel storage. Use `Storage::fake('local')` for retained workbook tests. Frontend compilation and browser verification must be reported separately if Node/npm is unavailable.

## Recommended Implementation Order

1. Add failing classification tests proving Customer-only versus workbook-only/mixed behavior.
2. Enrich Sales preflight issues without changing validation results.
3. Extract `SalesUploadService` and prove the ordinary Sales upload behavior is unchanged.
4. Add pending Sales migration, model, private retention service, expiry, and cleanup.
5. Add authorized show/refresh/retry/cancel routes and concurrency handling.
6. Add `customerFixContext` to `CustomerController` and ManageCustomer.
7. Add grouped queue actions to the RecordEntry issue dialog.
8. Run focused Sales upload, zero-rated, Customer, security, replacement, DAT, and attachment regressions.

## Acceptance Criteria

The feature is complete only when:

- Customer-only Sales BIR failures create a private, user-owned fix queue.
- Workbook amount and zero-rated errors never appear as Customer-fixable queue items.
- Add/Edit Customer targets and prefill are derived and authorized by the server.
- Queue progress changes only after fresh complete Sales preflight.
- Retry uses the exact retained bytes and original month.
- Retry repeats every ordinary Sales safeguard before deletion.
- Failed initial upload or retry never replaces existing Sales rows.
- Successful retry preserves adjusted-row exclusion, SI/CM behavior, and DM warnings.
- Expired, cancelled, completed, missing, corrupt, concurrent, and cross-user cases are protected.
- Sales calculations, zero-rated rules, consolidation, DAT output, filenames, totals, rounding, ZIP, and PDF attachments remain unchanged.
