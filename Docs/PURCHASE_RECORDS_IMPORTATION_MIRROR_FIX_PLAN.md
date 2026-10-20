# Purchase Records Importation Mirror Fix Plan

## Goal

Keep standalone Importation entries out of **Purchase Records** in every view, while preserving their dedicated **Importation Records** listing and their current Importation DAT workflow. Regular local and imported purchases uploaded as Purchase data must continue to appear as they do today.

## Current behavior and cause

`ImportationEntryWriter::syncVatInput()` creates or updates a `vat_inputs` mirror for each `importation_entries` row. It marks that mirror `is_imported = true` and puts taxable goods in `purchase_imported`. The mirror supports existing internal VAT/DAT handling; it is not an ordinary supplier purchase.

`VatInput::scopeExcludingImportationMirrors()` already identifies those rows through `importation_entries.vat_input_id`. Several consumers use this scope, including Purchase DAT and dashboard queries. But `RecordController::purchases()` applies the scope only when the user selects a company or "Unassigned Purchase records". The default "All companies / unassigned" path does not apply it, so mirrored rows leak into Purchase Records and display a nonzero **Purchase Imported** amount.

## Intended behavior

- Every Purchase Records request excludes rows referenced by `importation_entries.vat_input_id`, regardless of company filter, period, search, sorting, or pagination.
- Ordinary `vat_inputs` rows remain visible, including genuine imported purchase rows.
- Importation Records continues to list the original `importation_entries` rows.
- Importation DAT generation continues to use the Importation entries and produces the same output.
- Purchase DAT continues to exclude Importation mirrors, preventing duplicate reporting.
- No existing database rows are deleted or rewritten as part of this fix.

## Implementation plan

1. **Apply the existing mirror exclusion unconditionally**
   - In `RecordController::purchases()`, construct the base purchase query with `excludingImportationMirrors()` before applying the optional company selector.
   - Keep company selection behavior intact: `unassigned` still means rows with a null filing company; a selected company still resolves through `PurchaseCompanyDirectory` and uses `forFilingCompany()`.
   - Remove the repeated conditional calls to the exclusion scope so there is one clear invariant for the entire Purchase Records query.
   - Keep period/search filters, sorting, pagination, amount presentation, and adjustment-history handling unchanged.

2. **Add a regression case to `RecordPagesTest`**
   - Create a regular local Purchase row and an Importation entry with its linked mirror, using the same writer/sync path as production.
   - Assert the default Purchase Records response contains the local row but not the mirror.
   - Assert the result remains correct under the `unassigned` filter and a selected company filter, covering all branches that previously applied the scope inconsistently.
   - Assert Importation Records still contains the Importation entry.
   - If a suitable genuine imported Purchase fixture is used, assert it remains visible, proving the fix excludes only linked Importation mirrors and does not hide all rows with `is_imported = true`.

3. **Preserve DAT behavior with existing coverage**
   - Keep the existing `ImportationEntryTest` check that an Importation sync produces a row internally, writes to the Importation DAT, and is rejected/excluded from the Purchase DAT.
   - Keep the existing `ImportationUploadTest` check for uploaded entries being included in the Importation DAT and excluded from Purchase DAT.
   - No DAT serializer or Importation writer changes are needed for this listing-only query fix.

## Verification after implementation

Run the focused feature tests:

```powershell
php artisan test --filter=RecordPagesTest
php artisan test --filter=ImportationEntryTest
php artisan test --filter=ImportationUploadTest
```

Review the diff to confirm only the Purchase Records query and focused regression coverage changed. In the UI, check **Purchase Records** with no company selection and confirm linked Importation mirrors are absent while normal local/imported purchase rows remain; then check **Importation Records** and confirm the original entry is still present.

## Scope and risks

- This plan changes listing visibility only. It does not alter `vat_inputs` data, Importation entry creation/edit/delete, DAT serialization, or VAT calculations.
- The exclusion depends on the `importation_entries.vat_input_id` link. Historical mirror rows without that link cannot be identified by this scope; investigate those separately before considering any data repair.
- Do not filter by `is_imported` alone: ordinary imported purchase rows are valid Purchase Records and should remain visible.

## Completion criteria

- Purchase Records hides linked Importation mirrors in the default and filtered views.
- Valid standalone imported Purchase rows are still listed.
- Importation Records and Importation DAT behavior remain intact.
- Focused tests pass and the diff contains no data migration or destructive cleanup.
