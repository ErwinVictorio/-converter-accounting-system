# Importation Excel Upload Plan

## Goal

Add an Upload Data tab to the existing `/importation` screen so users can import multiple Importation entries from the provided Excel template instead of keying every row manually.

The current Manual Entry tab stays available. The Upload Data tab must write to the same `importation_entries` table and keep using the existing Importation DAT generation flow.

## Template Source

Use:

`Docs/Importaion/Importation_Upload_Template_Updated.xlsx`

The workbook sheet is named `Importation Template`.

This is a no-auto-compute input template. It should contain only the fields users manually provide. Fields already computed by the app must stay out of the template.

Observed row 1 headers:

| Excel Header | Stored / Used As | Notes |
| --- | --- | --- |
| Tax Month | `tax_month` | Accept month text like `July 2026`; store as first day of month. |
| Import Entry No. | `import_entry_no` | Required; BIR-safe text normalization. |
| Name of Seller | `supplier` | Required; uppercase/BIR-safe text. |
| Assessment / Release Date | `assessment_date` | Accept Excel serial dates and normal date strings. |
| Date of Importation | `importation_date` | Accept Excel serial dates and normal date strings. |
| Country of Origin | `country` | Required; uppercase/BIR-safe text. |
| VAT Rate | `vat_rate` | Accept `0.12`, `12`, or `12%`; store as `12.00`. |
| Total Landed Cost | `total_landed_cost` | Required numeric amount. |
| Dutiable Value | `dutiable_value` | Required numeric amount; used to compute charges. |
| Exempt | `exempt` | Required numeric amount; default blank to `0.00` only if agreed. |
| OR Number | `or_number` | Required; preserve values like `000` or `OR-2026-00125`. |
| Date of VAT Payment | `payment_date` | Accept Excel serial dates and normal date strings. |

Fields intentionally not included in the upload template:

| Computed UI Field | Reason |
| --- | --- |
| All Charges Before Release from Custom's Custody | Auto-computed as `Total Landed Cost - Dutiable Value`; never manually uploaded. |
| Taxable Goods | Auto-computed as `Total Landed Cost - Exempt`; never manually uploaded. |
| VAT | Auto-computed as `Taxable Goods * VAT Rate / 100`; never manually uploaded. |

## Upload Computations

The Excel file must not include the fields already computed by the app.

For every uploaded row:

| Computed Field | Formula / Rule |
| --- | --- |
| `charges` | `total_landed_cost - dutiable_value`. This is the backend value behind "All Charges Before Release from Custom's Custody." |
| `taxable_goods` | `total_landed_cost - exempt`. |
| `vat_payable` | `taxable_goods * vat_rate / 100`. |
| `sequence_number` | Next available sequence for the row's tax month, preserving upload row order. |

Important: computations must happen on the backend, not only in React. The backend remains the source of truth. Even if a user adds computed columns to their own workbook, the importer should ignore them or reject them rather than trusting uploaded computed amounts.

## User Interface Plan

Update `resources/js/Pages/Importation.jsx`:

1. Add tabs at the top of the page:
   - `Manual Entry`
   - `Upload Data`
2. Keep the existing manual form inside the `Manual Entry` tab with the same fields and behavior.
3. Add an upload form inside `Upload Data`:
   - file input accepting `.xlsx`, `.xls`, and optionally `.csv`
   - template filename hint or download/open link
   - upload button with loading state
   - upload result summary after success
4. Use flash messages for success and failure, consistent with the existing manual entry flow.
5. After successful upload, suggest going to `Record > Importation Records`; do not turn this page into a record browser.

## Backend Plan

Add a dedicated upload endpoint owned by `ImportationController`:

```php
Route::post('/importation/upload', [ImportationController::class, 'upload']);
```

Add an importer class:

`app/Imports/ImportationEntryImport.php`

Recommended concerns:

- `ToArray` or `OnEachRow`
- `WithCalculatedFormulas`
- no `WithHeadingRow` dependency unless the heading row is fixed and tested carefully

The importer should:

1. Detect the heading row from the template headers.
2. Validate all required headers before inserting anything.
3. Skip fully blank rows.
4. Parse dates from Excel serial values and text values.
5. Normalize money values by removing commas and currency symbols.
6. Normalize `VAT Rate`:
   - `0.12` becomes `12.00`
   - `12` becomes `12.00`
   - `12%` becomes `12.00`
7. Build the same payload shape used by manual `store()`.
8. Call shared validation/computation logic so manual and upload cannot drift.
9. Create the synced `vat_inputs` mirror row for every imported `ImportationEntry`.

## Refactor Needed Before Upload

Move reusable logic out of private manual-only methods, or make controller helpers reusable internally:

- validation rules
- `payload()`
- `syncVatInput()`
- `normalizeTaxMonth()`
- `amount()`
- `birText()`

Preferred shape:

`app/Services/ImportationEntryWriter.php`

Responsibilities:

- accept normalized row data
- compute derived values
- enforce duplicate rules
- assign sequence number
- create `ImportationEntry`
- sync the matching `VatInput`

Then both manual `store()` and Excel upload use the same writer.

## Validation Rules

Reject the whole upload before any insert when:

- required headers are missing
- any required field is blank
- any amount/date cannot be parsed
- `exempt > total_landed_cost`
- VAT rate is negative or unreadable
- duplicate `tax_month + import_entry_no` exists inside the uploaded file
- duplicate `tax_month + import_entry_no` already exists in the database

Use one database transaction for the whole upload. If row 8 fails, rows 2 to 7 must not be saved.

Error messages should name worksheet row numbers, for example:

- `Row 5: Exempt cannot be more than Total Landed Cost.`
- `Row 7: Date of VAT Payment is blank or unreadable.`
- `Row 9: Import Entry No. C-12345 already exists for July 2026.`

## DAT / Records Impact

No DAT format changes.

Uploaded entries should behave exactly like manually saved entries:

- listed in `Record > Importation Records`
- editable/deletable through the existing Importation Records actions
- included in Importation DAT generation
- excluded from Purchase DAT reporting through the existing `vat_input_id` mirror exclusion
- counted in dashboard importation totals

## Tests To Add

Add focused feature tests:

1. Uploading the provided template creates three importation entries.
2. Uploaded rows compute:
   - `dutiable_value = total_landed_cost`
   - `charges = 0.00`
   - `taxable_goods = total_landed_cost - exempt`
   - `vat_payable = taxable_goods * vat_rate / 100`
3. Upload accepts Excel serial dates.
4. Upload accepts `VAT Rate` as `0.12` and stores `12.00`.
5. Duplicate import entry number for the same month rejects the whole upload.
6. Missing required headers reject the whole upload.
7. Invalid row data reports worksheet row numbers.
8. Every uploaded row syncs to `vat_inputs`.
9. Importation DAT includes uploaded rows in upload order.
10. Purchase DAT still excludes importation mirror rows.

Existing tests to keep passing:

- `tests/Feature/ImportationEntryTest.php`
- `tests/Feature/ImportationDatFileTest.php`
- `tests/Unit/ReliefImportationDatGeneratorTest.php`

## Implementation Order

1. Create the reusable importation writer/service.
2. Update manual `store()` and `update()` to use the shared computation path where practical.
3. Add `ImportationEntryImport`.
4. Add `ImportationController@upload`.
5. Add `POST /importation/upload` route.
6. Add the `Upload Data` tab to `Importation.jsx`.
7. Add feature tests for successful upload, computed fields, duplicate rejection, header rejection, and DAT inclusion.
8. Run focused importation tests.

## Open Confirmation

Confirmed: `All Charges Before Release from Custom's Custody` is auto-computed and should not be included in the template.

`charges = total_landed_cost - dutiable_value`
