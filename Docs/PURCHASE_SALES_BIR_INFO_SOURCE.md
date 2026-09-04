# Purchase and Sales BIR Info Source

This note documents where the app currently gets the TIN, name, and address used for RELIEF Purchase and Sales DAT files.

## Summary

| DAT Type | Master Data Source | Stored Record Table | Detail TIN Field | Detail Address Fields |
| --- | --- | --- | --- | --- |
| Purchase | `suppliers` | `vat_inputs` | `tin_number` / `vendor_tin` | `address1`, `address2` |
| Sales | `customers` | `sales_vatsinputs` | `customer_tin` | `address1`, `address2` |

## Purchase

Purchase upload uses supplier master data when the Excel row can be matched to a supplier.

Current code path:

1. `app/Http/Controllers/VatInputController.php`
   - For `record_type = purchase`, imports with `VatInputImport`.

2. `app/Imports/VatInputImport.php`
   - Reads the Excel TIN from `vendor_tin`, `tin`, or `tin_number`.
   - Reads the Excel supplier name from `supplier_name`, `company_name`, or `companyname`.
   - Calls `findSupplier()` before saving the row.
   - `findSupplier()` searches `suppliers` by:
     - full 12-digit TIN,
     - first 9 digits of TIN,
     - exact supplier name.

If a supplier is found, these fields are taken from `suppliers`:

| Saved `vat_inputs` Field | Source |
| --- | --- |
| `tin_number` | `suppliers.tin` |
| `supplier_name` / `company_name` | `suppliers.name` |
| `address1` | `suppliers.addr` |
| `address2` | `suppliers.city` |

If no supplier is found, the import falls back to the uploaded Excel row:

| Saved `vat_inputs` Field | Source |
| --- | --- |
| `tin_number` | Excel `vendor_tin`, `tin`, or `tin_number` |
| `supplier_name` / `company_name` | Excel `supplier_name`, `company_name`, or `companyname` |
| `address1` | Excel `address1` or first part of `address_1` |
| `address2` | Excel `address2`, `address_2`, or city split from `address1` |

Important behavior:

- Purchase DAT generation does not look up `suppliers` again during download.
- The Generate DAT screen validates the already saved `vat_inputs` rows.
- If a supplier is added or edited after the Purchase upload, old `vat_inputs` rows are not automatically updated by `SupplierController`.
- To fix existing Purchase DAT validation errors, the saved Purchase row needs valid BIR info in `vat_inputs`, usually through re-upload/import with matching supplier data or through the record BIR info edit flow.

Download/validation path:

1. `app/Http/Controllers/DatFileController.php`
   - `purchasePeriods()` validates each saved `VatInput` row with `BirPurchaseRowValidator`.
   - `downloadPurchase()` validates the same saved rows before creating the DAT.

2. `app/Models/VatInput.php`
   - `toBirPurchaseRow()` maps saved `vat_inputs` fields to the Purchase DAT row shape.

3. `app/Services/BIR/ReliefPurchaseDatGenerator.php`
   - Writes the DAT detail line from the mapped row.

4. `app/Services/BIR/BirPurchaseRowValidator.php`
   - Requires a valid first 9 TIN digits.
   - Requires `address1`.
   - Blocks comma and ampersand in BIR text fields.

## Sales

Sales upload uses customer master data when the uploaded customer name can be matched to a customer.

Current code path:

1. `app/Http/Controllers/VatInputController.php`
   - For `record_type = sales`, imports with `SalesVatInputImport`.

2. `app/Imports/SalesVatInputImport.php`
   - Reads the customer name from the Sales upload format.
   - Calls `findCustomer()` before saving the row.
   - `findCustomer()` normalizes the uploaded customer name with `Customer::normalizeName()`.
   - It searches `customers.name_key` and uses the latest matching customer.

If a customer is found, these fields are taken from `customers`:

| Saved `sales_vatsinputs` Field | Source |
| --- | --- |
| `customer_tin` | `customers.tin` |
| `customer_name` / `company_name` | `customers.name` |
| `address1` | `customers.addr` |
| `address2` | `customers.city` |

If no customer is found:

- For Sales Summary upload rows, the importer can reuse previous saved BIR info from the latest matching `sales_vatsinputs` row with a non-null `customer_tin`.
- For BIR Sales upload rows, the importer falls back to the uploaded Excel TIN and address fields.

Important behavior:

- Customer create/update has an automatic sync path.
- `CustomerController::syncSalesRows()` updates matching `sales_vatsinputs` rows with the customer TIN, company name, address, and city.
- This means updating customer master data can repair matching existing Sales rows.

Download/validation path:

1. `app/Http/Controllers/DatFileController.php`
   - `salesPeriods()` validates consolidated saved Sales rows.
   - `downloadSales()` validates the same consolidated rows before creating the DAT.

2. `app/Services/BIR/SalesSiCmConsolidator.php`
   - Groups Sales SI/CM rows by customer identity before DAT generation.

3. `app/Models/SalesVatInput.php`
   - `toBirSalesRow()` maps saved `sales_vatsinputs` fields to the Sales DAT row shape.

4. `app/Services/BIR/ReliefSalesDatGenerator.php`
   - Writes the DAT detail line from the mapped row.

5. `app/Services/BIR/BirSalesRowValidator.php`
   - Requires a valid first 9 TIN digits.
   - Requires `address1`.
   - Blocks comma and ampersand in BIR text fields.

## Key Difference

Purchase and Sales both use the correct master data source:

- Purchase uses `suppliers`.
- Sales uses `customers`.

The difference is how existing saved rows are updated:

| Type | Uses master data during import? | Master data update syncs old rows? |
| --- | --- | --- |
| Purchase | Yes, from `suppliers` | No current automatic supplier-to-`vat_inputs` sync |
| Sales | Yes, from `customers` | Yes, `CustomerController::syncSalesRows()` updates matching Sales rows |

Because of this, a Purchase DAT validation error can still appear even when the supplier master record is already correct, if the existing `vat_inputs` row was saved before the supplier info was available or matched.
