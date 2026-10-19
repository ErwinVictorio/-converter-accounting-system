# Importation Upload Duplicate Entry No. Plan

## Status

Implemented. Uploads now allow duplicate normalized Entry Nos per tax month; manual duplicate checks and required field validation remain in place.

## Goal

Allow an Importation workbook upload to contain multiple rows with the same normalized `Import Entry No.` for the same tax month. Keep the existing required-field and business validation for every row, including `Assessment / Release Date`.

This plan scopes the duplicate allowance to workbook uploads. Manual create and edit duplicate validation should remain unchanged unless the business rule is explicitly expanded later.

## Current Behavior and Cause

The current upload path rejects duplicates before saving:

1. `app/Imports/ImportationEntryImport.php` normalizes each row's tax month and Entry No., builds a duplicate key, and reports repeated keys with worksheet row numbers.
2. `app/Services/ImportationEntryWriter.php` rejects an Entry No. that already exists for the same tax month unless the caller explicitly permits existing duplicates.
3. The `importation_entries` table has a unique index on `tax_month` and `import_entry_no`, so the database rejects duplicates even if application validation allows them.

The upload first validates and prepares the entire workbook, then replaces the months represented in the workbook and saves prepared rows inside a database transaction. A failed validation therefore leaves existing month data intact.

The `Assessment / Release Date is required` errors are independent of duplicate checking. The importer requires this value, and the shared writer validates it as a required date. Allowing duplicate Entry Nos does not make a blank or unreadable assessment date acceptable.

## Intended Rules

- Multiple workbook rows may share the same Entry No. and tax month.
- Duplicate matching continues to use the existing normalized tax month and BIR-normalized Entry No. semantics, for consistent handling of case, spacing, and punctuation.
- Each duplicate row remains a separate importation record with its own worksheet data, computed amounts, VAT mirror row, and sequence number.
- Manual record creation and editing continue to reject duplicate Entry Nos for the same tax month.
- All other upload validations remain active, including required assessment/release, importation, and payment dates; required text and amounts; VAT rate checks; and amount consistency checks.
- Any invalid row continues to reject the whole workbook before month replacement or inserts.
- Existing upload behavior remains: successful uploads replace all Importation entries for each tax month represented in that workbook.
- DAT format, field order, calculations, record ordering, and Purchase DAT exclusion behavior remain unchanged.

## Database and Compatibility Considerations

The current unique index prevents this feature even after importer validation changes. Add a forward migration that drops the unique composite index on `tax_month` and `import_entry_no` and creates a non-unique lookup index over those columns. The migration must use the actual index name from the migration/schema and work with the project's supported database driver(s).

The rollback migration should restore the unique index only if the data contains no duplicate `(tax_month, import_entry_no)` pairs. If duplicates exist, rollback cannot safely restore the constraint; the migration should fail clearly rather than deleting or rewriting user records. Document this deployment constraint.

Before deployment, inspect the target database for pre-existing duplicates under the same normalized identity rule. The current database constraint should prevent exact stored duplicates, but a pre-deployment read-only check is still useful for verifying schema drift and alternate database environments.

## Implementation Plan

1. **Confirm scope**
   - Treat the request as upload-only duplicate allowance.
   - Keep manual create/edit uniqueness intact.
   - Keep `Assessment / Release Date` required.

2. **Update workbook validation**
   - Remove the importer's in-file duplicate rejection and its duplicate tracking state.
   - Keep per-row preparation and validation unchanged.
   - Preserve worksheet row numbers for all remaining validation errors.

3. **Update the shared writer interface safely**
   - Allow the upload save path to bypass only the application-level duplicate query.
   - Keep the default behavior for manual create and edit duplicate checks.
   - Ensure upload creation still uses the same normalization, computed payload, VAT mirror sync, and sequence assignment as existing rows.

4. **Change the schema constraint**
   - Add a migration to replace the unique composite index with a regular composite index.
   - Add a guarded rollback that refuses to recreate uniqueness if duplicates are present.
   - Do not modify or delete existing records as part of the migration.

5. **Preserve atomic replacement semantics**
   - Keep validation of every workbook row before transactionally deleting/replacing affected months.
   - Keep month replacement and all row/VAT mirror creation within one transaction.
   - Confirm that a failure during row creation rolls back month replacement and mirror writes.

6. **Keep UI behavior unchanged**
   - No frontend changes are needed for duplicate acceptance.
   - Existing success and failure flash messages should remain accurate.
   - Users must still correct missing Assessment / Release Date values before uploading successfully.

## Focused Verification Plan

Add or update feature tests to cover:

1. Two rows with the same Entry No. and tax month in one workbook upload successfully and produce two `importation_entries` records.
2. Both duplicate rows create separate, correctly linked `vat_inputs` mirror records.
3. Duplicate rows receive distinct sequential numbers within their tax month, preserving workbook order.
4. Entry Nos that differ only by formatting handled by `birText()` follow the same duplicate-allowed upload behavior.
5. Uploading a duplicate Entry No. that already exists in the month still succeeds because successful upload replaces that month before saving.
6. Manual create and edit continue to reject a duplicate Entry No. in the same tax month.
7. A duplicate row with a missing Assessment / Release Date still causes the whole upload to fail with its worksheet row number, and existing month data remains untouched.
8. A valid duplicate workbook still replaces only months present in the workbook.
9. Importation DAT includes each uploaded row in the established order and format; Purchase DAT continues excluding mirrored Importation rows.
10. Migration up/down behavior is checked against a database containing both no duplicate data and duplicate data, including the rollback safety guard.

Verification completed: `php artisan test --compact tests/Feature/ImportationUploadTest.php tests/Feature/ImportationEntryTest.php tests/Feature/ImportationDatFileTest.php` passed (23 tests, 206 assertions). PHP lint passed for the changed importer, writer, migration, and upload test. `git diff --check` reported no whitespace errors. The rollback guard test confirms duplicate data remains intact when rollback refuses to restore uniqueness.

## Acceptance Criteria

- A valid workbook with repeated Entry Nos in one tax month uploads successfully and preserves every row.
- The duplicate rows each have their own VAT mirror record and correct computed values.
- Invalid rows, including missing Assessment / Release Date, still reject the entire workbook without deleting existing records.
- Manual duplicate protections remain in force.
- DAT output and unrelated Importation/Purchase behavior remain unchanged.
- The database schema permits repeated Entry Nos for the same month and remains indexed for month/Entry No. lookups.
- Migration rollback never removes or alters duplicate records and reports a clear failure if uniqueness cannot be restored.

## Files Expected to Change During Implementation

- `app/Imports/ImportationEntryImport.php`
- `app/Services/ImportationEntryWriter.php`
- `database/migrations/<new timestamp>_allow_duplicate_importation_entry_numbers.php`
- `tests/Feature/ImportationUploadTest.php`
- `tests/Feature/ImportationEntryTest.php` or the focused writer test file

The upload UI and DAT generator are expected to remain unchanged unless implementation reveals a concrete integration issue.

## Explicitly Out of Scope

- Making Assessment / Release Date optional.
- Changing any other required upload field or amount/date validation.
- Changing duplicate rules for manual entry or editing.
- Automatically skipping, merging, or deduplicating uploaded rows.
- Changing Importation DAT serialization, tax calculations, or Purchase mirror exclusion.
- Automatically assigning or correcting values in the source workbook.
