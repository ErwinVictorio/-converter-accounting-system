# Purchase Company Selection for Upload and DAT Generation

Date: October 5, 2026  
Status: Proposed implementation plan only. No application or database changes applied.

## Goal

Apply the company-selection workflow already available in Expanded WTAX to Purchase upload and Purchase DAT generation. Users select the filing company before uploading, and select that same company when generating its DAT and attachment. FSI and STC must be able to hold Purchase records for the same month without replacing, merging, or exporting each other's records.

## Current code findings

| Area | Current behavior / implementation |
| --- | --- |
| Expanded upload | `resources/js/Pages/RecordEntry.jsx` provides Known Company, Company TIN, and Branch Code. `VatInputController` requires the company fields for Expanded and scopes replacement by withholding-agent TIN and branch. |
| Company directory | `WithholdingCompanyDirectory` combines managed companies, configuration, and historical Expanded identities; `companyForDat()` resolves profile details. |
| Purchase upload | `PurchaseUploadService::replace()` replaces normal Purchase rows by month, preserving adjusted rows and Importation mirrors. It does not scope replacement by filing company. |
| Purchase import | `VatInputImport` aggregates by supplier name, import classification, adjustment flag, and reporting date. Filing company is not part of that match. |
| Pending Purchase upload | `PendingPurchaseUploadService` retains the workbook and reporting period; retry calls the same Purchase preflight/replacement path without a filing-company identity. |
| Purchase DAT | `DatFileController::purchasePeriods()` lists Purchase periods across companies; `downloadPurchase()` uses `defaultReliefCompany()`, currently based on Fortress configuration. |
| DAT format | `ReliefPurchaseDatGenerator` already accepts a company array. Preserve its existing RELIEF Purchase field layout, calculations, ordering rules, and filename convention. |

Evidence is from local source inspection. Existing database ownership has not been audited; the fixed Fortress export profile does not prove every historical row belongs to Fortress.

## Expected user flow

### Purchase upload

1. Select Reporting Month and File Type = Purchase.
2. Select Known Company, using the existing company list (for example, FORTRESS STEEL INC. or STC).
3. Show the selected Company's TIN and Branch Code beside the selector. Recommended Purchase behavior: auto-filled, read-only identity fields, with no implicit company chosen for a new upload.
4. Choose or drop the workbook, then submit. Require a valid company before submission and repeat validation on the server.
5. Show company and month in the upload result and any existing replacement confirmation. Replacement affects only normal Purchase rows for that company, branch, and month.
6. If supplier corrections are required, retain the selected company with the pending upload and show it in the fix queue. Retry always uses that stored company, even if the user changes the upload-page selector later.

Use the current Purchase template and supplier validation rules. Supplier TIN is the vendor identity, not the filing-company TIN; never compare every supplier row's TIN with the selected company. If a supported workbook contains explicit filing-company metadata, validate that metadata for conflicts. Without such metadata, the user's explicit selection establishes ownership; do not guess from filenames or supplier names.

### Purchase DAT generation

1. Select DAT Type = Purchase.
2. Select Known Company; show its TIN and Branch Code using the same company identity as upload.
3. Load reporting months, record counts, and validation issues for only that company and branch.
4. Reset stale period/readiness state when company changes, and disable download while refreshed data is loading.
5. Download DAT + Attachment using only the selected company's records and profile. Header/detail owner fields, filenames, attachment owner information, and totals must agree.
6. Show a clear company-specific empty state when no records exist. Revalidate the selection and records on direct download requests, not only in the UI.

Example: upload October 2026 for FSI, then October 2026 for STC. Both datasets remain. Re-uploading STC October replaces only STC's normal October Purchase rows. Generating FSI October includes only FSI rows and FSI owner details.

## Implementation steps

### 1. Add durable Purchase ownership

- Add proposed nullable `filing_company_tin` and `filing_company_branch_code` columns to `vat_inputs` and `pending_purchase_uploads`. Store normalized strings to retain leading zeros: nine-digit TIN and four-digit branch, following the existing directory identity convention.
- Keep these separate from `tin_number` and `company_name`, which describe the supplier. Add an index supporting company + branch + reporting date queries.
- Require ownership for new Purchase uploads and their resulting rows. Keep columns nullable initially for migration compatibility and Importation mirrors sharing `vat_inputs`.
- Add a reusable explicit Purchase company query scope. Do not install a global scope that could silently change Sales, Importation, or dashboard behavior.
- Resolve profiles from managed/configured filing companies. Do not use `DatFileController::companyLookup()` supplier fallbacks as filing-company profile authority.
- Reuse the directory carefully: its historical Expanded suggestions and permissive `resolve()` fallback do not by themselves establish an eligible Purchase filing profile. Validate Purchase identities and required RELIEF profile fields server-side. Keep Expanded behavior intact.
- Permit regeneration of owned historical Purchase data for inactive companies; keep inactive companies unavailable for new uploads. Include historical Purchase identities in the export selection where needed.

### 2. Preserve existing records through a reviewed backfill

- Inventory normal Purchase rows, adjusted rows, adjustment links, Importation mirrors, and pending uploads before implementation rollout. Verify the active database connection first.
- Back up affected tables and record counts/totals by period before assigning ownership.
- Assign historical rows to FSI only when their provenance is confirmed. Do not blanket-assign all rows merely because old exports used a Fortress header.
- Keep unresolved rows explicitly unassigned and visible for review; exclude them from company-specific exports with a visible unresolved-record notice. Resolve ownership before affected historical exports are considered ready.
- Reconcile amounts and row counts after backfill. Inspect linked adjustment source/target ownership together; unresolved or conflicting links require review.
- For legacy pending uploads with no company, require explicit company assignment before retry; never inherit the user's latest selection silently.
- Deploy schema first and enable the company-aware flow only after all Purchase writers/readers and the agreed historical ownership mapping are ready. Once multiple companies have data, rollback must disable Purchase mutations/downloads or restore a company-aware version; reverting to month-only replacement is unsafe. Preserve ownership columns and backups.

### 3. Thread company through upload, import, and retry

- Update `RecordEntry.jsx` and `VatInputController` to accept and validate Purchase company selection independently of Expanded request fields.
- Pass a validated identity into `PurchaseUploadService`, `VatInputImport`, and pending-upload creation, refresh, and retry.
- Scope transactional replacement by company, branch, and period while retaining current adjusted-row and Importation-mirror exclusions.
- Include company identity in importer aggregation matches and all created Purchase rows.
- Persist company on the pending-upload record and include it in queue responses/UI. Reject retry attempts to change its stored ownership; require a fresh upload to choose a different company.
- Serialize concurrent replacement for the same company/branch/month so two submissions cannot produce mixed or duplicated imports. Different companies must remain independent. Retain rollback on import failures and existing preflight-before-replacement behavior.

### 4. Keep adjustments and record management within ownership

- Ensure newly created adjustment targets inherit the source row's company and branch.
- Scope adjustment lookup, transfer target matching, merge candidates, restoration candidates, and deletion validation to the same identity. Reject cross-company source/target links on the backend.
- Add a company filter/context and company identification to Purchase Records so identical suppliers/months remain distinguishable. Preserve it through pagination, editing, and returning to the listing.
- Audit other Purchase aggregations, including dashboard and temporary supplier totals, for grouping that would merge suppliers across companies. Preserve intentionally combined totals with a clear combined context, or scope by a selected company; do not silently change unrelated report contracts.
- Shared supplier master data remains shared. This plan does not split supplier tables or redesign company permissions.

### 5. Scope DAT selection and output

- Update `GenerateDatFile.jsx` to display Purchase company selection and send it during period refresh and download.
- Update `DatFileController` index/download validation, `purchasePeriods()`, and `downloadPurchase()` to use the same normalized company/branch filter.
- Replace the fixed Purchase company profile with the selected profile; leave Sales and Importation default-company behavior unchanged.
- Validate the selected profile's required Purchase owner information before generation. Never fill missing STC fields using Fortress details.
- Pass the same filtered record set and selected profile to the existing DAT generator and attachment builder. Preserve Importation-mirror exclusion.
- Keep branch as a storage/filter identity; do not append it to the RELIEF TIN, insert Expanded fields, or change the Purchase filename/serialization convention without separate format evidence. Existing DAT filenames may coincide for different branches sharing one TIN; do not overwrite a shared stored output when packaging downloads.

## Files expected to change during implementation

| Concern | Files |
| --- | --- |
| Upload UI and request | `resources/js/Pages/RecordEntry.jsx`, `app/Http/Controllers/VatInputController.php` |
| Ownership persistence | New migrations; `app/Models/VatInput.php`, `app/Models/PendingPurchaseUpload.php` |
| Import/replacement | `app/Services/PurchaseUploadService.php`, `app/Imports/VatInputImport.php` |
| Retained upload retry | `app/Services/PendingPurchaseUploadService.php`, `app/Http/Controllers/PendingPurchaseUploadController.php`, existing supplier fix-queue UI |
| Filing-company resolution | `app/Services/BIR/WithholdingCompanyDirectory.php` or a focused Purchase adapter; company lifecycle validation where referenced Purchase data requires protection |
| Purchase records/adjustments | `resources/js/Pages/Records/PurchaseRecords.jsx`, its listing controller, adjustment paths in `VatInputController` |
| DAT selection | `resources/js/Pages/GenerateDatFile.jsx`, `app/Http/Controllers/DatFileController.php` |
| Verification | Existing Purchase upload, pending-upload, adjustment, DAT, attachment, and Expanded regression tests; new company-isolation feature coverage |

## Acceptance and verification

1. Missing, malformed, or unsupported company identities fail on both upload and direct DAT endpoints without changing records.
2. FSI and STC uploads for the same month and supplier remain separate. Re-uploading either leaves the other's rows and amounts unchanged.
3. Two branches of one TIN remain separate; leading zeros survive request, storage, retry, and export selection.
4. Supplier-fix retry retains the original company and month. Invalid preflight, cancellation, expired queues, and failed import do not replace existing Purchase data.
5. Adjustment creation, merge, deletion, and restoration never transfer amounts across companies.
6. Period lists, row counts, validation errors, DAT details, totals, and attachment rows all use the same company filter. Invalid STC records do not block FSI export.
7. Changing the selector cannot download stale records under a new header. Empty company/month combinations return a clear message.
8. For an unchanged FSI fixture, generated DAT remains byte-for-byte compatible with the current output. Selected STC owner fields and attachment profile are correct while amounts/layout remain unchanged.
9. Importation mirrors remain excluded. Existing excluded-supplier behavior, Purchase VAT calculations, text-validation relaxation, sorting, Sales, Importation, and Expanded flows remain unchanged.
10. Backfill reconciles counts and amounts; unresolved ownership is visible. Inactive-company historical export and same-company concurrent uploads have explicit test coverage.

Run focused backend regression tests, the frontend build, and browser checks for company switching, upload validation, pending retry, records filtering, and ZIP contents. Verify database migration/backfill separately against a backed-up dataset. No tests or migrations are needed for this documentation-only change.

## Scope boundaries and implementation decisions

This plan covers Purchase upload through company-specific Purchase DAT + Attachment, including the supporting ownership and adjustment protections needed to make selection reliable. It does not change tax calculations, workbook columns, DAT layouts, supplier data, or company selection in Sales/Importation.

Recommended defaults are explicit company selection for new Purchase uploads and read-only derived TIN/branch fields. Before historical backfill, confirm which existing records belong to each company and how unresolved rows will be assigned. These are rollout decisions, not assumptions that all historical rows are FSI.
