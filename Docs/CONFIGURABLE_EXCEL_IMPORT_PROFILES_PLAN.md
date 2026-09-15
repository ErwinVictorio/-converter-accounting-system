# Configurable Excel Import Profiles Plan

## Status

Unimplemented. This document is a proposed implementation plan only.

## Goal

Allow each customer company to upload its own Excel layout by creating a reusable Import Profile that maps workbook columns into the application's standard Purchase, Sales, or Expanded WTAX fields.

The feature should make the product adaptable to different accounting-system exports without changing PHP import code for every new customer. It must preserve the application's existing validation, consolidation, stored-value rules, reporting-period boundaries, and BIR DAT output.

## Current Behavior

The current upload flows recognize a limited number of layouts in application code:

- Purchase uses `VatInputImport`, expects its heading row at row 3, and resolves a known set of heading aliases.
- Sales uses `SalesVatInputImport` and supports the existing Sales Summary and BIR Sales layouts through fixed column positions and heading detection.
- Expanded WTAX uses `ExpandedWtaxImport` and detects either the BIR Schedule layout or the existing system-export layout from known headings.
- `UploadWorkbookTypePreflight`, `UploadBirInfoPreflight`, and the Expanded preflights inspect the original workbook before its reporting-period records are replaced.
- `RecordEntry.jsx` currently asks for the record type, reporting period/report type, relevant withholding company, and workbook.

This is safe for known formats, but onboarding a customer with a different workbook normally requires a code change.

## Product Decision Required Before Implementation

Decide how the application will be sold and deployed.

### Option A: Separate installation per customer

Each customer receives a separate application and database. Import Profiles do not initially need tenant ownership, although keeping an optional owner/company relationship in the design will make a future SaaS migration easier.

### Option B: One shared SaaS application

Multiple customer companies use the same application and database. Company isolation must be implemented before, or in the same release as, Import Profiles.

For shared SaaS, every relevant master-data row, transaction, pending upload, import profile, import run, record listing, replacement query, and DAT-generation query must be scoped to an organization. The existing global reporting-period replacement behavior must not be used across organizations.

This plan recommends designing profiles with an `organization_id`, but implementing the full tenant layer as a separately reviewed prerequisite if the chosen product model is shared SaaS.

## Scope

### Included

- Create, edit, duplicate, deactivate, and select saved Import Profiles.
- Upload a sample workbook and detect its worksheets and possible heading rows.
- Map source headings to canonical system fields.
- Configure data-start row, date format, amount mode, ignored rows, and controlled default values.
- Preview normalized rows and validation issues before importing.
- Save a versioned profile for reuse.
- Apply the selected profile to Purchase, Sales, and Expanded WTAX uploads.
- Pass normalized rows through record-type-specific validation and persistence rules.
- Preserve the current atomic replacement behavior.
- Record an audit summary for each attempted import.

### Not Included

- Changing any BIR DAT layout, field order, delimiter, header, filename, total, rounding rule, or generator.
- Allowing users to execute PHP, JavaScript, SQL, spreadsheet macros, or unrestricted formulas as transformations.
- Automatically guessing accounting meaning when the choice affects stored amounts.
- Combining Purchase, Sales, and Expanded WTAX storage.
- Replacing existing master-data matching rules unless separately approved.
- Importing unsupported record types such as manual Importation entries in the first release.

## Core Design

Use a controlled normalization pipeline:

```text
Customer workbook
    -> selected Import Profile
    -> raw row reader
    -> canonical record-type row
    -> existing business and BIR preflight rules
    -> existing record-type writer/consolidator
    -> existing database tables
    -> unchanged DAT generators
```

The Import Profile only explains how to read and normalize the customer's workbook. It must not become a second implementation of BIR calculations or DAT generation.

## Canonical Field Schemas

Maintain an allowlisted schema in code for every supported record type. The UI must build its mapping fields from these schemas rather than accepting arbitrary database column names.

Each canonical field definition should contain:

- stable field key
- user-facing label and description
- data type
- required or optional status
- allowed source count
- allowed transformations
- default-value policy
- record-type-specific validation notes

### Purchase

At minimum, expose controlled mappings for:

- supplier TIN
- supplier/company name
- individual name fields, where applicable
- address and city
- exempt purchases
- zero-rated purchases
- Purchase Imported
- Purchase Local
- Services
- Others
- capital goods
- other-than-capital goods
- taxable net of VAT
- VAT rate
- input VAT
- total purchases
- transaction/reporting date, when supplied

Purchase profiles must explicitly choose one amount mode:

1. `vat_bucket_amounts`: Purchase Imported, Purchase Local, Services, and Others are uploaded VAT amounts and are converted once into stored taxable bases using the configured VAT rate.
2. `taxable_base_amounts`: those fields already contain taxable bases.
3. `explicit_bir_fields`: the workbook directly provides the relevant BIR taxable categories and input VAT.

Do not infer the amount mode only from blank or missing columns. A wrong guess can produce valid-looking but incorrect tax values.

### Sales

At minimum, expose mappings for:

- document number and document date
- customer name and TIN
- company/individual name fields
- address and city
- terms, days, due date, agent, and reference where available
- exempt sales
- zero-rated sales
- taxable net of VAT
- output VAT
- gross, discount, charges, and net amounts

The profile must explicitly identify the layout/amount model that the Sales normalizer should use. Existing SI/CM/DM handling and zero-rated consistency rules must remain active after mapping.

### Expanded WTAX

At minimum, expose mappings for:

- reporting month or transaction date
- vendor/payee TIN and branch code
- company or individual name fields
- ATC
- income payment
- EWT rate
- tax amount
- source reference

Support two controlled Expanded modes:

1. `bir_line`: one workbook row supplies one BIR-like WTAX line.
2. `rate_columns`: configured rate columns can expand one workbook row into multiple canonical WTAX lines.

For `rate_columns`, rate-to-ATC mappings must be explicit profile settings. Do not infer ATC from rate alone because more than one ATC can use the same rate.

## Profile Configuration

An Import Profile should contain:

- profile name
- owning organization/company, if applicable
- record type
- report subtype where relevant
- worksheet selection rule
- heading row
- data starting row
- optional ending-row or stop-row rule
- normalized source-heading fingerprint
- canonical field mappings
- amount mode
- accepted date formats
- decimal and negative-number conventions
- blank-row behavior
- controlled ignored-row labels such as `TOTAL`, `GRAND TOTAL`, and `SUBTOTAL`
- controlled default values such as a VAT rate
- Expanded rate-column and ATC settings where applicable
- active/inactive status
- version number
- creator and last updater

Prefer matching a column by normalized heading, with the saved column index used only as a checked fallback. If the heading at that index changes, require review instead of silently reading a different column.

## Proposed Database Tables

### `import_profiles`

- `id`
- `organization_id` nullable only when the deployment model allows it
- `name`
- `record_type`
- `report_subtype` nullable
- `sheet_name` nullable
- `heading_row`
- `data_start_row`
- `amount_mode`
- `date_format` nullable
- `settings` JSON for allowlisted advanced options
- `header_fingerprint` nullable
- `version`
- `is_active`
- `created_by`
- `updated_by`
- timestamps

Add a uniqueness rule appropriate to the deployment model, such as active profile name per organization and record type.

### `import_profile_fields`

- `id`
- `import_profile_id`
- `system_field`
- `source_heading`
- `source_column_index` nullable
- `default_value` nullable
- `is_required`
- `transform` nullable and restricted to an allowlist
- timestamps

Prevent the same canonical single-source field from being mapped twice. Also reject ambiguous duplicate source headings unless the user identifies a specific column occurrence.

### `import_runs`

- `id`
- `organization_id` nullable according to deployment model
- `import_profile_id`
- `profile_version`
- `record_type`
- reporting period/range fields
- original filename
- file checksum
- uploaded by user
- status: `previewed`, `rejected`, `imported`, or `failed`
- total, accepted, skipped, and rejected row counts
- validation summary JSON
- started/completed timestamps

Do not store sensitive workbook contents in the audit table. If files are retained for correction, keep them in private storage with an expiry and integrity checksum, following the existing pending Purchase upload safeguards.

## Backend Components

### 1. Canonical schema registry

Create a service such as `ImportSchemaRegistry` that returns the allowed fields, requirements, and transformations for each record type.

### 2. Workbook inspector

Create a read-only `WorkbookInspector` that:

- lists visible worksheets
- reads a limited number of initial rows
- proposes likely heading rows
- returns normalized headings while retaining original labels
- detects duplicate headings
- does not write records

Put limits on worksheet count, inspected rows, columns, file size, and processing time.

### 3. Profile validator

Create `ImportProfileValidator` to reject:

- missing required mappings
- mappings to headings that do not exist
- duplicate or conflicting mappings
- unsupported transformations
- invalid amount modes
- incomplete Expanded rate/ATC configuration
- unsafe defaults

### 4. Generic row normalizer

Create `ProfiledWorkbookReader` or equivalent. It should read each source row and return a canonical associative array plus source metadata such as worksheet name and row number.

It may apply only controlled transformations, for example:

- trim whitespace
- normalize text casing through existing helpers
- remove permitted number formatting
- parse an approved date format
- join explicitly selected name/address columns
- apply an approved sign convention
- use a configured constant default

It must not perform record-type accounting calculations.

### 5. Record-type adapters

Keep separate adapters after normalization:

- `ProfiledPurchaseRowAdapter`
- `ProfiledSalesRowAdapter`
- `ProfiledExpandedWtaxRowAdapter`

Each adapter should translate the canonical row into the same semantic input currently consumed by that record type's preflight and writer. Shared parsing is useful, but Purchase, Sales, and Expanded rules should not be collapsed into one large importer.

### 6. Side-effect-free preview/preflight

The preview endpoint must use the same mapping and record-type adapter that the final import uses. It should return:

- original worksheet row number
- selected source values
- normalized values
- warning/error status
- actionable field-level messages
- totals and accepted/rejected counts

Preview and final import must not maintain separate mapping logic that can drift.

### 7. Atomic import orchestrator

Create an orchestrator that:

1. Reloads the selected active profile and version.
2. Verifies organization access.
3. Rechecks the uploaded workbook header fingerprint.
4. Normalizes every candidate row.
5. Runs all blocking validation before deletion.
6. Stops with row-level issues if any blocking error exists.
7. Opens the existing record-type-specific replacement transaction only after validation succeeds.
8. Reuses existing consolidation and write behavior.
9. Stores the import-run result.

No invalid or structurally changed workbook may partially replace an existing reporting period.

## User Interface Plan

### Profile management page

Add an `Import Profiles` page under an appropriate Settings or Master Data section.

The list should show:

- profile name
- company/organization
- record type
- worksheet and heading row
- amount mode
- version
- active status
- last updated date

Available actions:

- Create
- Edit
- Duplicate
- Test with sample file
- Deactivate

Use deactivation instead of hard deletion after a profile has import history.

### Profile creation wizard

Use a guided workflow:

1. Choose company and record type.
2. Upload a sample workbook.
3. Select worksheet and confirm heading/data rows.
4. Map required and optional system fields using dropdowns populated from detected headings.
5. Select amount mode and controlled transformations.
6. Preview 5 to 10 normalized rows.
7. Resolve blocking configuration or data issues.
8. Name and save the profile.

Display business labels and short explanations. Do not expose database column names or internal IDs.

### Upload-page integration

Update the existing upload screen so that after the record type is selected, the user selects a compatible active Import Profile.

Recommended behavior:

- Keep the existing built-in layouts as protected `System Default` profiles or a compatibility path.
- Filter profiles by organization, record type, and report subtype.
- Show the selected profile's expected sheet, heading row, date format, and amount mode before upload.
- Provide `Preview Import` before the final confirmation.
- If headings changed, block the import and offer to duplicate/update the profile; do not mutate a proven profile automatically.

## Built-in Layout Compatibility

Do not immediately rewrite or remove all current importers.

Recommended migration:

1. Register the current Purchase, Sales Summary, BIR Sales, BIR Expanded, and system Expanded layouts as protected built-in definitions.
2. Prove the canonical pipeline produces the same stored rows for representative fixtures.
3. Route custom profiles through the new engine first.
4. Move built-in layouts to the shared engine only after parity tests pass.

This reduces regression risk while customer-specific profiles are introduced.

## Validation and Safety Rules

- Validate access to the selected organization and profile on every request.
- Never trust client-submitted system field names, column indexes, amount modes, defaults, or transformations without server-side validation.
- Reject formulas with spreadsheet errors; consume calculated values only where the existing workflow permits them.
- Do not execute workbook macros.
- Protect against spreadsheet formula injection in any later CSV/Excel export.
- Reject unsupported merged-heading structures rather than guessing.
- Preserve worksheet row numbers in every issue.
- Validate reporting period/range before replacement.
- Keep Supplier/Customer/Company BIR validation actionable and early.
- Retain the existing Purchase exclusions and Sales debit-memo behavior.
- Keep Expanded withholding-company, report-type, period, ATC, rate, and amount rules.
- Require user confirmation when a header fingerprint differs from the saved profile.
- Store profile version with each import run so historical uploads remain explainable.

## Tenant Isolation Requirements for Shared SaaS

If shared SaaS is selected, add and enforce organization ownership across:

- users and memberships
- Suppliers, Customers, and withholding companies
- Purchase, Sales, Expanded WTAX, Importation, and adjustment-related records
- pending uploads and retained files
- Import Profiles and import runs
- dashboards, search, record pages, exports, attachments, and DAT generation

Use policies and organization-scoped services/queries. Do not depend only on hidden form fields or frontend filtering.

Before adding tenant keys, audit unique constraints and replacement/consolidation identities. A supplier TIN, document number, profile name, or reporting period that is unique for one customer may legitimately exist for another.

## Suggested Routes and Requests

Names are illustrative and should follow the project's route conventions:

- `GET /import-profiles`
- `POST /import-profiles/inspect`
- `POST /import-profiles/preview`
- `POST /import-profiles`
- `PUT /import-profiles/{profile}`
- `POST /import-profiles/{profile}/duplicate`
- `PATCH /import-profiles/{profile}/deactivate`
- `POST /imports/preview`
- `POST /imports/commit`

The commit request should identify the uploaded file/token, profile ID, expected profile version, record type, and reporting scope. The server must reload and verify all of them.

## Testing Plan

### Profile configuration tests

- Create a valid profile for each record type.
- Reject missing required fields.
- Reject duplicate canonical mappings.
- Reject unknown system fields and transformations.
- Reject incomplete amount-mode settings.
- Reject an Expanded rate column without an explicit ATC.
- Prevent access to another organization's profile.
- Preserve inactive profiles in import history while excluding them from new uploads.

### Workbook inspection tests

- Detect headings on rows 1, 3, 5, and other configured rows.
- Select a named worksheet.
- Handle reordered columns.
- Handle harmless heading punctuation/case differences.
- Report duplicate headings clearly.
- Reject missing sheets, excessive dimensions, merged ambiguous headings, and structurally empty files.

### Purchase tests

- Map a differently named and reordered customer workbook.
- Prove each amount mode stores the expected values.
- Prove VAT-bucket values are converted exactly once.
- Reject mixed or ambiguous amount semantics.
- Preserve Supplier lookup, excluded-supplier behavior, imported/local buckets, consolidation, and reporting-period replacement boundaries.
- Compare stored rows and DAT bytes against the equivalent built-in-layout fixture.

### Sales tests

- Map different document/customer headings and positions.
- Preserve SI and CM handling and skip DM rows as currently defined.
- Validate zero-rated, exempt, taxable, VAT, net, and gross relationships.
- Preserve Customer matching and reporting-period replacement.
- Compare stored rows and DAT bytes with equivalent existing layouts.

### Expanded WTAX tests

- Map a direct BIR-line customer layout.
- Expand configured rate columns into the correct number of canonical lines.
- Require explicit ATC per configured rate column.
- Preserve formulas as calculated workbook values where currently supported.
- Preserve quarterly/annual period rules, withholding-company scope, consolidation, and BIR validation.
- Compare stored rows and DAT bytes with equivalent existing layouts.

### Atomicity and audit tests

- Seed existing records, upload a file containing valid and invalid rows, and prove no rows are inserted, deleted, or changed.
- Change a heading after preview and prove commit revalidation blocks the import.
- Change/deactivate the profile between preview and commit and prove the stale version is rejected.
- Verify successful and rejected import-run counts and profile versions.
- Verify one organization's re-upload cannot read, replace, or report another organization's data.

### Regression suites

Run the existing focused Purchase, Sales, Expanded WTAX, upload-preflight, consolidation, record-page, attachment, and DAT generator suites. Add byte-for-byte DAT fixtures where the current suite does not already prove format parity.

## Implementation Phases

### Phase 0: Deployment and tenant decision

- Choose separate installations or shared SaaS.
- If shared SaaS, write and approve a separate tenant-isolation migration plan before importer implementation.
- Inventory all unscoped replacement, search, export, and DAT queries.

### Phase 1: Foundation

- Add profile, field, and import-run tables/models.
- Add the canonical schema registry and profile validator.
- Add authorization and organization ownership appropriate to the deployment model.
- Register current layouts as protected built-in definitions or compatibility metadata.

### Phase 2: Inspector and profile UI

- Implement safe workbook inspection.
- Build the profile wizard and management list.
- Add mapping/configuration validation.
- Add versioning and deactivation.

### Phase 3: Purchase pilot

- Implement the generic reader and Purchase adapter.
- Add explicit Purchase amount modes.
- Add preview using the real Purchase validation path.
- Import through the current atomic Purchase replacement service.
- Prove database and DAT parity with existing Purchase fixtures.

### Phase 4: Sales

- Add the Sales canonical adapter and layout/amount configuration.
- Reuse the current Sales amount normalizer and preflight semantics.
- Add parity and atomic-replacement tests.

### Phase 5: Expanded WTAX

- Add direct-line and rate-column adapters.
- Add explicit rate-to-ATC profile configuration.
- Reuse the current Expanded row mapping, quarterly/annual checks, and BIR preflight where practical.
- Add parity tests for both current Expanded layouts.

### Phase 6: Hardening and rollout

- Add audit reporting and support diagnostics.
- Test realistic customer workbooks with redacted data.
- Document profile setup and correction workflows.
- Pilot with one customer/company before enabling profile creation broadly.
- Monitor rejection reasons and improve guidance without weakening validation.

## Expected Files and Areas

Exact names may change after implementation inspection, but the work will likely affect:

- new migrations and models for profiles, profile fields, and import runs
- new services under `app/Imports` or `app/Services/Imports`
- controllers, requests, policies, and routes for profile management and preview/commit
- `app/Http/Controllers/VatInputController.php` or a new import orchestration controller
- existing Purchase, Sales, and Expanded import/preflight services through narrow adapters
- `resources/js/Pages/RecordEntry.jsx`
- new Import Profile management/wizard pages and reusable mapping components
- focused unit and feature tests
- user-facing format documentation

Do not treat this list as permission for unrelated refactoring. Review every changed file and hunk against this feature before completion.

## Acceptance Criteria

The feature is complete when:

- an authorized user can configure and save a reusable mapping for a differently named/reordered workbook
- the user sees a normalized preview and actionable row-level errors before replacement
- Purchase amount meaning is explicit and is never guessed when it changes stored values
- Expanded rate columns have explicit ATC mappings
- invalid uploads leave existing records unchanged
- a changed workbook structure cannot silently reuse an incompatible profile
- successful custom-layout imports produce the same canonical stored values and DAT output as equivalent supported built-in layouts
- profile versions and import results are auditable
- built-in uploads continue to behave as before
- shared-SaaS deployments prove organization isolation across uploads, records, and DAT generation

## Recommended First Deliverable

Implement only the foundation, mapping wizard, preview, and Purchase custom profiles first. Keep Sales and Expanded WTAX on their current built-in paths until Purchase parity and usability are proven with a real customer workbook.

This provides a usable customer-onboarding feature while limiting the first release's accounting risk. The shared engine should still be record-type-neutral so Sales and Expanded WTAX can be added without rebuilding the profile system.
