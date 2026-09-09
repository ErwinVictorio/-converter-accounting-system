# DAT Attachment PDF Plan

## Goal

When a user downloads a DAT file for a selected month/period, the app should also provide the readable attachment report required for submission. The DAT text file remains the official machine-readable output, while the PDF attachment is the human-readable report version.

This work must not change the existing DAT file layouts, DAT filenames, line ordering rules, calculations, rounding, validation rules, or stored transaction data.

## No DAT Format Changes

This plan is only for adding the readable attachment file beside the generated DAT file.

The existing `.DAT` output must stay exactly the same:

- Do not add, remove, rename, or reorder DAT fields.
- Do not change DAT headers, detail rows, control rows, delimiters, line endings, or file extensions.
- Do not change DAT filenames or period formatting.
- Do not change DAT calculations, rounding, validation, or row ordering.
- Do not change import/storage behavior just to support the PDF attachment.

Any new PDF, ZIP, or React PDF code must consume the same already-selected records used by DAT generation without modifying the DAT generator output.

## Current Samples

The readable attachment samples are stored in:

- `Docs/attachment/PURCHASE.xlsx`
- `Docs/attachment/SALES.xlsx`
- `Docs/attachment/IMPORTATION.xlsx`

These samples use a report-style layout:

- Report title at the top.
- Taxpayer TIN, owner name, trade name, and address.
- A detail table.
- Grand total row.
- End of report marker.

## Required Report Columns

### Purchase Attachment

- Taxable Month
- Taxpayer Identification Number
- Registered Name
- Name of Supplier
- Supplier's Address
- Amount of Gross Purchase
- Amount of Exempt Purchase
- Amount of Zero-Rated Purchase
- Amount of Taxable Purchase
- Amount of Purchase of Services
- Amount of Purchase of Capital Goods
- Amount of Purchase of Goods Other Than Capital Goods
- Amount of Input Tax
- Amount of Gross Taxable Purchase

### Sales Attachment

- Taxable Month
- Taxpayer Identification Number
- Registered Name
- Name of Customer
- Customer's Address
- Amount of Gross Sales
- Amount of Exempt Sales
- Amount of Zero Rated Sales
- Amount of Taxable Sales
- Amount of Output Tax
- Amount of Gross Taxable Sales

### Importation Attachment

- Taxable Month
- Import Entry Number
- Assessment/Release Date
- Registered Name
- Importation Date
- Country of Origin
- Amount of Total Landed Cost
- Amount of Dutiable Value
- Amount of Charges Before Release From Custom
- Amount of Taxable Imports
- Amount of Exempt Imports
- Amount of VAT
- OR Number
- Date of VAT Payment

## Download Behavior

Preferred workflow:

1. User selects DAT type and month/period on `Generate DAT File`.
2. User clicks the existing download action.
3. Backend validates the selected records exactly as it does today.
4. If validation fails, no DAT file and no attachment file are generated.
5. If validation passes, the user receives both:
   - The existing `.DAT` file.
   - The readable PDF attachment for the same selected DAT type and period.

## Packaging Options

### Option A: ZIP Download, Selected

Return one ZIP file that contains both the DAT and the PDF attachment.

Example filenames inside the ZIP:

- `008791976P072026.DAT`
- `008791976P072026-ATTACHMENT.pdf`

Advantages:

- Browser downloads one file from one click.
- The DAT and attachment cannot be accidentally downloaded for different periods.
- Backend validation and data selection stay authoritative.
- Easier to test as one response.

Tradeoff:

- The downloaded file is `.zip`, so the user extracts it before submission.

### Option B: Two Sequential Browser Downloads

Keep the DAT response and trigger a second PDF download from the frontend.

Advantages:

- User sees two direct files without extracting a ZIP.

Tradeoffs:

- Browser popup/download restrictions can block the second file.
- More fragile on slow responses.
- Easier for the DAT and PDF to get out of sync if frontend state changes.

### Option C: Separate Buttons

Keep `Download DAT` and add `Download Attachment PDF`.

Advantages:

- Simple implementation.
- Useful for regenerating only the attachment.

Tradeoff:

- Does not fully satisfy the desired "kasama na yung attachment" behavior unless paired with Option A.

## Selected Implementation

Use Option A as the approved behavior: one ZIP download containing the DAT and the PDF attachment. The existing download action should generate a ZIP for the selected DAT type and period, and that ZIP should contain both the official `.DAT` file and the readable PDF attachment. The DAT file inside the ZIP must be byte-for-byte compatible with the current DAT download output.

Keep the DAT generation services as-is:

- `ReliefPurchaseDatGenerator`
- `ReliefSalesDatGenerator`
- `ReliefImportationDatGenerator`
- Expanded WTAX generators, if attachment support is added later

Add separate attachment/report generation code so the official DAT format stays isolated.

Suggested new service:

```text
app/Services/BIR/DatAttachmentReportBuilder.php
```

Responsibilities:

- Receive already selected and validated rows.
- Build report metadata from the same company and selected period used by DAT generation.
- Convert records into attachment rows matching the sample columns.
- Calculate grand totals from the same row values shown in the PDF.

## React PDF Library Decision

The proposed library is:

```text
@react-pdf/renderer
```

Docs checked: `https://react-pdf.org/docs/v4`

React PDF v4 can render PDFs in both browser and server environments. It supports creating PDF documents with React primitives such as `Document`, `Page`, `View`, and `Text`, and the docs show server rendering through `ReactPDF.render()` and `ReactPDF.renderToStream()`.

Because this project is a Laravel/Inertia app, there are two realistic ways to use React PDF:

### Approach 1: Frontend/Browser PDF Generation

Use `PDFDownloadLink` or `BlobProvider` in React.

This is not recommended for the main DAT attachment flow because the backend currently owns DAT validation, DAT file generation, filenames, and selected period filtering. Browser-side PDF generation would duplicate report logic in JavaScript and may drift from the backend DAT output.

### Approach 2: Server-Side React PDF Generation

Use a small Node script that imports `@react-pdf/renderer`, receives JSON report data from Laravel, and returns or writes the PDF output.

This is workable and keeps the actual PDF layout in React PDF.

Suggested flow:

1. Laravel selects and validates records.
2. Laravel builds a normalized JSON payload for the attachment.
3. Laravel calls a local Node PDF renderer script.
4. The Node script renders the React PDF document.
5. Laravel packages the generated PDF together with the DAT in a ZIP response.

This keeps the backend authoritative for data and lets React PDF handle only PDF layout/rendering.

## Important React PDF Caveats

- Requires adding an npm dependency: `@react-pdf/renderer`.
- Server-side rendering requires Node to be available in the deployment environment.
- Laravel tests should not depend heavily on pixel-perfect PDF internals.
- The PDF layout must be designed for wide tables, especially Purchase and Importation.
- If Node or npm is unavailable in the target machine, a PHP-native PDF library such as Dompdf may be simpler operationally.

## Data Mapping Rules

### Purchase

Use the same filtered records as `downloadPurchase()`:

- Source table/model: `vat_inputs` / `VatInput`
- Exclude importation mirrors.
- Filter by selected month using `date_uploaded`.
- Preserve the existing alphabetical supplier order used for DAT generation.
- Use the same BIR row mapping as the Purchase DAT path where possible.

### Sales

Use the same filtered/consolidated rows as `downloadSales()`:

- Source table/model: `sales_vatsinputs` / `SalesVatInput`
- Filter by selected month using `reporting_period`.
- Consolidate SI/CM rows through `SalesSiCmConsolidator`.
- Preserve the existing alphabetical customer order after consolidation.

### Importation

Use the same filtered records as `downloadImportation()`:

- Source table/model: `importation_entries` / `ImportationEntry`
- Filter by selected month using `tax_month`.
- Preserve existing Importation DAT ordering.

## Expanded WTAX Scope

The current attachment samples only cover:

- Purchase
- Sales
- Importation

Do not invent an Expanded WTAX attachment layout until a real sample or BIR-required readable format is provided.

## UI Plan

Update `resources/js/Pages/GenerateDatFile.jsx` carefully:

- Keep the existing DAT type and period controls.
- Update the main download action text only if needed, for example:
  - `Download DAT + Attachment`
- Keep the same disabled/error behavior for invalid periods.
- Do not add record browsing tables to the Generate DAT screen.
- The main download action should point to the ZIP-producing backend flow, not to separate DAT and PDF downloads.

## Backend Plan

1. Refactor `DatFileController` just enough to avoid duplicating the selected-record queries.
2. Add shared private methods or a small query service for:
   - Purchase selected records.
   - Sales selected/consolidated rows.
   - Importation selected records.
3. Keep existing DAT generation methods behavior-compatible.
4. Add an attachment payload builder.
5. Add a PDF renderer integration.
6. Add ZIP response generation.
7. Return a single download response containing both files.

## Suggested File Changes

- `app/Http/Controllers/DatFileController.php`
- `app/Services/BIR/DatAttachmentReportBuilder.php`
- `resources/js/Pages/GenerateDatFile.jsx`
- `resources/js/Pdf/DatAttachmentDocument.jsx`, if using React PDF
- `scripts/render-dat-attachment-pdf.mjs`, if using server-side React PDF
- `package.json`
- `package-lock.json`, if npm install is run
- Tests under `tests/Feature`

## Verification Plan

Run focused backend tests:

```bash
php artisan test tests/Feature/DatFileDownloadTest.php
php artisan test tests/Feature/DatFileAlphabeticalOrderingTest.php
```

Add new tests for:

- Purchase ZIP contains one DAT and one PDF attachment.
- Sales ZIP contains one DAT and one PDF attachment.
- Importation ZIP contains one DAT and one PDF attachment.
- Invalid DAT rows block both DAT and PDF generation.
- No records for selected period still returns the existing no-record behavior.
- Existing DAT content remains unchanged.

If React PDF is used:

```bash
npm install @react-pdf/renderer
npm run build
```

If Node/npm is unavailable, document that frontend/build verification could not be completed in the local environment.

## Acceptance Criteria

- Downloading a DAT for Purchase, Sales, or Importation also includes the matching readable PDF attachment.
- The DAT and PDF always use the same selected type and period.
- Existing DAT files remain format-compatible with current BIR/AVS validation.
- Attachment rows and totals match the saved/consolidated rows used for DAT generation.
- Purchase, Sales, and Importation PDF columns match the provided samples.
- Expanded WTAX is left unchanged unless a sample attachment format is provided.
