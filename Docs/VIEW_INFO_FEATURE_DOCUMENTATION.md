# View Info Feature Documentation

## Purpose and scope

This document describes the implemented View Info feature and the subsequent interface improvements. It covers the shared modal, table integration, categorized details, hidden technical fields, source-record browsing, copy actions, and validation messages.

The original design is recorded in [VIEW_INFO_MODAL_ALL_TABLES_PLAN.md](VIEW_INFO_MODAL_ALL_TABLES_PLAN.md). This document describes the current behavior where later display changes differ from that plan, particularly the removal of internal metadata from the modal.

## Changes delivered

1. Added a View Info button to all ten existing user-facing data-table locations.
2. Added one reusable dialog and an authenticated read-only detail endpoint for eight resource types.
3. Organized record information into categories, including business fields omitted from the tables.
4. Added original source-record details for consolidated Sales and Expanded WTAX entries.
5. Removed internal IDs and technical metadata from the visible dialog.
6. Hid empty fields and sections while preserving numeric zero and false/No values.
7. Added an At a Glance summary above the detailed categories.
8. Simplified Purchase to one visible Total with a short formula; hid the two other totals.
9. Added copy buttons for TINs and document/payment references.
10. Added source-record search and dates/amounts in collapsed source headings.
11. Added a Needs Attention section for validation errors returned by the detail endpoint.

## Where View Info is available

Page paths below are relative to `resources/js/Pages/`.

| Table location | Page | Detail resource |
| --- | --- | --- |
| Purchase Records | `Records/PurchaseRecords.jsx` | `purchase` |
| Sales Records | `Records/SalesRecords.jsx` | `sales` |
| Expanded WTAX Records | `Records/ExpandedWtaxRecords.jsx` | `expanded-wtax` |
| Importation Records | `Records/ImportationRecords.jsx` | `importation` |
| Manage Suppliers | `ManageSupplier.jsx` | `supplier` |
| Manage Customers | `ManageCustomer.jsx` | `customer` |
| Manage Brokers | `ManageBrokers.jsx` | `broker` |
| Withholding Companies | `WithholdingCompanies.jsx` | `withholding-company` |
| Dashboard: Recent Importation Entries | `Dashboard.jsx` | `importation` |
| Adjustment screen: Original Broker Record | `EditVatInputRecord.jsx` | `purchase` |

Each page renders one View Info dialog outside its row loop. Clicking another record selects that record for the same shared dialog. Existing Edit, BIR Info, Adjust, Delete, and company activation actions remain separate.

Actions columns were added to Sales, Expanded WTAX, Dashboard recent importations, and the Original Broker Record table. Corresponding empty-table column spans were updated where applicable.

## How to use the dialog

1. Open a supported table and click **View Info** beside a row.
2. Wait for the details to load.
3. Review the record name/reference in the header and available TIN, period, and status fields under **At a Glance**.
4. Review the detailed categories and the short formula below Total.
5. Use the copy icon beside a TIN or document reference when needed.
6. For Sales or Expanded WTAX, expand a **Source Records** heading to inspect the original record details. Use the search box to narrow this list.
7. Close the dialog using **Close**, the top close icon, or Escape.

The dialog uses a scrollable layout with a maximum height of 90% of the viewport. Field grids use one column on narrow screens and two on wider screens. The existing Radix dialog components provide the dialog title, description, and keyboard/focus behavior.

## Details shown per resource

| Resource | Information available for review |
| --- | --- |
| Purchase | Supplier and BIR identity, company/individual name fields, address and city, imported flag, purchase/VAT buckets, VAT rate, totals, upload date, current broker eligibility, and adjusted status |
| Sales | Consolidated customer/BIR identity, SI/CM counts, exempt and zero-rated sales, taxable amounts, VAT, net/gross amounts, SI/CM amount breakdown, reporting scope, and original source documents |
| Expanded WTAX | Payee and withholding-agent identities, branches, ATC, rate, income payment, tax withheld, reporting period/type, merged-row count, readiness and TIN/branch conflict details, and original source rows |
| Importation | Sequence number, tax month, import entry reference, assessment/importation dates, seller, country, landed cost, dutiable value, charges, exempt/taxable goods, VAT, OR number, and payment date |
| Supplier | TIN, supplier name, address, and city |
| Customer | TIN, customer name, address, and city |
| Broker | Broker name and TIN |
| Withholding Company | Registered/trade names, TIN, branch, RDO, address/city, active status, filed-row indicator, and identity-lock explanation |

Blank values are omitted, so the exact fields visible depend on the selected record. In particular, blank individual-name fields do not appear on a company record. The implementation hides blanks generally; it does not discard populated fields solely because of the company/individual classification.

## Hidden technical information

The shared dialog excludes these field keys from its displayed categories and source-record search:

| Field key | Hidden information |
| --- | --- |
| `id` | Database record ID |
| `vat_input_id` | Linked Purchase record ID |
| `created_at` | System creation timestamp |
| `updated_at` | System modification timestamp |
| `name_key` | Normalized customer matching key |
| `stored_is_broker` | Legacy stored broker flag |
| `label` | Generated company directory label |

These fields remain in the backend response where supplied. Hiding them is a presentation change, not removal from the database or an API access restriction. IDs still support detail requests and React keys.

Empty System Information and Matching Information sections disappear automatically. The source heading **Tax and System Information** is displayed as **Tax Information** after technical fields are hidden.

Source headings use the actual document number when available. Otherwise they use a display position such as **Source Record 1**, not the database ID.

## Empty fields and formatting

- Null, undefined, empty/whitespace-only strings, and empty arrays are hidden.
- Numeric zero is retained and monetary values display as `0.00`.
- Boolean false remains visible as **No**; true displays as **Yes**.
- Monetary values have thousands separators and two decimal places.
- Percentages have two decimal places and a percent sign.
- Dates use an English month/day/year display. If a date cannot be parsed, its original text is retained.
- IDs such as TINs, branch codes, and references are displayed as text, preserving leading zeros.
- A category is omitted when it has no remaining visible fields.

## At a Glance

The record name or reference appears in the dialog header. At a Glance repeats relevant nonblank top-level fields from the detailed categories:

- TIN (`tin`, `tin_number`, `customer_tin`, or `payee_tin`)
- Reporting scope, reporting period, tax month, or upload date
- Returned status, active flag, or adjusted flag

Only fields supplied by the selected resource appear. The summary does not calculate a new readiness status. Detailed categories retain the same fields for context.

## Purchase total explanations

| Label | Meaning |
| --- | --- |
| Total | The existing Purchase-table calculation: `(purchase_local + services + others) / 0.12` |

Purchase View Info now shows only **Total**, with this short description: `Total = (Purchase Local + Services + Others) / 0.12`.

**Stored Total** (`total`) and **Total Purchases** (`total_purchases`) are hidden in Purchase View Info. They remain in the database and detail response. The visible value still uses `display_calculated_total`, renamed to Total in the interface. No calculation or saved amount changed.

Importation retains Total Landed Cost with the short description `Total Landed Cost = Dutiable Value + Charges`. This is a reconciliation of the existing values; landed cost is still entered/uploaded and charges are derived during saving. The previous lengthy explanations of upload and adjustment paths were removed from the dialog.

## Copy buttons

Copy buttons are available beside visible values for:

- Supplier, customer, broker, payee, and withholding-agent TINs
- Document number and document references
- Import entry number
- OR number

The button copies the field's value to the clipboard and shows a success notification. If browser clipboard access is unavailable, an error notification asks the user to select and copy the text manually. Buttons include accessible labels such as **Copy TIN**.

## Source Records and search

Sales and Expanded WTAX tables can represent multiple original records in one consolidated line. Source Records provides the original details beneath the consolidated summary.

Each expandable heading shows the document number or source position. When available, it also shows document date, reporting period, net amount, and tax withheld. Source fields retain their saved values; React does not total the filtered source list.

### What search does

The search box filters only the source records already loaded inside the open modal. It does not search the whole database, change the main table filters, send a new request on each keystroke, or recalculate the consolidated summary.

Search is case-insensitive and checks the source heading plus nontechnical source field values. The counter shows the number of matching records, for example **2 of 8 source records**. Clearing the search restores the full source list. Opening another record, reopening the dialog, changing its period, or retrying the request resets the search.

Search currently compares raw field values. Use raw dates such as `2026-04` and amounts without thousands separators such as `1000` or `1000.00`. Formatted strings such as `Apr 2026` or `1,000.00` are not guaranteed to match.

### When search appears

The search box appears in Sales whenever Source Records contains at least one entry. Expanded WTAX does not show this search box: all loaded source records remain visible, and the counter shows the total source count. This does not change the search on the main Expanded WTAX records table.

Showing Sales search only when there are more than five source records was suggested in conversation but **has not been implemented**.

## Needs Attention

The dialog displays a highlighted Needs Attention section when the top-level response contains a nonempty `validation_errors` array. The errors are shown as a readable list and removed from the ordinary category grid to avoid repeating them there.

Currently the Expanded WTAX detail builder supplies these errors using `BirExpandedWtaxRowValidator`. Other resource types do not automatically gain validation checks merely because the shared dialog supports this section. Hiding an empty field does not itself generate an error message.

The dialog reports existing validation results; it does not repair data or alter upload/DAT validation rules.

## Backend and frontend architecture

### Authenticated endpoint

`GET /view-info/{resource}/{id}`

The route is inside the existing `auth` middleware group. `ViewInfoController::show()` uses an explicit allow-list of the eight resources listed above. Unknown resources or missing records return 404. The controller returns JSON containing:

```text
resource
title
subtitle
sections[]
  title
  fields[]
    key
    label
    value
    type
source_records[]
  id
  title
  sections[]
```

Display types include text, multiline, money, percentage, date, datetime, boolean, badge, and identifier.

### Simple records

Purchase, Importation, Supplier, Customer, Broker, and Withholding Company requests load a record by primary key. The Purchase response derives current broker eligibility; the company response includes its filed-row/identity-lock information.

### Sales groups

The request uses the displayed Sales row's first source ID as its anchor and receives the current `period=YYYY-MM` when the listing is month-filtered. The endpoint filters records using the existing `SalesSiCmConsolidator::identityKey()` and reuses the consolidator for amounts. Source documents are ordered by document date, document number, and ID.

Without a period, the detail lookup can include matching records from all periods. The main table's free-text search is not passed to the detail request. Therefore the modal's group summary may include more documents than a table row produced by a document-number search. The modal search only changes which source details are visible.

### Expanded WTAX groups

The consolidated listing now includes `source_record_id` as an anchor for View Info. The existing `consolidationKey()` is accessible to the detail controller; the existing consolidator produces the summary and the validator supplies warnings.

Current detail candidate selection uses the anchor's exact reporting date, report type, agent TIN/branch, rate, and ATC before comparing consolidation keys. The consolidator itself normalizes grouping values and groups by reporting month. Legacy rows with different dates within a month or different raw formatting can therefore require further lookup alignment to ensure every merged source is returned; this edge case is not covered by the initial detail tests.

### Loading and failure behavior

The shared component loads details with Axios when opened, clears prior results during a new request, and ignores a response after its effect has been cancelled. Loading and request errors are displayed inside the modal. Retry requests the details again. Closing View Info does not submit the page's edit forms.

## Implementation files

- `resources/js/Components/Records/ViewInfoDialog.jsx`: shared dialog, field filtering, formatting, summary, copy controls, warnings, and source search.
- `app/Http/Controllers/ViewInfoController.php`: resource detail builders and JSON contract.
- `routes/web.php`: authenticated `view-info.show` route.
- `app/Services/BIR/SalesSiCmConsolidator.php`: exposed identity method for detail grouping.
- `app/Models/ExpandedWtaxEntry.php`: source anchor metadata and exposed consolidation key.
- The ten React pages in the coverage table: selected-row state, action buttons, and one dialog per page.
- `tests/Feature/ViewInfoTest.php`: initial endpoint/authentication/grouping coverage.

## Preservation boundaries

The feature adds record viewing and presentation. It introduces no migration or record-saving endpoint. Existing stored amounts, import/replacement workflows, edit actions, main-table filters, and business validation remain in place. No DAT serializer, official field layout, filename, line ending, Excel template, or PDF attachment renderer was changed for this feature.

The existing Sales and Expanded consolidators are reused for detail summaries. Their amount calculations were not rewritten for the dialog.

## Verification record

Results reported during the initial implementation in this conversation:

| Check | Recorded result |
| --- | --- |
| ViewInfoTest | 5 passed, 66 assertions |
| Related Record, Dashboard, master-data, Sales, and Expanded regression suites | 114 passed, 1,441 assertions |
| Full Laravel suite | 418 passed, 22 failures in untouched Auth/Profile/upload-dialog tests |
| PHP syntax and whitespace checks | Passed for the checked feature files |
| Frontend production build | Could not run because Node/npm was unavailable in the shell |

These results belong to the earlier implementation run; they were not rerun for this documentation-only update. The later display improvements received whitespace checks, but frontend compilation and full browser interaction/accessibility verification were not completed. Backend endpoint tests do not verify rendering, clipboard access, empty-field hiding, or source search.

Recommended browser checks when the frontend runtime is available: open each supported table's modal, verify hidden metadata and visible zero/No values, copy a TIN, search and expand source rows, verify warning display, retry a failed request, and test mobile scrolling and keyboard closing.
