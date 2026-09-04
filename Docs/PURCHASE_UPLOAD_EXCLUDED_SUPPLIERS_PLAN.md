# Purchase Upload Skipped Suppliers Plan

## Goal

During Purchase Excel upload, automatically exclude supplier rows that should not be stored as Purchase VAT records.

Initial skipped supplier:

- `BUREAU OF CUSTOMS`

The user should not need to fix the Excel file just because it contains `BUREAU OF CUSTOMS`. The upload should continue, but those rows should not be saved into `vat_inputs`.

No DAT file layout or DAT generator format should change.

## Reason

`BUREAU OF CUSTOMS` is not part of RELIEF Purchase DAT generation. If the app saves that row as a normal Purchase record, the Generate DAT screen can later show confusing BIR validation errors for a row that should not have been included in the first place.

This should behave like the existing Sales upload handling for DM rows:

- Sales `DM` rows are skipped during upload.
- They are not stored in `sales_vatsinputs`.
- The upload can still succeed when there are valid importable rows.
- The user can receive a warning/count that rows were skipped.

Purchase should follow the same pattern for `BUREAU OF CUSTOMS`.

## Current Flow

Purchase upload currently follows this flow:

1. `app/Http/Controllers/VatInputController.php` validates the request.
2. `UploadWorkbookTypePreflight` checks workbook type and reporting period.
3. `UploadBirInfoPreflight::checkPurchase()` checks supplier BIR information before import.
4. If preflight passes, the controller replaces the selected month records.
5. `VatInputImport` saves Purchase rows into `vat_inputs`.
6. Generate DAT later validates saved `vat_inputs` rows through `BirPurchaseRowValidator`.

The skipped-supplier rule should happen before BIR-info validation and before saving rows, so `BUREAU OF CUSTOMS` does not create missing-TIN or missing-address errors.

## Required Behavior

When uploading a Purchase file:

1. Read each Purchase row supplier name from the workbook.
2. Normalize the supplier name before comparison.
3. If the normalized supplier name matches `BUREAU OF CUSTOMS`, treat the row as skipped.
4. Do not validate skipped rows in `UploadBirInfoPreflight::checkPurchase()`.
5. Do not save skipped rows in `VatInputImport`.
6. Continue importing the remaining valid Purchase rows.
7. Show a clear success warning when one or more rows were skipped.
8. If the workbook has only skipped rows and no importable Purchase rows, show a clear message that no importable Purchase rows were found.

Example warning after successful upload:

```text
Purchase file uploaded, but 2 BUREAU OF CUSTOMS row(s) were skipped because they are not included in RELIEF Purchase DAT.
```

Example message when all rows are skipped:

```text
Purchase upload skipped 2 BUREAU OF CUSTOMS row(s). No importable Purchase rows were found.
```

## Normalization Rule

Use the same supplier-name normalization pattern already used for Purchase supplier matching.

Recommended comparison:

- Convert to uppercase.
- Remove punctuation/special characters that should not affect identity.
- Collapse repeated spaces.
- Compare against normalized skipped supplier names.

This should catch examples like:

- `BUREAU OF CUSTOMS`
- `Bureau of Customs`
- `BUREAU, OF CUSTOMS`
- `BUREAU  OF  CUSTOMS`

## Backend Plan

### 1. Add a Skipped Supplier List

Add one clear owner for the Purchase skipped supplier list.

Preferred option:

- Add a config value such as `config/bir.php`.
- Example key: `purchase.skipped_suppliers`.
- Initial value: `BUREAU OF CUSTOMS`.

Acceptable smaller option:

- Add a private constant in `UploadBirInfoPreflight` and `VatInputImport`.

Config is better if more skipped suppliers may be added later without touching importer logic.

### 2. Skip Before BIR-Info Preflight Issues

Update:

- `app/Imports/UploadBirInfoPreflight.php`

Inside `checkPurchase()`:

1. Extract the row supplier name using the same column handling used by the current Purchase preflight/import.
2. Normalize the supplier name.
3. If it matches the skipped supplier list, increment a skipped count and continue to the next row.
4. Do not run supplier TIN/address validation for the skipped row.

Expected result:

- `BUREAU OF CUSTOMS` rows do not appear in the upload issue dialog.
- Other supplier rows still get normal BIR-info validation.

### 3. Skip During Actual Import

Update:

- `app/Imports/VatInputImport.php`

Inside row handling:

1. Extract and normalize the supplier name.
2. If it matches the skipped supplier list:
   - increment a skipped counter,
   - do not create or update a `vat_inputs` row,
   - return/continue to the next row.

Expose methods similar to Sales DM handling:

```php
public function skippedExcludedSupplierRows(): int
public function importedRows(): int
```

Exact method names can follow the existing local style.

### 4. Return Upload Warning

Update:

- `app/Http/Controllers/VatInputController.php`

After `Excel::import(new VatInputImport(...))`, read the skipped count from the import instance.

If there are imported rows and skipped rows:

- Return normal success.
- Add a warning message saying how many `BUREAU OF CUSTOMS` rows were skipped.

If there are skipped rows but no imported rows:

- Return a clear warning/error-style message that no importable Purchase rows were found.
- Do not present it as a normal successful upload.

Important: same-month replacement behavior must stay safe.

Preferred behavior:

- Run preflight first.
- Delete/replace selected month only after preflight passes.
- Import remaining non-skipped rows.
- If the workbook contains only skipped rows, avoid replacing the existing month with an empty dataset unless the user explicitly asked for that behavior.

### 5. Keep Generate DAT Unchanged

Do not change:

- `app/Services/BIR/ReliefPurchaseDatGenerator.php`
- DAT line layout
- DAT file naming
- Purchase DAT validator rules

This is an upload/import filtering rule, not a DAT format rule.

## Frontend Plan

No new modal is required for `BUREAU OF CUSTOMS` skipped rows.

Use the existing flash warning/success display on the upload page.

Expected UI:

- If upload succeeds with skipped rows, show a success message plus warning text.
- If all rows are skipped, show a clear message that no importable Purchase rows were found.
- Do not open the BIR-info fix dialog for skipped `BUREAU OF CUSTOMS` rows.

The existing upload issue dialog remains only for real fixable BIR-info problems, such as missing Supplier TIN/address for suppliers that should be imported.

## Existing Records

This plan does not automatically delete old saved Purchase rows for `BUREAU OF CUSTOMS`.

Important note:

- After this change, new uploads will not save `BUREAU OF CUSTOMS` rows.
- Old `BUREAU OF CUSTOMS` rows already in `vat_inputs` may still exist until the month is replaced with a corrected upload or cleaned up separately.
- Any cleanup of existing records should be a separate, explicit task.

## Tests

Add focused backend coverage in:

- `tests/Feature/UploadWorkbookTypePreflightTest.php`

Required cases:

1. Purchase upload with one valid supplier row and one `BUREAU OF CUSTOMS` row succeeds.
2. The `BUREAU OF CUSTOMS` row is not saved in `vat_inputs`.
3. The valid supplier row is still saved.
4. Upload response includes a warning/count for skipped `BUREAU OF CUSTOMS` rows.
5. Skipped `BUREAU OF CUSTOMS` rows do not create BIR-info dialog errors.
6. Normalized variants are skipped, such as `Bureau of Customs` and `BUREAU, OF CUSTOMS`.
7. Purchase upload with only `BUREAU OF CUSTOMS` rows does not silently report a normal success.
8. Existing same-month replacement behavior remains protected and does not delete old records before preflight passes.

Regression tests to keep:

- Sales DM skip behavior remains unchanged.
- Purchase DAT generator unit tests remain unchanged and passing.
- Existing Purchase/Sales upload preflight tests remain passing.

## Verification Commands

Run after implementation:

```bash
php artisan test tests\Feature\UploadWorkbookTypePreflightTest.php
php artisan test tests\Unit\ReliefPurchaseDatGeneratorTest.php
git diff --check
```

If PHP linting is desired:

```bash
php -l app\Imports\UploadBirInfoPreflight.php
php -l app\Imports\VatInputImport.php
```

## Non-Goals

- Do not change DAT file generation format.
- Do not apply this rule to Sales uploads.
- Do not show `BUREAU OF CUSTOMS` as a fixable Supplier master-data issue.
- Do not require the user to edit the Excel file before upload.
- Do not delete existing records automatically unless separately requested.
