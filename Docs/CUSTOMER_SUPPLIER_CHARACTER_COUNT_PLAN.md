# Customer and Supplier Character Count Plan

Status: Implemented and verified on 2026-09-25.

## Requested behavior

Exclude spaces from character-limit validation in Customer and Supplier Add/Edit forms. Preserve word spacing in submitted values and keep existing save-time formatting behavior.

Assumed scope: Name, Address, and City in both modules. Existing limits remain:

| Field | Maximum counted characters |
| --- | ---: |
| Customer / Supplier Name | 50 |
| Address | 30 |
| City | 30 |

Letters, digits, and punctuation count. Ordinary spaces do not. Use a consistent whitespace definition in JavaScript and PHP, including pasted non-breaking spaces and tabs/newlines; whitespace-only values remain invalid. Count Unicode code points consistently. For example, `ABC TRADING` counts as 10 characters and retains its word separator.

## Current findings

- `resources/js/Pages/ManageCustomer.jsx` sets native `maxLength` through `renderField` for Add/Edit Name, Address, and City.
- `resources/js/Pages/ManageSupplier.jsx` sets the same native limits separately in Add/Edit fields.
- `resources/js/lib/FormSchema.js` uses Zod `.max(...)` in `customerSchema` and `supplierSchema`, which includes spaces.
- `app/Http/Controllers/CustomerController.php` shares `validateCustomer()` between store and update. `SupplierController.php` repeats Laravel `max` rules in store and update. These rules include spaces.
- Existing limits are 50/30/30 in `config/bir.php` and frontend `birFieldLimits`; changing these shared numbers would affect unrelated workflows.
- The original supplier migration allows only 60 total characters for `name`. Customer columns are larger. Live schema has not been checked, so accepting more spaces must be reconciled with actual storage capacity.
- Customer saves already normalize text and synchronize matching Sales rows. Preserve those existing behaviors.

## Implementation steps

1. Add a reusable frontend counting helper and matching Laravel validation rule. Count a temporary whitespace-free copy; never use that copy as the saved field value. Apply the rule only to the scoped Customer/Supplier text fields. Retain required-field and TIN validation.
2. Replace the six schema length checks with non-whitespace length validation. Show errors such as `Maximum 50 characters, excluding spaces.` Ensure whitespace-only input fails required validation.
3. Remove native 50/30-character `maxLength` restrictions from the scoped Add/Edit inputs because the browser counts spaces. Allow full typing and pasting, display a live counter such as `42 / 50 (excluding spaces)`, and block saving over-limit values with a field error. Do not silently truncate text.
4. Apply the matching server rule to both Customer and Supplier create/update paths so direct requests behave like the UI. Leave shared limit values and unrelated schemas unchanged.
5. Inspect actual column widths and later migrations before implementation. If needed, add a non-destructive widening migration, especially for Supplier Name. Keep a separate, explicit total-length storage safeguard consistent with confirmed capacities; non-space limits alone permit arbitrarily many spaces. Do not shrink columns or rewrite existing records.
6. Trace accepted values through Customer Sales synchronization and Supplier resolution into existing DAT row validation. Document any downstream full-length rejection. This request changes master-data counting only; do not silently expand DAT validation, formatting, calculations, or import rules. Master-data acceptance is not proof that downstream DAT validation accepts the same value.

## Verification and acceptance

- Test both create and update for both entities: exactly 50 non-space name characters passes even with spaces; 51 fails. Repeat 30/31 boundaries for Address and City.
- Cover repeated spaces, pasted non-breaking spaces, tabs/newlines, accented letters, punctuation, and whitespace-only values using identical frontend/backend examples.
- Verify direct server requests reject over-limit values and invalid/duplicate TIN behavior remains unchanged.
- Verify internal word spacing is not removed by the counting helper, and existing Customer/Supplier formatting still applies.
- Verify a Supplier Name longer than 60 total characters but within 50 counted characters can persist safely after any required schema adjustment.
- Browser-check Add/Edit on both pages: full paste retained, counter updates, clear validation, successful save and reload, and no existing value cut off when editing.
- Run focused master-data tests, frontend build, and relevant Sales synchronization / DAT regression tests. Report any existing downstream total-length restriction explicitly.

## Boundaries

No changes to existing records, TIN rules, shared numeric limits, unrelated screens, imports, or DAT generation. Supplier name storage was widened after inspecting the active database.

## Implementation and verification results

- Added `NonWhitespaceLength` (PHP), `masterDataText` (JavaScript), and a reusable reactive `MasterDataCharacterCount` component. Both Add/Edit forms use the new validation and counters; native 50/30 limits no longer cut off input containing spaces.
- Both runtimes exclude the Unicode White_Space set and count other Unicode code points. The helper does not transform submitted text. Existing save-time formatting remains intact.
- Confirmed active MySQL database `c_and_c_database`. Applied only migration `2026_09_25_000000_widen_supplier_name_for_space_excluded_limit`; verified `suppliers.name` is now `varchar(300)`. Its rollback deliberately does not shrink the column to avoid truncating saved names.
- Separate total-character safeguards: Customer Name/Address/City = 300/500/100; Supplier Name/Address/City = 300/100/100. These prevent excessive whitespace from exceeding storage capacity.
- Backend regression run: 47 tests passed, 773 assertions. After adding malformed-input and Customer-to-Sales synchronization coverage, the focused 5-test suite passed with 113 assertions.
- Frontend: 2 Node tests passed; Vite production build passed. Node was supplied by VS Code's bundled runtime with `ELECTRON_RUN_AS_NODE=1` because standalone Node/npm were absent from PATH.
- Headless Edge against a separate temporary SQLite database verified both modules: full 99-character paste (50 letters plus 49 spaces), live counters, over-limit errors, create persistence, correct initial Edit counters, updates, and saved values after reload. Temporary test database, browser profile, and scripts were removed.
- Pint and `git diff --check` passed.
- Downstream boundary confirmed: `BirPurchaseRowValidator` and `BirSalesRowValidator` still use full `mb_strlen` against 50/30/30. A value accepted in master data can therefore still fail DAT validation when spaces put its full length over those limits. DAT validation and generation were intentionally not changed by this implementation.
