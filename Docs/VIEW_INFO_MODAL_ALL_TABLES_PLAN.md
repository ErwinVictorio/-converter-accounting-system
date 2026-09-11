# View Info Modal for All Tables - Implementation Plan

## Status

Implemented on September 11, 2026.

Implementation added one authenticated, allow-listed read-only detail endpoint, one shared `ViewInfoDialog`, categorized information for all eight resource types, and View Info actions in all ten table occurrences listed below. No migration was added, and no import, stored-value, validation, DAT, Excel, or PDF behavior was changed.

Verification completed:

- `tests/Feature/ViewInfoTest.php`: 5 passed, 66 assertions.
- Focused Record, Dashboard, master-data, Sales, and Expanded WTAX regressions: 114 passed, 1,441 assertions.
- Full Laravel suite: 418 passed and 22 failures in untouched Auth/Profile/upload-dialog scaffold tests.
- `npm run build` could not run because Node/npm is unavailable in the current shell; frontend compilation and browser visual verification remain unconfirmed.

## Goal

Add a visible **View Info** button to every user-facing data table. Clicking it opens a read-only modal that shows all meaningful data for the selected row, including fields intentionally omitted from the compact table, grouped into clear categories.

Use one reusable modal component across the application. Each page keeps only one modal instance outside its row loop and changes the selected record when a View Info button is clicked. Do not render one modal per row.

## Current implementation findings

The application currently has ten user-facing table occurrences:

1. `resources/js/Pages/Records/PurchaseRecords.jsx`
2. `resources/js/Pages/Records/SalesRecords.jsx`
3. `resources/js/Pages/Records/ExpandedWtaxRecords.jsx`
4. `resources/js/Pages/Records/ImportationRecords.jsx`
5. `resources/js/Pages/ManageSupplier.jsx`
6. `resources/js/Pages/ManageCustomer.jsx`
7. `resources/js/Pages/ManageBrokers.jsx`
8. `resources/js/Pages/WithholdingCompanies.jsx`
9. `resources/js/Pages/Dashboard.jsx` - Recent Importation Entries
10. `resources/js/Pages/EditVatInputRecord.jsx` - Original Broker Record

The first eight are the primary listing tables. The Dashboard table is a five-row summary of the same Importation records, while the Edit VAT Input table is a one-row summary of the same Purchase record. Both contextual tables should reuse the corresponding Importation or Purchase View Info definition so the behavior is consistent everywhere.

Important data-shape differences:

- Purchase and Importation listings currently receive complete Eloquent rows, so many hidden stored fields already exist in the page payload.
- Supplier, Customer, Broker, and Withholding Company controllers currently select or transform only the fields needed by their tables. Their timestamps and some internal metadata are not sent.
- Sales table rows are customer-level SI/CM consolidations. A displayed row can represent multiple stored `sales_vatsinputs` rows, and document-level fields such as document number, date, terms, due date, agent, discount, and charges are not present in the consolidated row.
- Expanded WTAX table rows are consolidated filing lines. A displayed row can represent multiple stored `expanded_wtax_entries` rows.
- The modal must distinguish consolidated summary values from source rows. It must not present a consolidated line as though it were one original uploaded record.

## Scope decision

“All data” means:

- every business field stored for the selected record;
- all calculated, status, validation, and consolidation metadata already used by the listing;
- record ID, linked-record ID, normalized matching key, and created/updated timestamps under a separate **System Information** category;
- for a consolidated Sales or Expanded WTAX row, the consolidated summary plus all source rows represented by that summary.

Do not expose framework internals, authentication/session data, unrelated database records, hidden form tokens, or secrets.

## User experience contract

- Button label: **View Info**, with the Lucide `Eye` icon.
- Place View Info first in the existing Actions cell, before Edit, Adjust, BIR Info, Activate/Deactivate, or Delete actions.
- Add an Actions column to Sales, Expanded WTAX, and Dashboard tables because those tables do not currently have one.
- On `EditVatInputRecord.jsx`, add View Info to the Original Broker Record row without changing the adjustment form.
- Opening the modal is read-only and must never submit, update, delete, recalculate, or navigate away from the page.
- Modal heading uses the record's primary name or reference, for example `Purchase Information - ABC SUPPLIER` or `Sales Information - CUSTOMER NAME`.
- Use a responsive, scrollable dialog such as `max-h-[90vh] overflow-y-auto sm:max-w-4xl` so long addresses and source-record details remain usable.
- Show categories as bordered sections with a two-column definition grid on desktop and one column on mobile.
- Display `-` for null, empty, or unavailable values; do not convert a real numeric zero to `-`.
- Format money with thousands separators and two decimals, rates with two decimals and `%`, dates consistently, and booleans as readable Yes/No or status badges.
- Preserve the stored raw meaning. Formatting is display-only.
- For Sales and Expanded WTAX, label the top section **Consolidated Summary** and show a separate **Source Records** area. Each source row can use an accordion or compact bordered sub-section to prevent one very long unstructured screen.
- While lazy-loaded details are being retrieved, show a loading state inside the dialog. On failure, keep the dialog open and show a retryable error; do not replace the page with an error response.
- Keep the standard Radix/shadcn focus trap, Escape-to-close, close button, `DialogTitle`, and `DialogDescription` for keyboard and screen-reader support.

## Shared frontend design

### New component

Create `resources/js/Components/Records/ViewInfoDialog.jsx`.

Responsibilities:

- receive `open`, `onOpenChange`, `resourceType`, `recordId`, optional `period`, and lightweight title/subtitle values;
- lazily request the complete detail payload only when opened;
- reset stale detail/error state when a different row is selected;
- render the server-provided sections using safe text output;
- support field display types: `text`, `multiline`, `money`, `percentage`, `date`, `datetime`, `boolean`, `badge`, and `identifier`;
- render optional source-record groups for consolidated Sales and Expanded WTAX rows;
- provide only a **Close** action; it must not reuse editable forms such as `BirVendorDialog` or Importation's Edit dialog.

### Optional small helpers

If the shared component becomes too large, split only presentation helpers into:

- `resources/js/Components/Records/ViewInfoSection.jsx`
- `resources/js/Components/Records/viewInfoFormat.js`

Continue using the existing `resources/js/Components/ui/dialog.jsx`, `button.jsx`, and `badge.jsx`. Do not introduce another modal library or a new npm dependency.

### Page state pattern

Each page should use the same pattern:

1. Keep `selectedInfoRecord` in component state.
2. Set it from the row's View Info button.
3. Render one `ViewInfoDialog` after the table/card, not inside `.map()`.
4. Clear the selected record when the dialog closes.
5. Preserve the current search, month filter, pagination query, and scroll position.

For the Dashboard and Edit VAT Input pages, use resource types `importation` and `purchase` respectively so they reuse the exact same detail definitions as the primary record pages.

## Backend data contract

### Route and controller

Add one authenticated read-only endpoint inside the existing authenticated route group:

`GET /view-info/{resource}/{id}`

Create `app/Http/Controllers/ViewInfoController.php` with a `show(Request $request, string $resource, string $id)` action.

Use an explicit allow-list mapping for these resource values:

- `purchase`
- `sales`
- `expanded-wtax`
- `importation`
- `supplier`
- `customer`
- `broker`
- `withholding-company`

Never build a model class, table name, or SQL fragment directly from the URL value. Unknown resources return 404. Normal model binding/not-found behavior applies to unknown record IDs.

The JSON response should use one stable presentation contract:

```json
{
  "resource": "purchase",
  "title": "Purchase Information",
  "subtitle": "ABC SUPPLIER",
  "sections": [
    {
      "title": "Vendor and BIR Information",
      "fields": [
        { "key": "supplier_name", "label": "Supplier Name", "value": "ABC SUPPLIER", "type": "text" }
      ]
    }
  ],
  "source_records": []
}
```

Returning explicit labels and display types prevents every page from duplicating category logic. Values must be JSON scalar values or arrays; React performs the final display formatting and escaping.

### Consolidated record lookup

#### Sales

The Sales listing already uses `SalesSiCmConsolidator`. Keep that service as the single source of grouping and amount rules.

- The displayed Sales row's `id` is the first stored Sales record ID in the group and can be used as the endpoint anchor.
- Accept the active `period=YYYY-MM` query when the Sales page is month-filtered.
- Starting from the anchor record, retrieve records with the same existing customer identity used by `SalesSiCmConsolidator`; apply the requested period when present.
- Re-run the existing consolidator to produce the summary. Do not copy its SI/CM subtraction, zero-rated handling, taxable-base, VAT, or rounding logic into the controller.
- Return all matched stored source records, ordered by document date, document number, then ID.
- Do not let the table's free-text search accidentally hide other source documents belonging to the selected consolidated group. Search chooses which summary row is visible; View Info explains the whole selected group within the active period.

If needed, expose a narrowly scoped identity/group method from `SalesSiCmConsolidator` or add a dedicated query helper. Do not change the current identity key or consolidation output calculations.

#### Expanded WTAX

The Expanded listing already uses `ExpandedWtaxEntry::consolidate()`.

- Add a source anchor ID and/or source ID list to the consolidated metadata without changing any BIR-facing field.
- Use the anchor to retrieve only rows in the same existing consolidation group: report type, reporting period, withholding agent identity, normalized payee identity, ATC, and rate.
- Re-run `ExpandedWtaxEntry::consolidate()` for the summary and `BirExpandedWtaxRowValidator` for the existing readiness/error details.
- Return all represented stored rows ordered by ID.
- Preserve the existing first-usable-TIN behavior, `merged_rows`, distinct TIN/branch metadata, amount summation, and rounding.

### Simple record lookup

Purchase, Importation, Supplier, Customer, Broker, and Withholding Company details are loaded directly by primary key. The detail endpoint—not the initial paginated listing—should retrieve the full record including timestamps and relevant derived status. This keeps initial page payloads small and avoids changing the existing table queries solely for the modal.

For Purchase, recompute the current broker-eligibility flag using the same first-nine-digit TIN rule already used by `RecordController::purchases()`. Do not trust the legacy stored `is_broker` value when the listing currently uses a derived flag.

For Withholding Company, include `has_filed_rows` and the generated `label` alongside the stored fields.

## Modal categories and complete field inventory

### 1. Purchase Records

**Vendor and BIR Information**

- Supplier Name (`supplier_name`)
- TIN Number (`tin_number`)
- Vendor Type (`vendor_type`)
- Company Name (`company_name`)
- Last Name (`last_name`)
- First Name (`first_name`)
- Middle Name (`middle_name`)
- Address 1 (`address1`)
- City / Address 2 (`address2`)

**Purchase Classification and Amounts**

- Imported (`is_imported`)
- Exempt (`exempt`)
- Zero Rated (`zero_rated`)
- Purchase Imported (`purchase_imported`)
- Purchase Local (`purchase_local`)
- Services (`services`)
- Capital Goods (`capital_goods`)
- Other Than Capital Goods (`other_than_capital_goods`)
- Others (`others`)
- Taxable Net of VAT (`taxable_net_of_vat`)
- VAT Rate (`vat_rate`)
- Input VAT (`input_vat`)
- Total Purchases (`total_purchases`)
- Stored Total (`total`)
- Current table display total, clearly labeled **Display-Calculated Total**, using the unchanged existing UI formula `(purchase_local + services + others) / 0.12`

**Record Status**

- Date Uploaded (`date_uploaded`)
- Broker Eligible (the same derived flag used by the table)
- Adjusted (`is_adjusted`)

**System Information**

- Record ID
- Created At
- Updated At

Do not reconcile, overwrite, or silently relabel `total`, `total_purchases`, and the current display-calculated total. They are distinct existing values/formulas and must be shown distinctly.

### 2. Sales Records

**Consolidated Summary**

- Records Count
- SI Rows
- CM Rows
- Customer Type
- Customer TIN
- Customer Name
- Company Name
- Individual Last, First, and Middle Names
- Address 1
- City / Address 2
- Exempt Sales
- Zero-Rated Sales
- Taxable Net of VAT
- Output VAT
- Net Amount
- Gross Amount
- SI Taxable Sales
- CM Taxable Sales
- SI Output VAT
- CM Output VAT
- Active reporting-period scope, or **All available periods** when no month filter is active

**Source Records** - one subsection per stored `sales_vatsinputs` row

- Record ID
- Document Number
- Document Type
- Document Date
- Terms
- Days
- Due Date
- Agent Name
- Customer Name
- Document References
- Gross Amount
- Discount
- Charges
- Net Amount
- Output VAT
- Taxable Net of VAT
- Customer TIN and Type
- Company or Individual Name fields
- Address 1 and Address 2
- Exempt Sales
- Zero-Rated Sales
- `s_zero_rated` classification
- Reporting Period
- Adjusted status
- Created At
- Updated At

The summary must continue to use the existing `SalesSiCmConsolidator`; source rows are informational and must not be re-summed in React.

### 3. Expanded WTAX Records

**Consolidated Summary**

- Payee Name and Type
- Company Name or Individual Last, First, and Middle Names
- Payee TIN and Branch Code
- Withholding Agent Name
- Withholding Agent TIN and Branch Code
- ATC Code
- Tax Rate
- Income Payment
- Tax Withheld
- Reporting Period
- Report Type
- Merged Rows count

**Validation and Consolidation Details**

- Ready / Needs BIR Info status
- Validation errors
- Missing ID/TIN flag
- Multiple Payee TINs flag
- Distinct Payee TIN values
- Distinct Payee Branch Code values

**Source Records** - one subsection per represented `expanded_wtax_entries` row

- Record ID
- Reporting Period and Report Type
- Withholding Agent TIN, Branch, and Name
- Payee Name and Type
- Payee TIN and Branch
- Company or Individual Name fields
- ATC Code
- Tax Rate
- Income Payment
- Tax Withheld
- Created At
- Updated At

Do not show the old `transaction_date`, `source_no`, `reference_no`, or `source_row` fields: the current schema intentionally removed them, so they are not current record data.

### 4. Importation Records

**Importation Reference**

- Sequence Number
- Tax Month
- Import Entry Number
- Assessment Date
- Importation Date
- Supplier / Name of Seller
- Country

**Amounts and VAT Payment**

- Total Landed Cost
- Dutiable Value
- Charges
- Exempt
- Taxable Goods
- VAT Rate
- VAT Payable
- OR Number
- Payment Date

**System Information**

- Record ID
- Linked Purchase VAT Input ID (`vat_input_id`)
- Created At
- Updated At

The Dashboard's Recent Importation table uses this same category definition and endpoint.

### 5. Suppliers

**Supplier Information**

- TIN
- Supplier Name
- Address
- City

**System Information**

- Supplier ID
- Created At
- Updated At

### 6. Customers

**Customer Information**

- TIN
- Customer Name
- Address
- City

**Matching Information**

- Normalized Name Key (`name_key`), labeled as a system matching value rather than an editable customer name

**System Information**

- Customer ID
- Created At
- Updated At

### 7. Brokers

**Broker Information**

- Broker Name
- TIN

**System Information**

- Broker ID
- Created At
- Updated At

### 8. Withholding Companies

**Company Identity**

- Registered Name
- Trade Name
- TIN
- Branch Code
- Generated Directory Label

**Registration and Address**

- RDO Code
- Address 1
- Address 2 / City

**Status**

- Active / Inactive
- Has Filed Rows
- Identity Locked explanation when filed rows exist

**System Information**

- Company ID
- Created At
- Updated At

## Page-by-page UI changes

### Record pages

- `PurchaseRecords.jsx`: add View Info before the existing BIR Info and Adjust buttons. Keep both current actions unchanged.
- `SalesRecords.jsx`: add a right-aligned Actions column and View Info button. Pass the current `filters.period` to the dialog.
- `ExpandedWtaxRecords.jsx`: add a right-aligned Actions column and View Info button. Do not remove status badges or their tooltips.
- `ImportationRecords.jsx`: add View Info before Edit and Delete. Keep the current Edit dialog as a separate modal with separate state.

Update empty-state `colSpan` values for tables receiving a new Actions column.

### Master-data pages

- `ManageSupplier.jsx`: add View Info before Edit and Delete.
- `ManageCustomer.jsx`: add View Info before Edit and Delete.
- `ManageBrokers.jsx`: add View Info before Edit and Delete.
- `WithholdingCompanies.jsx`: add View Info before Edit, Activate/Deactivate, and Delete.

Do not merge the read-only modal with existing edit dialogs. Closing View Info must not clear or submit an edit form that was never opened.

### Contextual tables

- `Dashboard.jsx`: add an Actions heading/cell and View Info button to each Recent Importation row. Reuse the Importation resource type. Update the empty-state `colSpan` from 7 to 8.
- `EditVatInputRecord.jsx`: add an Actions heading/cell and View Info button to the Original Broker Record row. Reuse the Purchase resource type. This does not change transfer values or adjustment behavior.

## Proposed file changes

### New files

- `app/Http/Controllers/ViewInfoController.php`
- `resources/js/Components/Records/ViewInfoDialog.jsx`
- Optional presentation-only helpers if needed: `ViewInfoSection.jsx` and `viewInfoFormat.js`
- `tests/Feature/ViewInfoTest.php`

### Existing files

- `routes/web.php`
- `app/Services/BIR/SalesSiCmConsolidator.php` only if a reusable group-identity helper is required
- `app/Models/ExpandedWtaxEntry.php` only to expose source anchor metadata without changing consolidation rules
- the ten React page files listed in Current implementation findings

Do not add a migration. All required business fields already exist in the current schema.

## Implementation sequence

1. Add focused backend tests for authentication, resource allow-listing, simple record payloads, and consolidated source-record payloads.
2. Add the authenticated route and `ViewInfoController` with explicit resource builders.
3. Reuse the existing Sales and Expanded consolidation paths and validators; expose only the minimum extra anchor/group metadata required for detail lookup.
4. Create the shared read-only `ViewInfoDialog` and its formatter/section helpers.
5. Add selected-record state and View Info buttons to the four Record pages.
6. Add the same behavior to all four master-data pages.
7. Add the reused Importation modal to Dashboard and Purchase modal to Edit VAT Input.
8. Correct every affected Actions heading and empty-state `colSpan`.
9. Run focused backend tests, the broader related regression tests, the frontend production build, and manual responsive/accessibility checks.

## Verification plan

### Backend feature tests

Create `tests/Feature/ViewInfoTest.php` covering:

- unauthenticated requests redirect to login;
- every allowed resource returns the expected title, categories, field keys, values, and types;
- unknown resource and missing ID return 404;
- null values remain distinguishable from numeric zero;
- Purchase returns every stored field, timestamps, display-calculated total, and the same current broker eligibility as its listing;
- Importation returns all fields including assessment/importation dates and linked `vat_input_id`;
- Supplier, Customer, Broker, and Withholding Company include timestamps; Customer includes `name_key`; Company includes `label` and `has_filed_rows`;
- Sales returns the unchanged consolidated amounts plus every underlying source document in the selected period;
- Sales correctly includes SI, CM, zero-rated, adjusted, and other-document source rows without changing their stored signs or values;
- an unfiltered Sales group can cover all periods, while a supplied period limits the modal to that month;
- Expanded WTAX returns the unchanged consolidated summary, validation errors, distinct TIN/branch metadata, and all represented source rows;
- requesting one consolidated group never leaks unrelated Sales customers or Expanded payees/rates/ATCs.

### Existing regression tests

Run at minimum:

```text
php artisan test tests/Feature/ViewInfoTest.php
php artisan test tests/Feature/RecordPagesTest.php tests/Feature/DashboardTest.php
php artisan test tests/Feature/MasterDataTinUniquenessTest.php tests/Feature/WithholdingCompanyTest.php
php artisan test tests/Feature/ExpandedWtaxConsolidationTest.php tests/Feature/SalesZeroRatedImportTest.php
npm run build
```

Report focused-test results separately from unrelated full-suite failures. If Node/npm is unavailable, report that the frontend build and browser verification were not performed rather than claiming success.

### Manual checks

- Open and close View Info from every one of the ten table occurrences.
- Confirm the correct selected row opens after pagination, search, or month changes.
- Confirm only one modal is present/open per page and rapidly switching rows does not show stale data.
- Verify null, zero, large monetary values, long names/addresses, and timestamps.
- Verify a Sales customer with both SI and CM documents and an Expanded line with multiple merged rows.
- Check mobile width, vertical scrolling, keyboard focus, Escape, close button, and screen-reader title/description.
- Confirm existing Edit, Delete, BIR Info, Adjust, Activate/Deactivate, filters, pagination, and Dashboard month selector still work.

## Hard preservation boundaries

- Read-only feature only; no database migration or data write.
- Do not change imports, upload preflight, record replacement, master-data synchronization, validation rules, or stored values.
- Do not change Purchase totals, Sales SI/CM consolidation, zero-rated classification, Expanded WTAX consolidation, first-valid-TIN behavior, tax computations, or rounding.
- Do not change any DAT generator or official DAT format: headers, detail fields, field order/count, delimiters, line endings, filenames, totals, calculations, or validations.
- Do not change Excel templates, attachment data, PDF layout/rendering, or `scripts/render-dat-attachment-pdf.mjs`.
- Do not change current table columns other than adding the View Info action/Actions column where required.
- Do not turn View Info into an editing shortcut. Existing edit workflows remain separate.

## Acceptance criteria

- Every user-facing table occurrence listed in this plan has a View Info button for each real row.
- All pages reuse one shared View Info modal implementation and render only one modal instance per page.
- The modal displays all current business fields and applicable system metadata in named categories.
- Consolidated Sales and Expanded WTAX modals clearly separate summary values from every represented source record.
- Dashboard Importation and Original Broker Record reuse the same data definitions as their main Record pages.
- The modal is read-only, responsive, keyboard accessible, and handles loading, null, zero, and request errors correctly.
- Existing table actions, filters, pagination, calculations, validation, imports, database values, DAT files, attachments, and exports behave exactly as before.
