# Record Pages Reorganization Plan

## Goal

Move all record/data tables out of the Excel upload and DAT generation screens so each workflow is easier to understand.

The app should have a dedicated `Record` area in the sidebar. Under `Record`, each data table gets its own page.

## Important Scope Rules

- Do not change any DAT file format.
- Do not change Sales DAT generation.
- Do not change Purchase DAT generation.
- Do not change Expanded WTAX DAT generation.
- Do not change Excel parsing or upload column rules.
- Do not merge Sales, Purchase, Expanded WTAX, and Importation storage.
- This task is only for page organization, sidebar navigation, and moving existing table views into cleaner pages.

## Current Problem

The upload/import screen is doing too many things:

- It accepts Excel uploads.
- It shows imported record tables.
- It also carries record maintenance controls.

This makes the page crowded and confusing, especially now that the system already has multiple data types:

- Purchase VAT records
- Sales VAT records
- Expanded WTAX records
- Importation records

The DAT generation page should also stay focused only on generating files, not browsing/editing tables.

## Proposed Sidebar Structure

Use `Record` as the sidebar group for table browsing and record maintenance pages.

```text
MAIN MENU
- Dashboard

DATA & TRANSACTIONS
- Import Data
- Importation
- Generate DAT File

RECORD
- Purchase Records
- Sales Records
- Expanded WTAX Records
- Importation Records

MASTER DATA
- Customers
- Suppliers
- Brokers
- Companies
```

## Page Responsibility

### Import Data Page

Keep this page focused on uploading files only.

It should contain:

- File type selector
- Reporting month selector
- Known company selector when needed
- Excel file picker
- Upload button
- Upload guide/instructions
- Upload success/error messages

It should not show the full imported data tables anymore.

After successful upload, the page can show a link like:

```text
View imported records
```

The link should point to the correct page under `Record`.

### Generate DAT File Page

Keep this page focused on DAT generation only.

It should contain:

- DAT type selector
- Reporting month or period selector
- Known company selector
- Company TIN and branch fields
- Record count/status message
- Download DAT button

It should not display full record tables.

### Record Pages

Each table gets a separate page under the new `Record` sidebar group.

Recommended pages:

```text
/records/purchases
/records/sales
/records/expanded-wtax
/records/importations
```

Each page should contain only the data table and tools for that data type.

## Record Page Details

### Purchase Records

Page path:

```text
/records/purchases
```

Shows records from the existing purchase VAT input table.

Keep existing behavior:

- Search
- Pagination
- Month filter if already available
- Edit BIR info if currently supported
- Existing display columns
- Existing delete/edit actions if already supported

### Sales Records

Page path:

```text
/records/sales
```

Shows records from the existing Sales VAT table.

Keep Sales data separate from Purchase data.

Keep existing behavior:

- Search
- Pagination
- Month filter if already available
- Existing display columns
- Existing actions if already supported

### Expanded WTAX Records

Page path:

```text
/records/expanded-wtax
```

Shows records from the existing Expanded WTAX table.

Keep existing Expanded WTAX rules:

- Data remains scoped by withholding-agent company/TIN/branch.
- Existing upload mapping remains unchanged.
- Existing DAT format remains unchanged.
- Existing company/rate consolidation rules remain unchanged unless another approved task changes them.

### Importation Records

Page path:

```text
/records/importations
```

Shows saved manual importation records.

Do not confuse this with Excel upload. Importation is the manual-entry module.

Keep existing behavior:

- List records
- Search/filter if already available
- Create/edit/delete if already available
- Existing validation rules

## Suggested File Changes

### Sidebar

Update:

```text
resources/js/Components/app-sidebar.jsx
```

Add a new `Record` sidebar group.

Move table-view links under `Record`.

Do not place record table links under `Import Data` or `Generate DAT File`.

### Frontend Pages

Split the current mixed page behavior from:

```text
resources/js/Pages/RecordEntry.jsx
```

Suggested new pages:

```text
resources/js/Pages/Records/PurchaseRecords.jsx
resources/js/Pages/Records/SalesRecords.jsx
resources/js/Pages/Records/ExpandedWtaxRecords.jsx
resources/js/Pages/Records/ImportationRecords.jsx
```

If there is repeated table UI, extract a shared component only when it avoids real duplication.

Suggested shared components:

```text
resources/js/Components/Records/RecordTableShell.jsx
resources/js/Components/Records/PaginationControls.jsx
resources/js/Components/Records/RecordFilters.jsx
```

Keep the shared components simple. Do not over-refactor.

### Routes

Update:

```text
routes/web.php
```

Add routes for the dedicated record pages.

Suggested route names:

```text
records.purchases.index
records.sales.index
records.expanded-wtax.index
records.importations.index
```

Keep existing upload and DAT download routes working.

### Controllers

Use existing controllers where practical, or create a small controller dedicated to record listing.

Possible option:

```text
app/Http/Controllers/RecordController.php
```

Suggested methods:

```text
purchases()
sales()
expandedWtax()
importations()
```

Each method should return only the props needed by its page.

## Data Rules

- Purchase records must continue using purchase storage.
- Sales records must continue using Sales storage.
- Expanded WTAX records must continue using Expanded WTAX storage.
- Importation records must continue using Importation/manual-entry storage.
- Do not use one query that mixes all data types.
- Do not rename existing database columns unless absolutely required.
- Do not change import history or existing uploaded data.

## UX Rules

- `Import Data` means upload only.
- `Generate DAT File` means download/generate only.
- `Record` means browse and manage existing transaction records.
- `Master Data` means setup/reference records like Customers, Suppliers, Brokers, and Companies.

This keeps the sidebar clearer:

- Upload new data in `Import Data`.
- Check uploaded/manual records in `Record`.
- Generate BIR files in `Generate DAT File`.
- Maintain reference lists in `Master Data`.

## Implementation Order

1. Inspect current `RecordEntry.jsx` table sections and identify which props/actions belong to each data type.
2. Add the new `Record` sidebar group in `app-sidebar.jsx`.
3. Add new routes for Purchase, Sales, Expanded WTAX, and Importation record pages.
4. Create backend controller methods for each record page.
5. Move Purchase table UI into `PurchaseRecords.jsx`.
6. Move Sales table UI into `SalesRecords.jsx`.
7. Move Expanded WTAX table UI into `ExpandedWtaxRecords.jsx`.
8. Move Importation table UI into `ImportationRecords.jsx` if it is currently mixed with the manual-entry page.
9. Simplify `Import Data` so it only handles upload workflow.
10. Confirm `Generate DAT File` still only generates/downloads DAT files.
11. Run focused tests for routes, imports, and DAT generation.
12. Manually verify sidebar navigation and page rendering.

## Testing Checklist

- `Import Data` page still uploads Purchase Excel files.
- `Import Data` page still uploads Sales Excel files.
- `Import Data` page still uploads Expanded WTAX Excel files.
- Upload success redirects or links to the correct `Record` page.
- `Purchase Records` shows only Purchase rows.
- `Sales Records` shows only Sales rows.
- `Expanded WTAX Records` shows only Expanded WTAX rows.
- `Importation Records` shows only manual importation rows.
- `Generate DAT File` still downloads the same valid DAT output as before.
- Existing validated Expanded WTAX DAT format remains unchanged.
- Sidebar shows the new `Record` group clearly.

## Acceptance Criteria

- The upload page no longer contains the full record tables.
- The DAT generation page does not contain full record tables.
- There is a `Record` sidebar group.
- Each record type has its own page under `Record`.
- Existing import and DAT generation behavior still works.
- No Sales, Purchase, Expanded WTAX, or Importation DAT format is changed.
