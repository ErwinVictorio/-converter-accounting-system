# Purchase Adjusted Delete and Services VAT Note Plan

## Status

Implemented on September 14, 2026.

## Implementation Result

- Added `purchase_adjustments` as the source-to-target ledger for every newly saved Purchase adjustment.
- Added an authenticated adjustment-delete context endpoint and Undo-and-Delete endpoint.
- Added transactional, cent-exact restoration to one or multiple original broker rows before deleting an adjusted target.
- Added guarded source allocation for legacy and partially tracked adjusted rows.
- Added Delete or Link & Delete only for `is_adjusted = true` rows in Purchase Records.
- Added the Services VAT-amount warning above the New Adjusted Record form.
- Applied migration `2026_09_14_000000_create_purchase_adjustments_table` to the local database.
- Preserved Purchase upload parsing, target matching, DAT generation, and other record modules.

Verification completed:

- Purchase adjusted delete, merge, and Record page suites: 48 passed, 786 assertions.
- Purchase DAT ordering/generator and Dashboard suites: 26 passed, 286 assertions.
- Upload workbook preflight suite: 38 passed, 326 assertions when run on an isolated Laravel test-storage path.
- Full backend suite: 440 passed, 4,506 assertions; 22 failures outside the changed Purchase paths remain in Auth/Profile scaffolding and one Expanded WTAX test.
- PHP syntax checks, route registration, migration, and `git diff --check` passed.
- Frontend build was attempted but remains unverified because Node/npm is unavailable in this PowerShell environment.

## Requested Outcome

1. Show a Delete action in Purchase Records only when the row has `is_adjusted = true`.
2. Allow a mistaken manually adjusted Purchase row to be removed after confirmation.
3. Show a warning above the New Adjusted Record form that the value entered in Services must be the VAT amount.

## Current Behavior Found

- `resources/js/Pages/Records/PurchaseRecords.jsx` already reads `is_adjusted` and shows the blue Adjusted badge, but it has no Delete action.
- `routes/web.php` has Purchase edit, update, lookup, and BIR-info routes, but no Purchase delete route.
- `VatInputController::update()` subtracts the four entered amounts from the source broker row.
- The same update may add the amounts to an existing uploaded row, add them to an existing adjusted row, or create a new adjusted row.
- Only the fallback row created by the adjustment flow has `is_adjusted = true`. A transfer merged into an uploaded Purchase row remains non-adjusted and therefore would not receive the requested Delete action.
- There is no adjustment-history table or source-row reference. After repeated transfers are merged into one adjusted row, the application cannot determine which broker row supplied each amount.

## Confirmed Delete Behavior

Delete means **undo the adjustment and then remove the adjusted row**.

- Restore each transferred amount to the original broker row from which it was deducted.
- Delete the `is_adjusted = true` target only after every amount has been restored.
- Never offer or perform a raw delete that would make Purchase value disappear.
- If the adjusted row contains transfers from multiple broker rows, return each contribution to its own source.
- Run the full reversal and deletion atomically. Any missing or invalid source must cancel the whole operation.

Example for Services:

```text
Original broker Services before adjustment: 185.84
Amount moved to adjusted row:                 50.00
Broker Services after adjustment:            135.84

Delete reversal: 135.84 + 50.00 = 185.84
Adjusted target: deleted only after restoration succeeds
```

## Implementation Plan

### 1. Store adjustment history

Add a focused `purchase_adjustments` table and model containing:

- source broker `vat_input` ID
- target Purchase `vat_input` ID
- transferred `purchase_imported`, `purchase_local`, `services`, and `others`
- timestamps

Use a nullable source reference so a later same-month Purchase re-upload does not fail when it replaces the original non-adjusted broker row. If the source becomes unavailable, refuse deletion until the transfer is mapped to the correct current broker row. Cascade history removal only after its target is deliberately deleted.

Update `VatInputController::update()` so every successful transfer creates one history record inside the existing database transaction, whether the target is a new adjusted row, an existing adjusted row, or an uploaded row. This must not change the existing target-priority or amount calculations.

One history row must be created per Save action. Do not replace earlier history when another transfer is merged into the same adjusted target.

### 2. Add the protected delete endpoint

Add an authenticated named DELETE route for a Purchase record and a dedicated controller method.

The backend, not only the button, must enforce:

- the target exists
- `is_adjusted = true`
- the target is not an Importation mirror
- non-adjusted uploaded Purchase rows cannot be deleted through this endpoint

Perform the operation in one database transaction with row locks:

1. Lock the adjusted target and its adjustment-history rows.
2. Confirm that the history totals exactly cover the adjusted target's four transferable amount fields.
3. Lock every source broker row referenced by that history.
4. Add each history row's exact four amounts back to its own source broker row.
5. Restore the source `total` as the exact inverse of the amount previously deducted; do not reinterpret or recalculate unrelated stored fields.
6. Delete the adjusted target and its history only after every source restoration succeeds.
7. Return to Purchase Records with a clear success message.

If any source row is missing, any history amount is invalid, or the history total does not equal the adjusted row, roll back the full transaction and keep every record unchanged.

### 3. Handle existing adjusted rows without history

Rows created before this feature have no reliable source link. They must not be permanently deleted without restoration.

For those legacy or partially tracked rows:

1. Calculate the untracked residual per amount field by subtracting recorded history totals from the adjusted row.
2. Show a Link Original Broker action instead of allowing immediate deletion.
3. Offer only non-adjusted broker candidates from the same `date_uploaded` and the same `is_imported` bucket.
4. Let the user allocate the residual amounts to one or more original broker rows when multiple sources contributed.
5. Require the allocations for each field to equal the complete untracked residual exactly.
6. Save those mappings as adjustment-history rows.
7. Run the normal undo-and-delete transaction only after the adjusted row is fully traceable.

Do not guess the source from TIN, supplier name, row order, or current balances. If the correct source cannot be identified, keep the adjusted row and show a clear reconciliation message.

### 4. Add the conditional Purchase Records action

In `resources/js/Pages/Records/PurchaseRecords.jsx`:

- import the Trash icon
- render Delete only for `isAdjusted`
- keep View Info and BIR Info unchanged
- do not show Delete for normal uploaded rows, broker rows, or Importation mirrors
- open a confirmation dialog naming the supplier, showing the four amounts to be returned, and explaining that the adjusted entry will be undone and removed
- submit the DELETE request with Inertia and preserve the active search/month context where possible
- use the existing flash/toast handling for success and server refusal messages
- show Link Original Broker instead when the backend marks the adjusted row as having incomplete history

Use the existing project Dialog and Button components so the confirmation matches the current UI.

### 5. Add the Services VAT warning

In `resources/js/Pages/EditVatInputRecord.jsx`, place an amber warning block directly below the New Adjusted Record header and above the form fields.

Suggested copy:

> Important: Enter the VAT amount in the Services field, not the VAT-exclusive purchase amount.

This is guidance only. Do not change validation, conversion, totals, stored values, or adjustment calculations as part of this UI request.

### 6. Add focused automated coverage

Extend `PurchaseAdjustmentMergeTest` and/or add a focused adjusted-delete feature test for:

1. Delete succeeds for an adjusted row with tracked history.
2. All transferred buckets are restored to each existing source broker row.
3. A combined adjusted row with multiple transfers restores every source exactly once.
4. The adjusted target and its history are deleted atomically.
5. A direct DELETE request for `is_adjusted = false` is rejected and preserves the row.
6. An Importation mirror cannot be deleted through the Purchase adjusted-delete route.
7. A legacy or partially tracked row cannot be deleted until its residual is fully allocated.
8. Legacy allocations can be divided between multiple valid source broker rows and must equal the residual exactly per field.
9. Invalid, cross-month, cross-imported-bucket, or guessed source mappings are rejected.
10. A missing source or incomplete history rolls back the entire delete operation.
11. An unauthenticated request is redirected to login.
12. Existing adjustment creation, target lookup, merge priority, repeated-entry reset, and BIR field-limit tests still pass.

The warning note is a frontend rendering change; verify it manually or with the project's available frontend test/build tooling.

## Verification Commands

```text
php -l app/Http/Controllers/VatInputController.php
php -l app/Models/PurchaseAdjustment.php
php artisan route:list --path=records
php artisan test --compact --filter=PurchaseAdjustmentMergeTest
php artisan test --compact --filter=RecordPagesTest
npm run build
git diff --check
```

If Node/npm remains unavailable in the Windows shell, report the frontend build as unverified rather than treating the backend test results as frontend verification.

## Compatibility Boundaries

- Do not change Purchase DAT layout, field order, formatting, filenames, or generator calculations.
- Do not change Purchase upload parsing or same-month replacement rules.
- Do not change the current adjustment target priority or TIN/month/imported matching.
- Do not add delete actions to Sales, Importation, or Expanded WTAX.
- Do not expose deletion for ordinary uploaded Purchase rows.
- Do not delete an adjusted row unless all of its amounts are traceable and restored.
- Do not reinterpret or convert the Services input in this scope; only add the requested warning.

## Acceptance Criteria

- Only rows visibly marked Adjusted offer Delete.
- A direct URL cannot delete a non-adjusted Purchase row.
- The user must confirm before deletion.
- Deleting an adjusted row restores every transferred amount to the correct original broker row and leaves no partial changes.
- Multiple transfers merged into one adjusted row are restored to their respective source rows exactly once.
- Legacy and partially tracked rows must be completely mapped before deletion; raw deletion is never allowed.
- The VAT-amount warning is visible before the user reaches the Services input.
- Existing upload, DAT, listing, filtering, adjustment, and BIR-info behavior remains unchanged.
