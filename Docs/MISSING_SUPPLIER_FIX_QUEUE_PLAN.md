# Missing Supplier Fix Queue and Retry Upload Plan

## Implementation Status

Implemented on 2026-09-11 for Purchase uploads.

The implementation adds a private, user-owned pending workbook, grouped Supplier issues, server-authorized create/edit prefill, queue progress, fresh revalidation, atomic Retry Upload, cancellation, 24-hour expiry, and scheduled cleanup. The same `PurchaseUploadService` now owns the normal initial Purchase replacement and pending Retry path so their preflight, exclusion, replacement, and warning behavior cannot drift.

Verification completed:

- New pending Purchase upload suite: 5 tests, 98 assertions.
- Focused upload and Supplier suites together: 49 tests, 460 assertions.
- Purchase DAT, ordering, text-validation, record-page, and View Info regressions: 61 tests, 1,171 assertions.
- Entire project suite: 423 tests passed; 22 unrelated pre-existing scaffold/fixture failures remain in removed auth/profile features and one Expanded WTAX title-row fixture.
- PHP syntax, Pint formatting, route registration, migration dry run, real local migration, cleanup command, and `git diff --check` passed.
- Frontend production build was not run because Node/npm is unavailable in the current shell.

No DAT generator, DAT layout, calculation, amount, replacement boundary, filename, ZIP, or PDF attachment code was changed.

## Goal

Turn a rejected Purchase upload with missing or invalid Supplier BIR information into a guided repair workflow:

1. Show one grouped queue item per affected supplier instead of forcing the user to work through repeated worksheet-row errors.
2. Let the user open a prefilled Add Supplier or Edit Supplier form with one click.
3. Track which supplier problems remain after each save.
4. Retry the exact original Purchase workbook without asking the user to select it again.
5. Re-run every normal Purchase preflight before replacing any saved records.

The workflow must preserve the current safety rule: a rejected or still-invalid upload must not delete or replace existing Purchase rows.

## Current Confirmed Behavior

The required foundations already exist:

- `VatInputController::import()` validates the file, selected type, and reporting month.
- `UploadWorkbookTypePreflight` rejects the wrong workbook type or reporting period.
- `UploadBirInfoPreflight::checkPurchase()` resolves Supplier master data using the same TIN/name priority used by the Purchase importer.
- Purchase BIR issues are returned as structured `uploadIssueDialog.issues` entries containing worksheet row, supplier name, field, problem, fix route, needed fields, and match basis.
- `RecordEntry.jsx` opens the issue dialog automatically and currently provides one general `Open Suppliers` button.
- The Purchase delete-and-import work runs only after the preflights pass and is wrapped in `DB::transaction`.
- `ManageSupplier.jsx` already supports create, edit, delete, TIN/name filters, pagination, server errors, and the current BIR field limits.
- `SupplierController` rejects invalid/zero base TINs and duplicate first-nine-digit TINs.

Current limitation:

- The uploaded browser `File` cannot be carried through normal navigation to `/suppliers` and back.
- The current issue payload does not include a Supplier ID or enough raw workbook values to choose reliably between Add and Edit and prefill the form.
- Repeated issues for the same supplier are displayed as separate worksheet-row errors.
- After fixing Suppliers, the user must manually return to Import Data, select the workbook again, and upload again.

## Scope

Included:

- Purchase uploads rejected specifically by Supplier BIR-info preflight.
- Private, temporary retention of the exact rejected workbook.
- A user-owned pending Purchase upload record with expiry and status.
- Grouping row-level issues into supplier-level queue items.
- Prefilled Add/Edit Supplier navigation.
- Queue progress refresh after Supplier create/update.
- Server-side Retry Upload using the retained workbook.
- Cleanup of expired pending uploads and their private files.
- Focused backend and frontend regression coverage.

Not included:

- Sales/Customer fix queues.
- Expanded WTAX or Importation retry queues.
- Editing the Excel workbook inside the application.
- Automatic guessing of missing addresses or cities.
- Automatic changes to existing saved Purchase records when a Supplier is edited.
- Changes to Supplier matching priority.
- Changes to Purchase calculations, replacement scope, stored values, skipped-supplier rules, or DAT validation.
- Any DAT generator, header, detail field, delimiter, filename, total, rounding, ZIP, or PDF attachment change.

## Required User Flow

### 1. Initial Purchase Upload

1. User selects a Purchase workbook and reporting month on Import Data.
2. The server runs the existing request, type/period, and Purchase BIR-info validation.
3. If Supplier BIR issues exist, the server stores the exact validated workbook on the private disk and creates a pending-upload record.
4. No Purchase records are deleted or imported.
5. The rejection dialog shows:
   - number of affected suppliers
   - number of affected worksheet rows
   - grouped Supplier queue items
   - required fields per supplier
   - `Fix Supplier` action per item
   - `Open Fix Queue` action

### 2. Fix a Supplier

For each grouped queue item:

- If the preflight matched an existing Supplier, `Fix Supplier` opens `/suppliers` and automatically opens the Edit Supplier dialog for that exact Supplier.
- If no Supplier matched, it opens `/suppliers` with the Add Supplier form prefilled from safe workbook values.
- Prefill only values actually obtained from the workbook or matched master record:
  - TIN
  - Supplier Name
  - Address
  - City
- Missing values stay blank and remain required. Do not invent or infer BIR identity/address data.
- Show a small banner such as `Fixing Purchase upload: May 2026 - purchases.xlsx` and list the worksheet rows affected by this supplier.

After a successful Supplier create/update:

1. Keep the user in the same pending-upload context.
2. Re-run the pending workbook's Purchase BIR preflight.
3. Refresh the grouped queue.
4. Mark a supplier as resolved only from the new preflight result, not merely because the form saved successfully.
5. Move automatically to the next unresolved supplier when practical.

### 3. Retry Upload

- Keep `Retry Upload` disabled while any Supplier BIR queue items remain.
- When the queue is clear, enable `Retry Upload`.
- Retry must use the retained original workbook, record type `purchase`, and original reporting month.
- The server must run all current request-equivalent safeguards again:
  1. confirm the pending upload belongs to the authenticated user
  2. confirm status is retryable and has not expired
  3. confirm the private file exists and matches its stored checksum
  4. run workbook type/period preflight again
  5. run Purchase BIR-info preflight again
  6. enter the existing atomic replacement/import transaction only if all checks pass
- On success:
  - mark the pending upload `completed`
  - delete the private temporary workbook after the database transaction commits
  - redirect to Purchase Records or show the existing success state
- If validation still fails:
  - do not replace any existing rows
  - refresh the queue from the new issues
  - keep the pending upload available until expiry

## Data Design

### Pending Purchase Uploads Table

Create a narrow table such as `pending_purchase_uploads` with:

| Column | Purpose |
| --- | --- |
| `id` | Internal primary key. |
| `token` | Unique opaque UUID exposed to the UI; do not expose sequential IDs. |
| `user_id` | Owner; every view/fix/retry request must authorize against it. |
| `original_name` | Display-only original filename. |
| `stored_path` | Generated path on the private `local` disk. |
| `mime_type` | Validated uploaded MIME type for diagnostics. |
| `size` | Validated size for diagnostics and integrity checks. |
| `sha256` | Checksum proving Retry uses the retained original bytes. |
| `reporting_period` | Original normalized month-end date. |
| `status` | `pending`, `retrying`, `completed`, `expired`, or `cancelled`. |
| `issues` | Latest raw structured preflight issues as JSON, optional cache only. |
| `expires_at` | Expiry boundary, recommended 24 hours after creation. |
| timestamps | Creation/update audit timing. |

Model recommendations:

- Cast `issues` to array, `reporting_period` to date, and `expires_at` to datetime.
- Add indexes for `token`, `user_id + status`, and `expires_at`.
- Treat cached `issues` as UI data only. Retry authorization and validity must come from a fresh preflight.
- Store files under a generated path such as `pending-purchase-uploads/{uuid}.{extension}` on the private disk; ownership is enforced by the pending-upload database record and authenticated endpoints.
- Never place pending workbooks under `storage/app/public` and do not add a download route.

### Queue Item Shape

Extend Purchase preflight issue metadata, or add a dedicated queue builder, so each raw issue can carry:

```text
supplier_id            nullable; set only for a confirmed master-data match
identity_key           stable normalized full/base TIN or normalized name fallback
raw_tin                workbook value, normalized for form display
raw_name               workbook Supplier name
raw_address1           workbook Address1 fallback when present
raw_address2           workbook City/Address2 fallback when present
row                    worksheet row
field                  vendor_tin, company_name, address1, or address2
problem                existing actionable validation message
match_basis            existing lookup explanation
```

Build UI queue items by `supplier_id` when available; otherwise group by the same normalized identity priority used by Purchase import:

1. normalized full 12-digit TIN
2. first 9 TIN digits
3. `Supplier::normalizeName()`

Each grouped queue item should return:

```text
queue_key
supplier_id
mode                    edit or create
display_name
affected_rows           unique sorted worksheet rows
missing_fields          unique fields that still block upload
problems                unique problem messages
prefill                 tin, name, addr, city
fix_url
```

Do not group two unrelated rows only because both names are blank or both TINs are invalid. If there is no safe identity key, keep the worksheet row as part of the queue key.

## Backend Plan

### 1. Extract One Purchase Upload Execution Path

Avoid implementing Retry as a second copy of `VatInputController::import()`.

Create a focused service, for example `PurchaseUploadService`, that accepts a readable workbook path/file plus reporting period and performs:

1. workbook type/period preflight
2. Purchase BIR preflight
3. structured rejection result when issues remain
4. existing `DB::transaction` replacement/import when valid
5. existing skipped-supplier behavior and success/warning result

Use the same service from:

- initial `POST /vat-import` Purchase branch
- pending-upload Retry endpoint

This keeps initial upload and Retry behavior identical and prevents drift in deletion exclusions, validation, or messages.

### 2. Create Pending Upload Only for Fixable Supplier Issues

In the initial Purchase branch:

- Validate file type/size and workbook type/period first.
- Run `checkPurchase()`.
- When Supplier BIR issues remain, store the file privately and create/update the pending-upload record.
- Return the pending token and grouped queue in `uploadIssueDialog`.
- Do not create a pending upload for request-validation errors, wrong file type, wrong reporting month, unreadable workbooks, or non-Supplier failures that cannot be fixed from Manage Suppliers.

Prevent duplicate abandoned records from repeated submission of the same file by considering a user + SHA-256 + reporting-period pending match. Reuse or replace only a still-valid `pending` record; never revive a completed/expired record silently.

### 3. Add a Queue Builder/Refresh Service

Create a service responsible for:

- opening the authorized retained file
- re-running `UploadBirInfoPreflight::checkPurchase()`
- enriching issues with confirmed Supplier IDs and safe prefill values
- grouping issues into supplier queue items
- updating the cached latest issues
- returning progress counts

Suggested progress response:

```json
{
  "affected_suppliers": 3,
  "affected_rows": 7,
  "remaining_suppliers": 2,
  "ready_to_retry": false,
  "items": []
}
```

### 4. Routes and Controller Responsibilities

Add authenticated routes with route-model binding by opaque token, for example:

```text
GET    /pending-purchase-uploads/{token}
POST   /pending-purchase-uploads/{token}/refresh
POST   /pending-purchase-uploads/{token}/retry
DELETE /pending-purchase-uploads/{token}
```

Responsibilities:

- `show`: authorize owner, reject expired/completed tokens, and return current queue/progress.
- `refresh`: re-run preflight and return the latest queue after a Supplier save.
- `retry`: lock the pending row, prevent concurrent retries, revalidate, and execute the shared Purchase upload service.
- `destroy`: cancel the pending upload and remove its private file.

All endpoints must require authentication and ownership. Return `404` or `403` without revealing whether another user's token exists.

### 5. Supplier Prefill Context

Extend `SupplierController::index()` to accept pending-upload context parameters, for example:

```text
/suppliers?pending_upload={token}&queue_item={queue_key}
```

The server must:

1. authorize the pending token
2. rebuild or load the current queue safely
3. find the requested queue item
4. return a `supplierFixContext` Inertia prop containing:
   - pending token and upload label
   - queue key and affected worksheet rows
   - `mode: create|edit`
   - confirmed `supplier_id`, when editing
   - safe prefill values
   - remaining supplier count
5. ignore/reject a client-supplied Supplier ID that does not match the server-built queue item

Supplier create/update requests made from this context should carry only the pending token and queue key as workflow metadata. Continue validating the actual Supplier fields with the existing `SupplierController` rules.

After save, redirect back to the authorized queue context and refresh it. A successful Supplier save must not bypass the pending workbook preflight.

### 6. Expiry and Cleanup

- Recommended lifetime: 24 hours, configurable in `config/bir.php` or a dedicated upload config value.
- Add a cleanup command/task that marks expired rows and deletes their private files.
- Also check expiry on every show, refresh, and retry request so security does not depend on the scheduler running.
- Delete completed/cancelled files immediately when safe.
- Keep only minimal metadata after completion if an audit record is wanted; never retain workbook contents indefinitely by default.

### 7. Transaction and Concurrency Rules

- Acquire a row lock on the pending upload during Retry.
- Accept Retry only from `pending`; move it to `retrying` while processing.
- If fresh preflight returns issues, restore `pending`, update issues, and make no Purchase-row changes.
- Execute the current Purchase deletion and import in one `DB::transaction`.
- Mark `completed` only after that transaction succeeds.
- If import throws, roll back Purchase changes and restore a retryable `pending` status unless the file itself is corrupt/unusable.
- Two browser tabs must not be able to import the same pending upload twice.

## Frontend Plan

### 1. RecordEntry Rejection Dialog

Extend the existing structured dialog in `resources/js/Pages/RecordEntry.jsx`:

- Keep raw row-level problems available under a details section.
- Show grouped supplier cards first.
- Each card displays supplier name/TIN, affected rows, missing fields, and unique problems.
- Add `Fix Supplier` per card using its server-provided `fix_url`.
- Add `Open Fix Queue` for the first unresolved item.
- Show `Retry Upload` only when a pending token exists; disable it until `ready_to_retry` is true.
- Keep `Copy Errors` and `Close` behavior.

Do not store the workbook itself in React state for this workflow after navigation. The pending token represents the server-retained private file.

### 2. ManageSupplier Fix Mode

Extend `resources/js/Pages/ManageSupplier.jsx` only when `supplierFixContext` exists:

- Display a visible fix-queue banner with filename, reporting month, and progress.
- Prefill the normal Add form for `mode=create` and focus the first missing field.
- Open the existing Edit dialog for `mode=edit` and focus the first missing field.
- Show affected worksheet rows and problem messages near the form.
- Label the submit action `Save and Check Again` in fix mode; retain normal `Submit` / `Save Changes` outside fix mode.
- Provide `Previous`, `Next unresolved`, `Back to Import Data`, and `Cancel pending upload` actions where applicable.
- Once the refreshed queue is empty, show `All supplier issues are fixed` and enable `Retry Upload`.

Normal `/suppliers` CRUD, filters, pagination, and dialogs must behave exactly as before when no pending token is present.

### 3. Retry UI State

During Retry:

- disable repeat clicks
- show a spinner and `Revalidating and importing...`
- handle `expired`, `already completed`, `file missing`, and `new issues found` distinctly
- on success, clear pending queue state and private-file references and show the normal Purchase import success message

## Validation and Safety Rules

- Never trust prefill values as validated values; existing Supplier validation remains authoritative.
- Never trust cached queue status for Retry; fresh workbook preflight is authoritative.
- Never replace saved Purchase rows until all current preflights pass.
- Preserve the current exclusion of Importation-linked `vat_inputs` during Purchase month replacement.
- Preserve existing adjusted rows and the current `is_adjusted = false` replacement boundary.
- Preserve configured skipped suppliers such as Bureau of Customs.
- Keep the private workbook scoped to the authenticated owner.
- Do not expose physical storage paths in Inertia props, URLs, logs, or validation messages.
- Escape original filenames when displaying them; use generated storage filenames.
- Do not use query-string TIN/name/address values as the source of truth for prefill. Obtain them from the authorized server-side queue item.

## Tests to Add

### Pending Upload and Security Tests

- A Purchase BIR rejection creates one pending upload owned by the authenticated user.
- The stored workbook is on the private disk and its checksum matches the original upload.
- Wrong workbook type/period and ordinary request validation errors do not create pending uploads.
- Another user cannot view, refresh, retry, or cancel the token.
- Expired, cancelled, and completed tokens cannot be retried.
- Cleanup removes expired private files and marks/removes the matching records as designed.

### Queue Grouping Tests

- Multiple row/field errors for one Supplier produce one queue item with unique sorted rows and fields.
- Existing matched Supplier produces `mode=edit` and the correct `supplier_id`.
- Unmatched Supplier produces `mode=create` with safe TIN/name/address/city prefill only when present.
- Full 12-digit TIN, base 9-digit TIN, and normalized name use the existing match priority.
- Two identity-less rows are not incorrectly merged.
- After Supplier save, refresh removes only issues actually resolved by fresh preflight.

### Retry and Atomicity Tests

- Retry is refused while fresh Supplier BIR issues remain.
- Successful Retry imports the retained exact workbook and replaces only the intended Purchase month rows.
- Existing month rows remain unchanged when Retry preflight fails.
- Existing month rows roll back when Retry import throws after deletion begins.
- Importation-linked Purchase mirrors and adjusted rows remain protected by the existing replacement query.
- A successful Retry marks the pending upload completed and deletes its private file.
- Concurrent/double Retry imports only once.
- Initial upload and Retry return equivalent success/warning behavior for skipped configured suppliers.

### Supplier Page Tests

- Authorized fix URL returns `supplierFixContext` with correct create/edit mode and prefill.
- Tampered token, queue key, or Supplier ID cannot expose or edit unrelated data.
- Supplier create/update still enforces required fields, BIR limits, valid base TIN, and duplicate base-TIN rejection.
- Successful fix-mode save returns to the same pending queue context.
- Normal Supplier CRUD without pending context remains unchanged.

### Frontend Checks

- Rejection dialog shows grouped queue items and affected row counts.
- `Fix Supplier` opens the correct prefilled create/edit state.
- Fix-mode banner and progress remain visible across Supplier pagination/filter navigation.
- Retry remains disabled while unresolved items exist.
- Retry becomes enabled only after server refresh reports zero unresolved items.
- Normal Supplier Management and non-Purchase upload dialogs remain unchanged.

## Acceptance Criteria

The feature is complete when:

1. A Purchase upload rejected for Supplier TIN/address/city problems shows all affected suppliers in a grouped queue.
2. Each queue item opens the correct prefilled Add or Edit Supplier form with one click.
3. Fixing one supplier and saving refreshes progress from the retained workbook.
4. The exact original workbook can be retried without selecting it again.
5. Retry cannot proceed while fresh Supplier BIR issues remain.
6. A failed initial upload or Retry never deletes/replaces existing Purchase records.
7. Successful Retry follows the exact existing Purchase replacement/import rules.
8. Pending files are private, user-owned, expiring, and cleaned up.
9. Normal Supplier CRUD and all other upload types continue to work unchanged.
10. No DAT format, calculation, generator, filename, totals, or attachment behavior changes.

## Recommended Implementation Order

1. Add pending-upload migration/model, private storage service, ownership policy, and expiry cleanup.
2. Extract the current Purchase preflight/replacement/import path into one reusable service and cover it with regression tests.
3. Enrich Purchase issue metadata and add supplier-level queue grouping.
4. Persist rejected workbooks only after file and workbook type/period checks pass.
5. Add pending show/refresh/cancel/retry endpoints with authorization and locking.
6. Add Supplier fix-context props and redirect-back behavior.
7. Add grouped dialog, prefilled Add/Edit state, progress, and Retry UI.
8. Run focused upload, Supplier, period/replacement, and DAT regression suites.

## Verification Commands

Recommended focused backend coverage after implementation:

```powershell
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php
php artisan test tests\Feature\MasterDataTinUniquenessTest.php
php artisan test tests\Feature\PendingPurchaseUploadTest.php
php artisan test tests\Feature\PurchaseSupplierFixQueueTest.php
```

Syntax and route checks:

```powershell
php -l app\Http\Controllers\VatInputController.php
php -l app\Http\Controllers\SupplierController.php
php artisan route:list --path=pending-purchase-uploads
```

Frontend build when Node/npm is available:

```powershell
npm run build
```

DAT regression coverage must be included because Supplier identity data ultimately feeds Purchase DAT rows, even though this feature must not edit the DAT generator:

```powershell
php artisan test tests\Unit\ReliefPurchaseDatGeneratorTest.php
php artisan test tests\Feature\DatFileAlphabeticalOrderingTest.php
php artisan test tests\Feature\DatTextValidationRelaxationTest.php
```
