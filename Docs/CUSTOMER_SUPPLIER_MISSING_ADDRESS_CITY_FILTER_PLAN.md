# All Master Data Missing Information Filter Plan

## Status

Implemented on September 14, 2026.

Implementation added allow-listed server-side completeness filters and screenshot-inspired labeled filter controls to Customers, Suppliers, Brokers, and Withholding Companies. No database schema, stored data, import behavior, or DAT-generation behavior was changed.

Verification completed:

- `tests/Feature/MasterDataMissingAddressCityFilterTest.php`: 6 passed, 210 assertions.
- Master-data TIN, alphabetical ordering, Withholding Company, and View Info regressions: 36 passed, 394 assertions.
- Pending Purchase Supplier queue regression: 5 passed, 98 assertions, using an isolated Laravel test-storage directory because the default generated test folder had a local Windows ACL issue.
- Total focused coverage: 47 passed, 702 assertions.
- PHP syntax checks passed for both changed controllers and the new test.
- Laravel Pint passed for both changed controllers and the new test.
- `git diff --check` passed.
- Frontend build and browser visual verification remain pending because Node/npm is unavailable in the current shell.

## Goal

Add server-side data-completeness filtering to every Master Data table so users can quickly isolate historical rows that need to be fixed through the existing forms.

The implemented expansion also covers all other Master Data pages:

- Brokers can be searched by TIN/name and filtered to missing-TIN or complete records.
- Withholding Companies can be searched using the existing company search and filtered by missing Address 1/Address 2 data or complete address records.

## Current Implementation Findings

- Customer listing is handled by `CustomerController::index()` and `resources/js/Pages/ManageCustomer.jsx`.
- Supplier listing is handled by `SupplierController::index()` and `resources/js/Pages/ManageSupplier.jsx`.
- Broker listing is handled by `ManageBrokerController::index()` and `resources/js/Pages/ManageBrokers.jsx`.
- Withholding Company listing is handled by `WithholdingCompanyController::index()` and `resources/js/Pages/WithholdingCompanies.jsx`.
- Both controllers currently accept `tin` and `name`, order records alphabetically by `name`, paginate by 10, and call `withQueryString()`.
- Both pages keep the filters in React state, submit them through Inertia `router.get()`, and provide Filter and Clear actions.
- Both tables already show `addr` and `city`, and their existing Edit dialogs can be used to repair a selected row.
- Customer and Supplier create/update validation already requires Address and City. The incomplete rows being targeted are therefore legacy/imported records containing `NULL`, an empty string, or whitespace-only text.
- Supplier Management also has an existing pending Purchase fix-queue context. Its `pending_upload` and `queue_item` parameters must continue to work while the new list filter is used or cleared.

## Proposed Filter Contract

Add one dropdown labeled **Missing Information** beside the existing search inputs. Customers, Suppliers, and Withholding Companies use the Address/City contract below.

| UI option | Query value | Records shown |
| --- | --- | --- |
| All records | `all` | No Address/City completeness restriction. |
| Missing address or city | `missing_any` | Address is missing, City is missing, or both are missing. |
| Missing address | `missing_address` | Address is missing; City may be present or missing. |
| Missing city | `missing_city` | City is missing; Address may be present or missing. |
| Missing both | `missing_both` | Both Address and City are missing. |
| Complete records | `complete` | Both Address and City have usable values. |

Use the query-string key `address_status`. The default is `all`.

For this feature, a field is **missing** when its database value is:

- `NULL`;
- an empty string; or
- whitespace-only text after `TRIM`.

A literal stored value such as `N/A` is treated as actual text and is not automatically classified as missing. The `N/A` currently rendered by the table for a falsy value is only display fallback and should not be written to the database by this feature.

## Backend Plan

### 1. Extend Customer listing filters

Update `app/Http/Controllers/CustomerController.php`:

1. Read `address_status` together with the existing `tin` and `name` filters.
2. Normalize/allow-list the value to the six supported values above; an absent or invalid value falls back to `all`.
3. Apply the selected condition to the existing `Customer::query()` before `orderBy('name')` and `paginate(10)`.
4. Use database-portable empty-value checks based on `TRIM(COALESCE(column, '')) = ''` so MySQL production data and SQLite tests behave the same way.
5. Return the normalized `address_status` in the existing Inertia `filters` prop.
6. Preserve the existing TIN/Name conditions, selected columns, alphabetical ordering, pagination size, and `withQueryString()` behavior.

The filters must combine with `AND`. Example: `name=ABC&address_status=missing_city` returns only customers matching `ABC` whose City is missing.

### 2. Extend Supplier listing filters

Apply the same filter contract in `app/Http/Controllers/SupplierController.php`:

1. Read, normalize, and return `address_status`.
2. Apply the same Address/City conditions before alphabetical ordering and pagination.
3. Preserve all existing `supplierFixContext` authorization and queue-building behavior.
4. Keep `pending_upload` and `queue_item` in the URL and pagination links when Supplier Management is opened in Purchase fix-queue mode.

### 3. Keep the query scope narrow

For Brokers, add TIN and Broker Name search plus an allow-listed `information_status` filter with `all`, `missing_tin`, and `complete` values. Preserve the existing plain collection response and alphabetical order.

For Withholding Companies, combine the existing registered name/trade name/TIN search with the same Address/City status contract, mapping Address to `address1` and City to `address2`. Preserve pagination, filed-row locks, activation, and deletion rules.

No migration or new route is required. The feature queries only existing master-data fields (`addr`, `city`, `tin_number`, `address1`, and `address2`) and does not update records automatically.

Keep the filter implementation close to each listing query or use a small shared, well-named query helper only if it removes exact duplication without changing either model's other behavior.

## Frontend Plan

### 1. Customer table

Update `resources/js/Pages/ManageCustomer.jsx`:

1. Import and reuse the existing shadcn Select components from `resources/js/Components/ui/select.jsx`.
2. Add `address_status` to `filterValues`, initialized from the Inertia `filters` prop with `all` as fallback.
3. Keep local state synchronized when the server returns new filter props.
4. Render the **Address/City Status** dropdown beside the current TIN and Customer Name inputs.
5. Include `address_status` in the existing `/customers` Filter request.
6. Reset it to `all` in Clear and reload `/customers` without filter query parameters.
7. Adjust the responsive filter grid so inputs, dropdown, Filter, and Clear remain usable on mobile and desktop.

### 2. Supplier table

Make the matching changes in `resources/js/Pages/ManageSupplier.jsx`:

1. Add the same dropdown and state values.
2. Include `address_status` in the `/suppliers` Filter request.
3. Reset it to `all` when Clear is clicked.
4. Continue spreading `fixQueueQuery` into both Filter and Clear requests so the pending Purchase workflow is not lost.

### 3. User feedback

Apply the same labeled, responsive filter-bar presentation to Brokers and Withholding Companies. Broker options reflect its actual fields: **All records**, **Missing TIN**, and **Complete records**.

- Keep the existing table columns and row Actions unchanged.
- Preserve alphabetical name ordering inside every filtered result.
- Keep the existing pagination component; its links will retain the new filter because the controllers already use `withQueryString()`.
- When a filtered result is empty, the current `No customers found.` or `No suppliers found.` message is sufficient. It may be changed to `No records match the selected filters.` only if the same wording is applied consistently to both pages.
- Do not add automatic clearing after an Edit save. Keeping the active missing-data filter allows a successfully repaired row to disappear from the list, making the remaining repair queue obvious.

## Files Expected to Change During Implementation

- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `resources/js/Pages/ManageCustomer.jsx`
- `resources/js/Pages/ManageSupplier.jsx`
- `app/Http/Controllers/ManageBrokerController.php`
- `resources/js/Pages/ManageBrokers.jsx`
- `app/Http/Controllers/WithholdingCompanyController.php`
- `resources/js/Pages/WithholdingCompanies.jsx`
- `tests/Feature/MasterDataMissingAddressCityFilterTest.php` (new focused feature test)

No model, route, migration, importer, transaction record, validator, or DAT generator should need modification.

## Test Plan

Create focused authenticated feature tests with complete, missing-address, missing-city, and missing-both fixtures for both Customer and Supplier.

### Backend behavior

- Default/no `address_status` returns all rows.
- `missing_any` returns rows missing Address, City, or both and excludes complete rows.
- `missing_address` returns every row with a blank/whitespace Address.
- `missing_city` returns every row with a blank/whitespace City.
- `missing_both` returns only rows where both fields are missing.
- Whitespace-only values are treated as missing.
- A literal `N/A` is not treated as missing.
- Invalid `address_status` input safely falls back to `all` and the normalized Inertia filter prop also reports `all`.
- TIN and Name filters combine correctly with the selected Address/City status.
- Results remain ordered alphabetically by `name`.
- Pagination URLs preserve `address_status`, TIN, and Name.

### Page and regression behavior

- Customer and Supplier responses expose the selected `filters.address_status` value.
- Supplier filter and Clear actions retain an authorized pending-upload fix context.
- Existing create/update required-field and TIN uniqueness tests continue to pass.
- Existing master-data alphabetical ordering remains unchanged.

## Acceptance Criteria

1. Customer Management and Supplier Management each display an **Address/City Status** dropdown.
2. Selecting **Missing address or city** shows all records needing either field repaired, including rows missing both.
3. Users can narrow the list specifically to missing Address, missing City, or missing both.
4. The new selection works together with the existing TIN and Name filters.
5. The active filters survive movement between pagination pages.
6. Clear restores the full alphabetical list; on Supplier fix-queue pages it does not discard the authorized queue context.
7. Editing and completing a row causes it to leave the applicable missing-data result after the page refreshes.
8. No records are automatically edited, deleted, or normalized by filtering.
9. No import, upload replacement, Customer-to-Sales synchronization, Purchase fix queue, validation, calculation, or DAT format/output behavior changes.
10. Broker Management supports TIN/name search and missing-TIN/complete filtering without changing its collection response shape.
11. Withholding Companies supports Address 1/Address 2 completeness filtering alongside its existing company search and pagination.

## Recommended Implementation Order

1. Add focused Customer and Supplier filter tests that demonstrate the expected states and combined-filter behavior.
2. Implement the allow-listed server-side conditions in both controllers.
3. Add the shared dropdown contract to both React pages and preserve Supplier fix-queue parameters.
4. Run backend tests and the frontend build.
5. Manually verify the two listing pages at desktop and mobile widths, including edit-and-disappear behavior while a missing-data filter is active.

## Verification Commands

```powershell
php artisan test tests\Feature\MasterDataMissingAddressCityFilterTest.php
php artisan test tests\Feature\MasterDataTinUniquenessTest.php tests\Feature\AlphabeticalRecordOrderingTest.php
php -l app\Http\Controllers\CustomerController.php
php -l app\Http\Controllers\SupplierController.php
npm run build
git diff --check
```
