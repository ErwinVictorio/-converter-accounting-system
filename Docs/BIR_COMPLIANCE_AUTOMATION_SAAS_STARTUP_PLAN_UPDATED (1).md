# BIR Compliance Automation SaaS - Final Startup Plan

## Document Status

**Status:** Final implementation plan - not yet implemented  
**Current application:** Laravel, Inertia, and React BIR automation system  
**Target architecture:** Shared application and database with strict organization-level isolation  
**Primary compatibility boundary:** Existing stored tax values, validations, calculations, consolidation, reporting-period behavior, and BIR DAT output

This document replaces the earlier duplicated startup roadmaps. It defines the approved product direction, architecture boundaries, implementation order, phase gates, and first-pilot scope.

---

## 1. Product Vision

Transform the current company-specific BIR automation application into a multi-company **BIR Compliance Automation SaaS platform**.

The platform should allow businesses, accounting firms, and bookkeeping teams to:

- Register and manage their organization.
- Invite users and assign controlled roles.
- Maintain organization-specific BIR and master data.
- Upload Excel files from different accounting systems.
- Configure reusable Import Profiles.
- Preview and validate records before replacement or import.
- Resolve actionable master-data issues.
- Manage Purchase, Sales, Expanded WTAX, and Importation records.
- Generate BIR-compliant DAT files and readable attachments.
- Trace imports, validations, changes, and generated outputs.
- Later use subscription plans, usage limits, and accounting-firm features.

The product should be positioned as a:

> **BIR Compliance Automation Platform**

The main promise is:

> Keep the customer's accounting system. Import its Excel output, validate BIR requirements, organize the tax records, and generate compliant DAT files through a controlled and auditable workflow.

---

## 2. Current System Baseline

The current application already has working business flows that must be preserved:

- Authenticated login and logout.
- Purchase, Sales, Expanded WTAX, and Importation records.
- Supplier, Customer, Broker, and Withholding Company master data.
- Purchase and Sales upload preflight checks.
- Retained Purchase and Sales workbooks for fix-and-retry workflows.
- Reporting-period replacement inside database transactions.
- Purchase adjustments and protected adjustment reversal.
- Record listings, filters, search, and dashboard metrics.
- Monthly and annual BIR validation where applicable.
- Purchase, Sales, Importation, Expanded WTAX, and annual Expanded DAT generation.
- DAT attachment reports.

The current application is still effectively single-tenant:

- Users do not belong to organizations through a membership model.
- Master data and transaction tables are not organization-owned.
- Upload replacement queries are scoped by reporting period, not by organization.
- Dashboard, record, search, export, attachment, and DAT queries are not consistently tenant-scoped.
- Pending uploads are owned by a user but not by an organization.
- Some unique constraints represent global identities that must become tenant-aware.
- There is no Import Profile or Import Run implementation yet.
- Job infrastructure exists, but workbook processing is not yet a complete background-job workflow.

This baseline means tenant isolation is a prerequisite for a shared SaaS launch.

---

## 3. Non-Negotiable Compatibility Boundaries

SaaS conversion must not silently change the current tax-processing results.

Unless separately reviewed and approved, preserve:

- DAT file type and layout.
- Header and detail field order.
- Delimiters and line structure.
- Filename rules.
- Record counts and totals.
- Stored taxable values and VAT values.
- Rounding and decimal behavior.
- Purchase imported/local/services/others meaning.
- Supplier and Customer matching behavior.
- Purchase consolidation and adjustment behavior.
- Sales SI/CM handling and DM exclusions.
- Expanded WTAX ATC, rate, reporting-period, and company rules.
- Importation-to-Purchase mirror behavior.
- Existing upload validation and atomic replacement safeguards.
- Existing DAT generators until parity is proven.

The SaaS work should add ownership, authorization, configuration, traceability, and operational controls around the existing tax engine. It should not rewrite the tax engine as part of the tenant migration.

---

## 4. Core Product Guarantees

The platform must be designed around five guarantees:

1. **Tenant safe** - one organization cannot access or affect another organization's data.
2. **Configurable** - different workbook layouts can be supported without changing PHP code for every customer.
3. **Deterministic** - accounting meaning is explicitly configured and never guessed when it affects stored values.
4. **Auditable** - important imports and outputs can be traced to their source, configuration, actor, and applicable rules.
5. **Recoverable** - imports are atomic, failures are observable, backups are tested, and customer data is protected.

---

## 5. Final Tenant Architecture

Use one shared application and database with row-level organization ownership.

### 5.1 Organization as the tax boundary

An `organization` is the legal/customer context that owns:

- BIR company information.
- Users and memberships.
- Suppliers and Customers.
- Brokers and Withholding Companies.
- Purchase, Sales, Expanded WTAX, and Importation records.
- Adjustments.
- Pending uploads and retained files.
- Import Profiles and Import Runs.
- Reports, attachments, and generated DAT history.
- Reporting periods, approvals, usage, and audit events.

The existing `withholding_companies` concept must remain distinct. A Withholding Company is filing/master data used by the current Expanded WTAX flow; it is not automatically the SaaS tenant.

### 5.2 Membership model

Recommended core tables:

#### `organizations`

- `id`
- `name`
- `tin`
- `branch_code`
- `registered_address`
- `city`
- `email`
- `phone`
- `status`
- `trial_ends_at` nullable
- timestamps

#### `organization_memberships`

- `id`
- `organization_id`
- `user_id`
- `role`
- `status`
- timestamps

A user may belong to more than one organization. A unique constraint should prevent duplicate membership for the same user and organization.

### 5.3 Current organization context

Every organization-scoped request must follow:

```text
Authenticated User
        -> Active Membership
        -> Authorized Current Organization
        -> Organization-Scoped Action or Query
```

Do not trust an arbitrary `organization_id` supplied by the browser. The backend must resolve the organization through an authenticated membership and authorize every route-bound resource.

### 5.4 Accounting firm model

Accounting-firm mode is a later layer, not a replacement for organization tenancy.

Recommended relationship:

```text
Accounting Firm Workspace
        -> Authorized Accountants
        -> Client Organization A
        -> Client Organization B
        -> Client Organization C
```

Each client remains an independent organization and tax boundary. Firm users receive explicit access to selected client organizations.

---

## 6. Tenant Ownership Audit

Before migrations are implemented, create an ownership matrix covering every current table and query.

At minimum, review:

- `users`
- `suppliers`
- `customers`
- `brokers`
- `withholding_companies`
- `vat_inputs`
- `sales_vatsinputs`
- `expanded_wtax_entries`
- `importation_entries`
- `purchase_adjustments`
- `pending_purchase_uploads`
- `pending_sales_uploads`
- uploaded and generated files
- dashboard queries
- record listings and searches
- reporting-period replacement queries
- adjustment lookup and reversal queries
- exports and attachments
- DAT selection and generation

For each area, document:

- Owning organization.
- Existing identity and uniqueness rules.
- Read, create, update, delete, and replace paths.
- File ownership.
- Backfill source for existing records.
- Required policy and isolation tests.

### Unique constraint review

Global identities may need organization-scoped equivalents, including:

- Supplier or Customer TIN where the current business rule treats it as unique.
- Withholding Company TIN and branch.
- Sales document/customer/reporting-period identity.
- Importation tax month and entry number.
- Import Profile name and record type.
- Active import lock for a reporting scope.

The exact constraint must follow the existing business meaning. Do not add `organization_id` mechanically without reviewing duplicates, foreign keys, consolidation, and replacement identities.

---

## 7. Existing Data Migration Strategy

The current database must be migrated without losing or reinterpreting records.

Recommended sequence:

1. Create one default organization for the existing installation.
2. Create an owner membership for the existing authorized administrator.
3. Add nullable `organization_id` columns during the controlled migration window.
4. Backfill existing master data, transactions, adjustments, and pending uploads to the default organization.
5. Verify row counts, relationships, totals, and representative DAT outputs.
6. Add organization-aware foreign keys, indexes, and unique constraints.
7. Make organization ownership required where appropriate.
8. Deploy organization-scoped application queries and authorization.
9. Run cross-tenant isolation and compatibility tests before enabling a second organization.

The migration must include a documented rollback/recovery procedure. A schema migration is not complete until the data backfill and compatibility verification pass.

---

## 8. Roles and Authorization

Recommended organization roles:

- **Owner** - organization control, users, settings, subscription, and all tax workflows.
- **Admin** - users, configuration, master data, imports, records, and DAT operations except ownership-sensitive actions.
- **Accountant** - imports, records, validation, reports, and DAT generation.
- **Encoder** - upload, encode, and correct allowed master data.
- **Reviewer** - review validation and approve reporting scopes when approval workflow is enabled.
- **Viewer** - read-only access.

Authorization rules must be enforced on the backend through policies, gates, middleware, or tenant-scoped services. Hiding a button is not authorization.

Prevent privilege escalation by ensuring that a user cannot:

- Assign a role above their permitted level.
- Invite a user into an unauthorized organization.
- Change the organization of an existing resource.
- Access another organization's route-bound model by changing an ID.
- Use a retained upload, profile, import run, report, or generated file owned by another organization.

---

## 9. Registration, Invitations, and Onboarding

Registration should be implemented only after tenant isolation works.

### Registration flow

```text
Create Account
        -> Verify Email
        -> Create Organization
        -> Become Organization Owner
        -> Complete Company and BIR Setup
        -> Invite Team Members
        -> Configure First Import Workflow
```

### Invitation requirements

Recommended `organization_invitations` fields:

- organization
- email or username identity
- assigned role
- secure token
- expiry
- invited by
- accepted, revoked, and created timestamps

Support expiry, resend, revoke, role authorization, and audit history.

### Workflow-specific readiness

Do not use one global readiness percentage to block every module. Track readiness by workflow.

Examples:

- Purchase requires organization BIR information, Supplier requirements, and a compatible Purchase import path.
- Sales requires organization BIR information, Customer requirements, and a compatible Sales import path.
- Expanded WTAX requires an authorized Withholding Company, report type, period settings, and a compatible import path.
- DAT generation requires complete valid records for the selected tax type and period.

One workflow may be ready while another remains incomplete.

---

## 10. Import Profile Architecture

The detailed Import Profile design remains in `Docs/CONFIGURABLE_EXCEL_IMPORT_PROFILES_PLAN.md`. That document is the authoritative detailed plan for workbook mapping.

The core pipeline is:

```text
Customer Workbook
        -> Selected Organization-Owned Import Profile
        -> Safe Workbook Reader
        -> Canonical Record-Type Row
        -> Existing Preflight and Business Rules
        -> Existing Writer or Consolidator
        -> Existing Record-Type Storage
        -> Existing DAT Generator
```

### Required principles

- Purchase, Sales, and Expanded WTAX keep separate canonical schemas and adapters.
- Preview and final commit use the same reader, mapping, adapter, and validation logic.
- Profile fields come from allowlisted schemas, not arbitrary database column names.
- Unsupported or changed workbook structures are blocked instead of guessed.
- Built-in layouts remain available while custom profiles are piloted.
- Built-in importers are not removed until equivalent stored values and DAT bytes are proven.
- Profile versions used by historical imports remain traceable and immutable.

### Purchase amount modes

Every Purchase profile must explicitly select one mode:

- `vat_bucket_amounts`
- `taxable_base_amounts`
- `explicit_bir_fields`

The system must not infer the meaning of an `Amount` column or infer the mode from blank columns.

### Expanded WTAX modes

Support:

- `bir_line`
- `rate_columns`

For `rate_columns`, each rate column must have an explicit ATC mapping. Rate alone cannot safely determine ATC.

---

## 11. Import Center and Import Runs

The Import Center should eventually unify visibility without forcing all tax types into one importer.

Recommended flow:

```text
Upload
    -> Select or Detect Compatible Profile
    -> Inspect Workbook
    -> Preview Canonical Rows
    -> Run Validation
    -> Resolve Blocking Issues
    -> Confirm Reporting Scope
    -> Revalidate
    -> Atomic Commit
    -> Record Import Result
```

Recommended `import_runs` data:

- organization
- Import Profile and version
- record type and subtype
- reporting scope
- original filename and checksum
- uploaded by
- processing status
- total, accepted, skipped, and rejected row counts
- validation summary
- started and completed timestamps
- source-file retention reference where policy permits

Do not store unnecessary sensitive workbook contents inside audit metadata.

### Processing states

For background processing, use real backend states such as:

- `uploaded`
- `queued`
- `inspecting`
- `validating`
- `ready_for_review`
- `importing`
- `completed`
- `rejected`
- `failed`
- `cancelled`

Do not display fake progress percentages. Show a percentage only when the backend can calculate meaningful completed work.

---

## 12. Validation and Error Resolution

Validation must be early, actionable, atomic, and record-type aware.

Errors should identify:

- Workbook row.
- Affected master-data identity.
- Field or rule that failed.
- Number of affected rows.
- Whether the issue can be repaired in the application or requires re-upload.
- The action required from the user.

### Repair boundaries

- Purchase Supplier-fixable issues may use the Purchase retained-workbook queue.
- Sales Customer-fixable issues may use the separate Sales retained-workbook queue.
- Workbook structure, amount meaning, record type, invalid period, and unsupported layout issues require correction and re-upload.
- Expanded WTAX ATC/rate/type/period errors must not be silently repaired through unrelated master-data workflows.

Every retry must perform a fresh preflight before replacement. No invalid upload may partially delete or replace an existing reporting scope.

---

## 13. Duplicate and Concurrent Import Protection

### File duplicate detection

Use checksums to warn when the exact file has already been uploaded or imported for the organization.

A checksum match is not business-level duplicate detection. Different files may contain the same transactions, and identical files may be intentionally reprocessed.

If re-import is allowed:

- Require an authorized role.
- Show the existing import and affected reporting scope.
- Explain whether records will be replaced.
- Record the decision in audit history.

### Concurrent replacement protection

Only one active replacement should operate on the same scope:

```text
organization_id
+ record_type
+ report_subtype where applicable
+ reporting_scope
```

The final commit must recheck:

- Organization authorization.
- Import Profile ID and expected version.
- Header fingerprint.
- Reporting scope.
- Active import lock or version.
- Whether another import replaced the scope after preview.

Possible mechanisms include an import-lock table, database advisory locks, or a transaction-safe unique active lock.

---

## 14. Auditability and BIR Rule Versioning

The system should trace:

```text
Generated DAT or Report
        -> Stored Records
        -> Import Run or Manual Action
        -> Import Profile Version
        -> Source Workbook and Worksheet Row
        -> User and Organization
        -> Applicable BIR Rule Version
```

Audit events should capture controlled facts such as actor, organization, action, resource, timestamp, and before/after metadata where appropriate. Avoid copying complete sensitive workbooks or credentials into logs.

BIR rules that change over time should use effective-dated versioning. A later rule must not silently reinterpret historical records or outputs that belong to an earlier valid rule set.

---

## 15. File Security and Retention

Uploaded workbooks, preview files, generated attachments, and DAT packages must be privately stored.

Required controls:

- Authorized download endpoints.
- Organization ownership verification.
- File type, size, worksheet, row, and column limits.
- Parsing time limits.
- No macro execution.
- Controlled handling of formula results.
- Integrity checksums.
- Configurable retention categories.
- Audited expiry and deletion jobs.
- Protection against predictable public URLs.

Define separate retention rules for:

- temporary uploads
- retained fix-queue workbooks
- original source workbooks
- generated reports and DAT packages
- import and audit metadata

---

## 16. Subscription and Entitlement Architecture

Do not build payment processing before the tax workflow and tenant foundation are stable.

Tax-processing code should depend on product entitlements, not directly on a payment gateway.

Example entitlements:

- `can_import_purchase`
- `can_import_sales`
- `can_import_expanded_wtax`
- `can_create_custom_profiles`
- `can_manage_multiple_organizations`
- `can_use_approval_workflow`

Possible future plans:

- Starter
- Business
- Accounting Firm
- Enterprise

Define subscription states and their behavior:

- Trial
- Active
- Past due
- Grace period
- Suspended
- Cancelled

The default suspension behavior should preserve customer data and provide controlled read-only access where practical. Cancellation must include export and retention rules; it must not immediately destroy accounting data.

---

## 17. Platform Administration and Support Access

Platform administration is separate from customer organization administration.

Future platform capabilities may include:

- organizations and subscription status
- system usage
- failed imports and jobs
- storage and queue health
- feature flags
- support diagnostics
- announcements
- BIR rule configuration

Support diagnostics should prefer metadata such as import ID, organization, profile version, file checksum, structure, counts, status, and error codes.

If support access to a customer organization is introduced:

- require a reason
- identify the support user and organization
- record start and end timestamps
- use read-only access by default
- require elevated approval for sensitive actions
- never allow silent unrestricted impersonation

---

## 18. Operational and Security Requirements

Before broad production use, implement and verify:

- Server-side tenant authorization.
- Cross-tenant automated tests.
- Secure authentication and password reset.
- Optional multi-factor authentication after the core launch.
- Rate limiting.
- Private file storage.
- Encrypted transport and secure secret management.
- Queue failure monitoring and retries.
- Database and private-file backups.
- Periodic restore tests.
- Error and performance monitoring.
- Tenant-safe logs with minimal sensitive data.
- Incident-response procedure.
- Data export and account-closure process.
- Terms, privacy, retention, support, billing, and acceptable-use policies that match actual behavior.

A backup is not considered reliable until a restoration has been tested.

---

## 19. Final Implementation Roadmap

### Phase 0 - Compatibility baseline and tenant audit

- Inventory every organization-scoped table, query, route, file, and job.
- Record current row counts, totals, and representative workflows.
- Create representative stored-value and byte-for-byte DAT fixtures.
- Review unique constraints and replacement identities.
- Define existing-data backfill and rollback procedures.

**Exit gate:** Approved ownership matrix, migration design, rollback plan, and reproducible compatibility baseline.

### Phase 1 - Organization foundation

- Add organizations and memberships.
- Create the default organization and backfill existing users.
- Implement current-organization resolution.
- Add roles and centralized authorization.
- Add organization switching only after backend authorization works.

**Exit gate:** Current organization can be resolved only through an active authorized membership.

### Phase 2 - Tenant-scope the existing application

- Add organization ownership to master data, transactions, adjustments, and pending uploads.
- Scope record listings, search, dashboard, and CRUD actions.
- Scope upload preflight and reporting-period replacement.
- Scope adjustment, Importation mirror, consolidation, export, attachment, and DAT operations.
- Replace applicable global unique constraints with reviewed organization-aware constraints.

**Exit gate:** Organization A cannot view, modify, replace, export, or generate DAT from Organization B data. Existing single-organization outputs remain compatible.

### Phase 3 - Registration, invitations, and onboarding

- Add registration and verification.
- Add organization creation and owner assignment.
- Add invitations and role controls.
- Add company/BIR setup and workflow-specific readiness.

**Exit gate:** A new authorized organization can complete setup without accessing another organization's resources.

### Phase 4 - Import Profile foundation

- Add profiles, fields, immutable versions, and import runs.
- Add canonical schema registry and profile validator.
- Add safe workbook inspection.
- Register protected built-in compatibility definitions.
- Add profile management and mapping wizard foundations.

**Exit gate:** Profiles are organization-owned, versioned, safely validated, and cannot access or use another organization's files or configuration.

### Phase 5 - Purchase custom-profile pilot

- Implement Purchase mapping and explicit amount modes.
- Use one normalization/validation path for preview and commit.
- Reuse current Supplier rules, exclusions, consolidation, and atomic replacement.
- Compare stored values and DAT bytes with equivalent built-in fixtures.
- Pilot with one controlled customer organization.

**Exit gate:** Equivalent built-in and custom-profile workbooks produce identical canonical stored values and DAT output.

### Phase 6 - Import Center and processing reliability

- Add unified import visibility and Import Run history.
- Add background inspection, validation, and import for large workbooks.
- Add meaningful progress states.
- Add duplicate-file warnings.
- Add reporting-scope concurrency locks.
- Add retry-safe failure handling and commit-time revalidation.

**Exit gate:** Competing or stale imports cannot silently replace the same reporting scope.

### Phase 7 - Validation Center

- Group actionable errors by category and affected identity.
- Link Supplier-fixable Purchase issues to the Purchase queue.
- Link Customer-fixable Sales issues to the Sales queue.
- Require re-upload for workbook, amount, type, and period problems.
- Add fresh revalidation and clear readiness status.

**Exit gate:** Every supported blocking issue has an explicit resolution path, and failed correction attempts leave stored records unchanged.

### Phase 8 - Sales custom profiles

- Add Sales canonical mapping and controlled amount/layout configuration.
- Preserve SI/CM handling, DM exclusions, Customer matching, and zero-rated rules.
- Add stored-value and DAT parity tests.

**Exit gate:** Custom and built-in Sales paths are equivalent for representative fixtures.

### Phase 9 - Expanded WTAX custom profiles

- Add direct BIR-line and rate-column modes.
- Require explicit rate-to-ATC mappings.
- Preserve Withholding Company, report-type, period, validation, and consolidation rules.
- Add quarterly and annual parity tests.

**Exit gate:** Custom and built-in Expanded paths produce equivalent stored values and applicable DAT output.

### Phase 10 - Audit and rule versioning

- Add source-row, profile-version, user, organization, and output traceability.
- Add generated-file history.
- Add effective-dated BIR rule sets.
- Add controlled audit views and support diagnostics.

**Exit gate:** A generated output can be explained using immutable source and configuration metadata.

### Phase 11 - Production hardening

- Finalize retention and deletion jobs.
- Implement backups and restoration drills.
- Add monitoring, queue health, rate limits, and feature flags.
- Complete security review and incident procedures.
- Complete export, closure, and legal/product policies.

**Exit gate:** Operational launch checklist and recovery drill are approved.

### Phase 12 - Accounting firm mode

- Add firm workspaces and client assignments.
- Add authorized client switching.
- Add multi-client compliance status.
- Add firm-level entitlement and usage rules.

**Exit gate:** Firm users can access only explicitly assigned clients, and each client remains an isolated organization.

### Phase 13 - Subscription and platform operations

- Add plans, entitlements, trials, usage, and lifecycle rules.
- Integrate billing only after entitlement behavior is stable.
- Add platform administration and controlled support access.
- Add notifications and a compliance-readiness calendar.

**Exit gate:** Subscription state changes cannot corrupt, expose, or silently delete tax data.

---

## 20. First Safe SaaS MVP

The first external pilot should include:

- One migrated default organization.
- Organization membership and tenant-safe authorization.
- Organization-scoped existing master data and tax records.
- Organization-scoped uploads, replacements, records, dashboard, reports, and DAT generation.
- Owner, Admin, Accountant, Encoder, Reviewer, and Viewer authorization rules.
- Company and BIR setup.
- Purchase built-in import compatibility.
- Purchase custom Import Profile.
- Preview using the final normalization and validation path.
- Existing Purchase validation and atomic replacement.
- Duplicate warning and concurrent replacement protection.
- Import history and source/profile traceability.
- Unchanged Purchase DAT output.
- Private file retention.
- Cross-tenant tests, compatibility tests, backups, and monitoring.

### Explicitly outside the first pilot

- Accounting-firm mode.
- Payment gateway and public checkout.
- Custom Sales profiles.
- Custom Expanded WTAX profiles.
- Enterprise approval workflow.
- Compliance calendar.
- Public API.
- Support impersonation.
- Automatic accounting interpretation or AI-based amount guessing.

These items may be designed for future compatibility but should not delay proof of tenant safety and Purchase parity.

---

## 21. Pilot Acceptance Criteria

The first pilot is ready only when all of the following are true:

- Existing data is assigned to the correct default organization without loss.
- Every organization-owned model and file has backend authorization.
- Cross-tenant route-ID manipulation is rejected.
- One organization's upload cannot replace another organization's period.
- Dashboard, search, exports, attachments, and DAT generation return only authorized organization data.
- Invalid uploads leave existing records unchanged.
- A changed workbook structure cannot silently reuse an incompatible profile.
- Preview and commit use the same normalization and validation path.
- Purchase amount meaning is explicit.
- Equivalent built-in and custom Purchase imports produce identical stored values.
- Equivalent built-in and custom Purchase records produce identical DAT bytes.
- Import Profile versions and Import Runs are traceable.
- Concurrent replacement of the same reporting scope is prevented.
- Retained workbooks and generated files are private and organization-owned.
- Backup restoration has been tested.
- Monitoring can identify failed imports, failed jobs, and storage problems.

---

## 22. Final Product Direction

```text
BIR Compliance Automation SaaS
|
+-- Identity and Organization Security
|   +-- Registration and Verification
|   +-- Organizations and Memberships
|   +-- Roles and Authorization
|   +-- Invitations and Company Switching
|
+-- Company and BIR Setup
|   +-- Organization Information
|   +-- Workflow-Specific Readiness
|   +-- Suppliers, Customers, Brokers, and Companies
|
+-- Import Platform
|   +-- Protected Built-in Layouts
|   +-- Organization-Owned Import Profiles
|   +-- Safe Workbook Inspection
|   +-- Canonical Mapping and Preview
|   +-- Validation and Correction Queues
|   +-- Atomic Import and Import History
|
+-- Tax Workflows
|   +-- Purchase
|   +-- Sales
|   +-- Expanded WTAX
|   +-- Importation
|   +-- Adjustments and Consolidation
|
+-- Compliance Output
|   +-- Validation Status
|   +-- Unchanged DAT Generators
|   +-- Attachments and Reports
|   +-- Audit and Rule Versioning
|
+-- Later Expansion
|   +-- Accounting Firm Mode
|   +-- Subscriptions and Billing
|   +-- Approval Workflows
|   +-- Notifications and Calendar
|   +-- Platform Administration
|
+-- Production Operations
    +-- Private Storage and Retention
    +-- Monitoring and Queues
    +-- Backups and Recovery
    +-- Feature Flags and Security
```

The final architectural rule is:

> **Build and prove organization ownership and tenant isolation before enabling public SaaS onboarding or customer-configurable imports. Preserve the existing tax engine, pilot Purchase custom profiles through the same validation and storage rules, and expand only after stored-value and DAT parity are proven.**

