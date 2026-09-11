# Sales Zero-Rated VAT Plan

Updated: 2026-09-11
Status: IMPLEMENTED IN CODE AND TESTED - local MySQL migration pending because the configured database login was rejected.

## Objective and confirmed rule

For the Sales Summary upload shown by the user, use the uploaded `VAT` column to identify zero-rated transactions. A blank VAT cell or numeric zero means the transaction is zero-rated. Its VAT must remain zero when saved, displayed, consolidated, and exported to the DAT file.

This replaces the previous requirement to supply a separate `Zero Rated Sales` column for these Summary transactions. The plan is written entirely in English.

## Inspection findings before this implementation

The following findings describe the starting point before the VAT-column rule was implemented on 2026-09-11.

| File | Current behavior and implication |
| --- | --- |
| `app/Imports/SalesVatInputImport.php` | Reads Summary rows with calculated formulas and calls `SalesAmountNormalizer::summary()` before saving. An optional zero-rated column is detected from the header. |
| `app/Imports/SalesAmountNormalizer.php` | Maps Summary Net Amount from index 11, VAT from index 12, and Net of VAT from index 13. Zero-rated sales come only from the optional explicit column. Blank VAT is parsed as zero but does not trigger zero-rated classification. |
| `app/Imports/UploadBirInfoPreflight.php` | Uses the shared amount normalizer for Sales validation before import. The new rule must produce the same amounts in preflight and persistence. |
| `app/Http/Controllers/VatInputController.php` | Runs Sales preflight before deleting and replacing the selected month's non-adjusted Sales rows inside a database transaction. |
| `app/Services/BIR/SalesSiCmConsolidator.php` | Nets SI/CM amounts by customer, subtracts exempt and zero-rated amounts from net amount, then calculates taxable sales and VAT from the remaining gross amount. It does not simply preserve the uploaded zero VAT. |
| `app/Services/BIR/ReliefSalesDatGenerator.php` | Serializes supplied consolidated amounts. Detail zero VAT is `0`; a zero header VAT total is `0.00`. |
| Records controller and DAT attachment builder | Consume consolidated Sales amounts, so correct import classification must also flow through to Records and the attachment. |

Current problem example: Net Amount 1,000, blank VAT, Net of VAT 1,000, and no optional zero-rated column. The current normalizer leaves zero-rated sales at zero. Consolidation then treats 1,000 as taxable gross, producing taxable sales 892.86 and VAT 107.14. Under the requested rule, zero-rated sales must be 1,000 and VAT must be zero.

The screenshot establishes the requested VAT-column basis. These findings come from reading the current code; no live upload or database modification was performed during this inspection.

## Proposed classification and amount mapping

Apply the new automatic rule to Sales Summary rows using the existing workbook columns. No additional Excel column is required. Add a database boolean column named `s_zero_rated` to persist the transaction classification.

| Uploaded VAT value | Planned treatment |
| --- | --- |
| Empty cell, null, empty string, or whitespace | Zero-rated |
| Numeric `0`, `0.00`, or equivalent numeric zero | Zero-rated |
| Formula returning blank or numeric zero | Zero-rated after formula evaluation |
| Valid nonzero number, including negative VAT on a CM | Preserve the existing taxable or explicitly mixed treatment |
| Invalid text or formula error | Keep a row-specific validation error; do not silently classify as zero-rated |

For a Summary row classified as zero-rated:

```text
s_zero_rated      = true
zero_rated_sales   = uploaded Net Amount
output_vat         = 0
taxable_net_of_vat = 0
exempt_sales       = 0
net_amount         = uploaded Net Amount (unchanged)
gross_amount       = uploaded Gross Amount (unchanged)
```

`Net of VAT` may contain the entire sale in this workbook. Do not save that amount as a taxable base for a row classified as zero-rated. Do not divide the zero-rated amount by 1.12 or multiply it by a VAT rate.

Use Net Amount as the full amount after discounts and charges, consistent with the existing consolidation formula. Require a valid full Net Amount for a nonempty sale; do not guess from Gross Amount or Net of VAT when it is missing. Retain existing zero-only row detection behavior so empty/zero rows do not create phantom transactions.

For compatibility with the optional `Zero Rated Sales` column, blank or zero VAT remains authoritative: classify the entire valid Net Amount as zero-rated. A conflicting explicit partial amount must produce a clear preflight issue rather than silently discard part of the sale. Matching explicit amounts remain accepted. Nonzero-VAT mixed rows keep their existing explicit bucket handling.

The BIR-format upload has separate exempt, zero-rated, and taxable fields. Preserve that explicit mapping and its validations; this Summary-specific rule must not relabel explicitly exempt BIR rows.

## Implementation plan

### 0. Add the persisted zero-rated identifier

- Create a migration adding `s_zero_rated` as a boolean with default `false` to the existing `sales_vatsinputs` table. The rollback removes only this new column.
- Add `s_zero_rated` to `$fillable` and cast it as `boolean` in `app/Models/SalesVatInput.php`.
- Define `true` as a wholly zero-rated transaction. `false` means the whole-row zero-rated override does not apply; it does not prove that a row is taxable. Mixed and exempt rows still use their amount buckets.
- For Summary uploads, persist `true` when validated uploaded VAT is blank or zero; persist `false` for nonzero VAT. Save the flag and normalized amounts together, including on reupload/update, so an older `true` value cannot survive a later taxable upload.
- For BIR uploads, derive the flag from validated explicit buckets: `true` only for a nonzero pure zero-rated row with no exempt or taxable portion. Preserve mixed and exempt handling with `false` and the existing amount buckets.
- Existing rows initially receive `false`; do not infer historical classification or rewrite amounts during migration. Reupload applies the new rule to previously imported Summary records. Existing explicitly classified rows retain their bucket-based behavior even before reupload.
- Keep this identifier internal to the database/application. Do not add an `s_zero_rated` field to the official DAT layout.

### 1. Update the shared Summary normalizer

- Implement blank/zero VAT classification in `SalesAmountNormalizer::summary()` before the existing explicit-bucket consistency checks.
- Validate raw numeric inputs before using the classification result. Preserve formula evaluation and numeric error handling.
- Set the zero-rated amount from the full Net Amount and clear taxable/VAT amounts for qualifying rows.
- Return the classification alongside the normalized amounts. Keep the boolean outside numeric amount parsing and amount-presence checks.
- Retain the nonzero-VAT path and the BIR normalizer behavior.
- Keep `hasSummaryAmount()` aligned with the updated normalization so qualifying rows are detected consistently.

### 2. Verify import and preflight use identical results

- Confirm `SalesVatInputImport` saves `s_zero_rated` and the normalized zero-rated, taxable, and VAT values without later overwrites.
- Confirm `UploadBirInfoPreflight` uses those same values for validation and row detection.
- Preserve document type handling, DM exclusion, customer matching, TIN/address checks, reporting-period checks, and workbook type validation.
- Preserve preflight-before-replacement ordering and transactional rollback. Invalid rows must leave existing monthly records intact.

### 3. Verify consolidation and downstream output

- Keep classification at the transaction level before grouping. Do not zero the entire customer group merely because it includes a zero-rated transaction.
- Use `s_zero_rated` per source record before aggregation to ensure flagged rows contribute their full Net Amount to zero-rated sales and zero to taxable sales/VAT. Do not modify stored rows during download. Retain the existing aggregate formula and rounding for the remaining taxable amounts and existing explicit buckets.
- Verify SI minus CM behavior for zero-rated rows, including negative CM representations and full cancellation.
- Confirm Records, DAT, and attachment show the same consolidated values.
- Keep the DAT generator unchanged: preserve 17 header fields, 15 detail fields, field order, delimiters, quotes, CRLF, filename, and formatting. Detail VAT field 13 must be `0` for a purely zero-rated group; header VAT field 14 must sum the applicable VAT and remain `0.00` when all groups have zero VAT.

### 4. Update upload guidance and existing data handling

- Update the Summary sample and instructions to demonstrate blank/zero VAT without requiring the extra zero-rated column.
- Explain that nonzero VAT retains the existing taxable treatment, including mixed rows with explicit zero-rated amounts.
- Do not automatically rewrite historical records during download or perform a blanket database update.
- Previously imported Summary rows require reupload of the source workbook after implementation so the new classification is saved. Preserve the selected month's existing replacement boundaries and adjusted-record exclusions.

## Verification matrix

| Scenario | Expected result |
| --- | --- |
| Net Amount 1,000; VAT blank; Net of VAT 1,000; no extra column | Saved zero-rated 1,000; taxable 0; VAT 0; DAT detail VAT `0` |
| Same row with VAT `0` or `0.00` | Same zero-rated result |
| VAT formula evaluates to zero or blank | Same result in preflight and import |
| VAT contains invalid text or an Excel error | Row rejected before replacement |
| Nonempty zero-VAT sale has missing Net Amount | Row-specific amount issue; no guessed sales amount |
| Ordinary taxable gross 1,120; VAT 120 | Existing taxable 1,000 and VAT 120 unchanged |
| Same customer: taxable gross 1,120 plus blank-VAT sale 500 | Zero-rated 500; taxable 1,000; VAT 120 |
| Zero-rated SI 1,000 less CM 200 | Zero-rated 800; taxable 0; VAT 0 |
| Negative zero-rated CM or full SI/CM cancellation | Existing sign rules retained; no VAT introduced |
| Zero VAT plus matching optional zero-rated amount | Accepted without requiring changes to the older explicit format |
| Zero VAT plus conflicting explicit partial amount | Clear preflight issue; no silent amount loss |
| Nonzero VAT with explicit mixed buckets | Existing reconciliation and VAT behavior retained |
| Explicit BIR exempt/zero-rated/mixed rows | Existing classification and validation unchanged |
| Failed upload for an existing month | Existing records remain intact |
| DAT package and Records response | Correct values, totals, layout, ordering, and attachment amounts |

Extend the existing tests in:

- `tests/Feature/SalesZeroRatedImportTest.php`
- `tests/Unit/SalesSiCmConsolidatorTest.php`
- `tests/Unit/ReliefSalesDatGeneratorTest.php`

Also verify migration defaults and rollback, boolean model casting, saved flag values for blank/zero/nonzero VAT, classification changes on reupload, BIR pure/mixed/exempt flag values, and flagged rows with stale VAT/taxable amounts. The latter must not contribute VAT during consolidation. Test a flagged zero-rated row grouped with an unflagged taxable row to ensure only the taxable row contributes VAT.

Run the focused Sales import-to-DAT coverage and relevant existing workbook preflight, alphabetical ordering, DAT text-validation, and attachment report tests. Reuse `tests/Support/DatPackageAssertions.php` for packaged DAT checks. Include a fixture with the original Summary columns and no added classification column.

After implementation, inspect a generated DAT from a representative source upload and record the actual test results. Previous test results for the explicit-column implementation do not verify this revised rule.

## Scope and completion criteria

The migration, model flag, Summary normalizer, import persistence, and per-record consolidation override are implemented. Historical records were not backfilled. The local database migration remains pending because MySQL rejected the configured login.

The change is complete when a blank/zero VAT Summary transaction is saved with `s_zero_rated = true` and never contributes VAT to consolidated Records, attachment totals, or DAT output, while nonzero-VAT transactions retain their existing calculation. Purchase, Importation, and Expanded WTAX behavior remain outside scope.

## Implementation results - 2026-09-11

- Summary blank/zero VAT now saves `s_zero_rated = true`, the full Net Amount as zero-rated sales, and zero taxable/VAT amounts. Formula blank/zero results are supported without another Excel column.
- Nonzero Summary VAT saves `false` and retains the existing explicit-bucket behavior. Existing explicit pure zero-rated Summary rows with erroneous nonzero VAT still normalize through the earlier bucket rules; their flag remains false because the uploaded VAT was nonzero. Their zero-rated bucket continues to exclude VAT during consolidation.
- BIR pure zero-rated rows receive true; BIR mixed/exempt rows retain false and their existing buckets.
- Flagged source records contribute zero VAT even if their saved taxable/VAT fields are stale. The consolidator does not mutate those records or zero taxable transactions in the same group.
- Migration adds the boolean with default false; model supports mass assignment and boolean casting. No DAT field was added and the generator was not modified.
- Updated [Summary sample](SALES_ZERO_RATED_SUMMARY_SAMPLE.csv) uses the original 14 columns. ZERO CUSTOMER nets to zero-rated 800 / VAT 0. MIXED CUSTOMER combines a zero-rated 500 row and taxable gross 1,120 row, producing taxable 1,000 / VAT 120. These fictitious customers need valid Customer master data before upload.
- Existing Summary records need a same-month reupload after the migration is applied. No historical amounts were changed automatically.

Verification: 92 tests passed (999 assertions), covering SalesZeroRatedImportTest, SalesSiCmConsolidatorTest, ReliefSalesDatGeneratorTest, UploadWorkbookTypePreflightTest, DatFileAlphabeticalOrderingTest, DatTextValidationRelaxationTest, and DatAttachmentReportBuilderTest. Migration default/rollback was verified on the isolated test database. `git diff --check` passed.

The local migration preview failed with MySQL error 1045 (access denied for the configured root login). No local schema change was applied. Once the application's database connection is corrected, apply only the new migration:

```shell
php artisan migrate --path=database/migrations/2026_09_11_000000_add_s_zero_rated_to_sales_vatsinputs_table.php
```

DAT values and package contents were verified using upload-to-download fixtures. No production workbook was imported, and no browser/PDF visual inspection was performed during this implementation.
