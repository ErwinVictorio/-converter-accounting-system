# Sales/Purchase SI-CM Upload Consolidation Plan

## Scope

This plan is for the existing Sales and Purchase upload/DAT workflows only.

Included:
- Sales upload from the sample Sales Summary Excel format.
- Purchase upload regression checks, only to confirm it still behaves the same.
- Sales DAT period listing and download behavior.
- Sales Records display, if a visible distinction between SI and CM totals is needed.

Excluded:
- Expanded WTAX upload and DAT generation.
- Importation upload/manual entry and Importation DAT generation.
- Company/withholding-agent selection rules.

## Current App Flow To Preserve

- `VatInputController::import()` already separates upload by `record_type`.
- `record_type=sales` uses `SalesVatInputImport` and stores rows in `sales_vatsinputs`.
- `record_type=purchase` uses `VatInputImport` and stores rows in `vat_inputs`.
- Sales DAT generation uses `DatFileController::downloadSales()`.
- Purchase DAT generation uses `DatFileController::downloadPurchase()`.
- Sales and Purchase must remain selectable explicitly in Import Data and Generate DAT File.

## Business Rule From The Sales Sample

The Sales Summary workbook can contain both:
- `SI#...` rows: sales invoice rows.
- `CM#...` rows: credit memo rows.
- `DM#...` rows: debit memo rows that should not be uploaded into Sales VAT records.

For the same customer name/customer identity in the same reporting month:

1. Separate the rows first by document category:
   - SI rows belong to the Sales Invoice category.
   - CM rows belong to the Credit Memo category.

2. Sum each category separately:
   - SI total = sum of all SI amounts for the customer.
   - CM total = sum of all CM amounts for the customer.

3. Compute the final customer total as:
   - Final taxable/net/output figures = SI totals minus CM totals.

4. If a customer has SI and CM rows:
   - Example: 10 SI rows and 4 CM rows for one customer should become one final customer group for DAT purposes.
   - The 10 SI rows are summed first.
   - The 4 CM rows are summed first.
   - The final result is SI total minus CM total.

5. If a customer has only CM rows and no SI partner:
   - The customer group is still included.
   - The final result becomes negative because there is nothing to subtract the CM from.
   - Example: customer has only CM rows; final total = 0 minus CM total.

6. If the workbook contains DM rows:
   - DM rows must be skipped during upload.
   - DM rows must not be stored in `sales_vatsinputs`.
   - DM rows must not affect SI/CM totals or DAT generation.
   - The upload must still warn the user that DM rows were found and excluded.
   - Warning example: `Sales upload completed, but 3 DM row(s) were skipped because Debit Memo rows are not included in Sales VAT upload.`

## Data Handling Plan

### 1. Add document category to Sales records

Add a stored category to `sales_vatsinputs`, for example:

- `document_type`
  - `SI`
  - `CM`
  - `DM_SKIPPED` should not be stored; it is only a runtime warning/count.
  - nullable or `OTHER` only if old/manual rows need fallback support

Recommended migration:
- Add `document_type` string/enum-like column.
- Backfill existing rows from `document_no`:
  - starts with `SI#` => `SI`
  - starts with `CM#` => `CM`
  - starts with `DM#` => do not backfill/store as a normal uploaded Sales row
  - otherwise derive from current row behavior or set `OTHER`
- Add an index with `reporting_period`, `customer_name`, and `document_type` if needed.

Update `SalesVatInput::$fillable` and casts only if a cast is needed.

### 2. Classify document numbers during Sales import

Update `SalesVatInputImport::importSalesSummaryRow()`:

- Normalize `document_no`.
- Detect category:
  - `SI#`, `SI`, or invoice-style prefix => `SI`
  - `CM#`, `CM`, or credit-memo prefix => `CM`
  - `DM#`, `DM`, or debit-memo prefix => skip row and count it for upload warning
- Store `document_type`.
- Do not store DM rows.
- Keep the existing header-based Sales Summary detection.
- Keep customer matching through `Customer::normalizeName()`.

Warning handling:
- Track skipped DM rows in `SalesVatInputImport`.
- Expose a method such as `skippedDebitMemoCount()` after import.
- In `VatInputController::import()`, return success with warning details when the Sales import succeeds but skipped DM rows exist.
- Keep this warning non-blocking unless the workbook contains no importable SI/CM Sales rows.

Important:
- Do not change Purchase import aggregation in `VatInputImport`.
- Do not make Sales and Purchase share one importer.

### 3. Make CM signs predictable

The sample may show CM rows as parenthesized negative amounts, already-negative values, or positive credit memo values depending on workbook formula/export settings.

Plan rule:
- Store raw imported values as they appear after number parsing, but normalize for consolidation.
- During consolidation:
  - SI contribution = absolute or signed SI value according to current workbook behavior.
  - CM contribution = absolute value of CM amount, then subtract it from SI.

Recommended helper:
- Create a Sales consolidation method/service so the same rule is used by:
  - period validation
  - DAT download
  - Sales Records totals, if grouped totals are displayed

Suggested name:
- `App\Services\BIR\SalesSiCmConsolidator`

### 4. Consolidate before DAT validation/download

Update `DatFileController::groupSalesRows()` or move the logic into the new service.

Grouping key should remain customer identity based:
- first 9 digits of customer TIN
- customer type
- company/customer name
- individual name fields
- address fields

Within each customer group:
- split rows into SI and CM
- sum SI taxable/net/output/exempt/zero-rated amounts
- sum CM taxable/net/output/exempt/zero-rated amounts
- return one final row:
  - taxable_sales = SI taxable - CM taxable
  - output_vat = SI output VAT - CM output VAT
  - exempt_sales = SI exempt - CM exempt
  - zero_rated_sales = SI zero-rated - CM zero-rated

For display fields:
- keep the first customer identity fields from the group.
- optional debug/display totals can include:
  - `si_count`
  - `cm_count`
  - `si_taxable_sales`
  - `cm_taxable_sales`

### 5. Decide how to handle negative Sales DAT details

Before implementation, verify if BIR RELIEF Sales DAT accepts negative detail amounts for customers with CM-only rows.

Implementation options:
- If BIR accepts negative Sales values:
  - Keep CM-only output as negative.
  - Validator must allow negative Sales detail amounts for CM-only/net-credit customers.
- If BIR rejects negative Sales values:
  - Upload can store the rows, but DAT generation should block with a clear message naming the affected customer.
  - Message example: `Cannot generate Sales DAT. Customer COMPOSTELASTEEL INC. has CM total greater than SI total for this month.`

The user's requested rule says CM-only totals should become negative, so do not silently drop CM-only rows.

### 6. Keep Sales Records understandable

If the Sales Records page continues to show uploaded rows:
- Add a small `Document Type` column so SI and CM rows are visible.
- Keep existing search/filter behavior.

If the Sales Records page should show consolidated customer totals:
- Use the same consolidation service as DAT generation.
- Show SI total, CM total, and Net Sales total so the subtraction is auditable.

Recommended first implementation:
- Keep raw uploaded rows in the table.
- Add `Document Type`.
- Let DAT generation use consolidated net rows.

## Purchase Workflow Safeguards

Purchase must remain unchanged except for regression tests.

Do not change:
- `VatInputImport` grouping by supplier/month/imported status.
- `VatInputController::import()` branch for `record_type=purchase`.
- `DatFileController::downloadPurchase()`.
- Purchase DAT generator field counts and filename rules.
- Broker adjustment behavior.

Regression checks:
- Purchase workbook selected as Purchase still imports.
- Sales workbook selected as Purchase is still rejected by preflight.
- Purchase DAT unit tests still pass.

## Files Likely To Change During Implementation

Backend:
- `database/migrations/*_add_document_type_to_sales_vatsinputs_table.php`
- `app/Models/SalesVatInput.php`
- `app/Imports/SalesVatInputImport.php`
- `app/Http/Controllers/DatFileController.php`
- optional: `app/Services/BIR/SalesSiCmConsolidator.php`
- optional: `app/Services/BIR/BirSalesRowValidator.php`

Frontend:
- `resources/js/Pages/Records/SalesRecords.jsx`

Tests:
- `tests/Feature/UploadWorkbookTypePreflightTest.php`
- `tests/Unit/ReliefSalesDatGeneratorTest.php`
- new or updated feature/unit test for SI/CM consolidation
- `tests/Unit/ReliefPurchaseDatGeneratorTest.php` as regression

## Test Cases To Add

1. Sales import stores SI and CM document types.
   - Given one `SI#...` row and one `CM#...` row.
   - Assert both rows are stored with correct `document_type`.

2. Same customer with SI and CM nets to one Sales DAT detail row.
   - Given 10 SI rows and 4 CM rows for the same customer.
   - Assert consolidated output has one customer row.
   - Assert taxable/output VAT equals SI sum minus CM sum.

3. CM-only customer becomes negative.
   - Given one customer with only CM rows.
   - Assert consolidated row is negative, or DAT generation blocks if BIR validation rejects negative values.

4. Different customers do not net against each other.
   - SI for Customer A and CM for Customer B stay in separate groups.

5. Different TIN/name identity does not merge accidentally.
   - Same customer name with different valid TIN should remain separate if current grouping treats it as separate.

6. Purchase import still behaves the same.
   - Matching Purchase workbook still imports into `vat_inputs`.
   - No `document_type` requirement is added to Purchase rows.

7. Sales upload skips DM rows with a warning.
   - Given one `DM#...` row and one valid `SI#...` row.
   - Assert only the SI row is stored.
   - Assert the response includes a warning/message that one DM row was skipped.

8. Sales upload with only DM rows does not silently report a normal success.
   - Given a workbook with DM rows and no SI/CM rows.
   - Assert no Sales rows are stored.
   - Assert the response clearly says DM rows were skipped and no importable Sales rows were found.

## Acceptance Criteria

- Sales sample workbook rows with SI and CM are categorized before totals are computed.
- DM rows in the Sales sample workbook are skipped and never stored.
- Upload response warns the user when DM rows were included and skipped.
- Sales DAT generation uses one net customer row per customer identity per month.
- SI totals are reduced by CM totals.
- CM-only customer groups are not dropped.
- Purchase upload and Purchase DAT behavior remain unchanged.
- Expanded WTAX and Importation behavior remain unchanged.
- Error messages name the affected customer if a net-negative Sales row cannot be filed.
- Focused Sales/Purchase tests pass.

## Suggested Implementation Order

1. Add `document_type` migration/model support.
2. Update Sales importer classification.
3. Add a consolidation service or refactor `groupSalesRows()` cleanly.
4. Wire Sales DAT period validation/download to the consolidated rows.
5. Update Sales Records UI only if the raw SI/CM visibility is needed.
6. Add focused tests for SI/CM netting and Purchase regression.
7. Run:
   - `php artisan test tests\\Feature\\UploadWorkbookTypePreflightTest.php tests\\Unit\\ReliefSalesDatGeneratorTest.php tests\\Unit\\ReliefPurchaseDatGeneratorTest.php`
   - `php -l app\\Imports\\SalesVatInputImport.php`
   - `php -l app\\Http\\Controllers\\DatFileController.php`
