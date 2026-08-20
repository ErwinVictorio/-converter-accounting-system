# Importation Manual Entry Module

**Status: Implemented** (2026-08-20)

## Purpose

A new **Importation** module for manual data entry of importation VAT records.

This is **not an upload/import Excel feature**. The Excel screenshot was only the reference format for fields, number rules, and labels.

Delivered flow:

```text
Sidebar -> Importation -> Add/Edit/Delete manual records -> Sync to Purchases VAT/DAT data
```

## Excel Format Rules Followed

All amount and rate fields follow these rules:

```text
Number format only
No comma/accounting format
Round to 2 decimal places
```

Example valid values:

```text
1500000.00
12.00
180000.00
```

Example invalid values:

```text
1,500,000.00
PHP 1500000.00
180000
```

Enforced in two places: the Zod `importationSchema` on the client, and
`round((float) $value, 2)` in `ImportationController::payload()` on the server.

## Manual Entry Fields

| Field | Source Label | Type | Required |
| --- | --- | --- | --- |
| `assessment_date` | assessmentDate | Date | Yes |
| `supplier` | supplier | Text | Yes |
| `importation_date` | importationDate | Date | Yes |
| `country` | country | Text | Yes |
| `dutiable_value` | dutiableVal | Decimal 2 | Yes |
| `charges` | charg | Decimal 2 | Yes |
| `exempt` | exempt | Decimal 2 | Yes |
| `taxable_goods` | taxableGoo | Decimal 2 | Yes |
| `vat_rate` | vatRate | Decimal 2 | Yes |
| `vat_payable` | vatPayable | Decimal 2 | Yes |
| `or_number` | ORNumber | Text/Number | Yes |
| `payment_date` | paymentDate | Date | Yes |

System fields:

| Field | Purpose | Behavior as built |
| --- | --- | --- |
| `tax_month` | Month used for filtering and DAT generation | Normalized to the **first day** of the month |
| `import_entry_no` | Unique import entry/reference number | Unique per `tax_month` |
| `sequence_number` | Display/order number | Auto-assigned (`max + 1` within the tax month); preserved on edit |

## Database

Table `importation_entries` — separate from Excel upload history, as planned.

```text
id
sequence_number
tax_month
import_entry_no
assessment_date
supplier
importation_date
country
dutiable_value
charges
exempt
taxable_goods
vat_rate
vat_payable
or_number
payment_date
vat_input_id
created_at
updated_at
```

`vat_input_id` links the manual entry to its generated/updated `vat_inputs` row
(FK with `nullOnDelete`). A unique index on `['tax_month', 'import_entry_no']`
backs the duplicate rule at the database level.

## Files

Backend:

```text
app/Models/ImportationEntry.php
app/Http/Controllers/ImportationController.php
database/migrations/2026_08_20_000000_create_importation_entries_table.php
config/bir.php                      (added the bir.importation block)
```

Routes (`routes/web.php`):

```php
Route::get('/importation', [ImportationController::class, 'index']);
Route::post('/importation', [ImportationController::class, 'store']);
Route::put('/importation/{importationEntry}', [ImportationController::class, 'update']);
Route::delete('/importation/{importationEntry}', [ImportationController::class, 'destroy']);
```

Frontend:

```text
resources/js/Pages/Importation.jsx
resources/js/Components/app-sidebar.jsx   (sidebar item, Ship icon)
resources/js/lib/FormSchema.js            (importationSchema)
```

UI sections built:

```text
Month filter (dropdown of months that have records, with counts)
Manual add form
Importation record table (paginated)
Add / Save / Edit (dialog) / Delete actions
```

## Validation Rules

Server-side validation in `ImportationController::validateEntry()`:

```text
Dates must be valid dates.
Supplier, country, OR number, tax month, and import entry no. are required.
Amount fields must be numeric and >= 0.
Amount fields are rounded to 2 decimals before saving.
VAT rate must be numeric and >= 0.
Duplicate import_entry_no is rejected for the same tax_month.
Supplier, country, import entry no., and OR number are stored as BIR-safe text.
```

BIR-safe text (same `birText()` helper used across the codebase):

```text
Uppercase and trim
Convert & to AND
Remove commas
Remove unsupported symbols (keeps A-Z 0-9 space . # / - ( ))
Normalize extra spaces
```

## Sync To Purchases DAT

After saving, one matching `vat_inputs` row is created or updated, so the
**existing** purchase DAT generator picks up manual importations with no second
DAT engine. Deleting an entry deletes its synced row. Editing reuses the same
row rather than creating a duplicate.

Mapping as implemented:

| Importation Field | `vat_inputs` Field |
| --- | --- |
| `supplier` | `supplier_name`, `company_name` |
| `tax_month` | `date_uploaded` (set to **end of month**, matching the DAT period filter) |
| `taxable_goods` | `purchase_imported`, `capital_goods`, `taxable_net_of_vat` |
| `exempt` | `exempt` |
| `vat_rate` | `vat_rate` |
| `vat_payable` | `input_vat` |
| `exempt` + `taxable_goods` | `total`, `total_purchases` |
| fixed `true` | `is_imported` |
| fixed `false` | `is_adjusted`, `is_broker` |

`capital_goods` is the field that reaches the DAT detail line (field 12);
`purchase_imported` is internal bookkeeping.

### Deviations from the original plan

Two rows of the original mapping table could not be applied as written:

1. **`creditable_input_vat` does not exist as a column** on `vat_inputs`. The
   RELIEF generator derives it in the header as
   `total input VAT - non-creditable input VAT`, so no per-row field is needed.
2. **Vendor identity had to be added.** The planned field list has no TIN and no
   supplier address, but `BirPurchaseRowValidator` requires a valid 9-digit
   vendor TIN (not `000000000`) and a non-empty `address1`. Without these, a
   synced importation row fails validation and **blocks DAT generation for the
   entire month**.

### Vendor identity: fixed customs defaults

Chosen approach (of the three considered): a configured importation vendor TIN
for all entries, with `country` mapped into the address.

```php
// config/bir.php
'importation' => [
    'tin' => '000-472-103-000', // TODO: confirm the TIN used in your BIR filing.
    'address2' => 'PORT AREA MANILA',
],
```

- `tin_number` <- `config('bir.importation.tin')`, formatted
- `address1` <- the entry's `country`
- `address2` <- `config('bir.importation.address2')`
- The supplier name still comes from each entry.

> **Outstanding action:** the TIN above is a **placeholder**. Replace it with the
> real TIN your BIR filing uses for importations before generating a live DAT
> file. Everything else is production-ready.

## Verification

Both planned commands run clean:

```bash
php artisan migrate                                        # applied; only this migration was pending
php artisan test tests/Unit/ReliefPurchaseDatGeneratorTest.php   # 6/6 pass
```

Also verified:

```bash
php artisan test --testsuite=Unit                # 11/11 pass
php artisan test tests/Feature/ImportationEntryTest.php   # 6/6 pass
npm run build                                    # Importation page compiles
```

Feature tests in `tests/Feature/ImportationEntryTest.php`:

```text
Create importation entry (with BIR-safe text + sequence number)
Update importation entry (reuses the same vat_inputs row)
Reject duplicate import_entry_no per tax_month (and allow it in a different month)
Sync saved entry to vat_inputs and clear BirPurchaseRowValidator
Synced entry appears in the generated purchase DAT file (end to end)
Deleting an entry removes its synced DAT row
```

The `index()` month dropdown uses MySQL-only `DATE_FORMAT` (consistent with the
existing `DatFileController`), so it is verified against the dev MySQL database
rather than the sqlite test database.

### Unrelated fix required to verify

`database/migrations/2026_08_18_080000_add_matching_fields_to_customers_table.php`
ran `ALTER TABLE customers MODIFY ...`, which is MySQL-only syntax. It crashed
`RefreshDatabase` on the sqlite test database, so **every** feature test in the
project errored. It is now wrapped in a `DB::getDriverName() === 'mysql'` check.
The migration already ran on the dev DB (batch 6), so nothing re-runs there.

### Known pre-existing failures (not from this module)

`ProfileTest` and `tests/Feature/Auth/*` (22 tests) are Laravel scaffold tests
hitting `/profile`, `/login`, and `/register` — routes this app does not define,
so they 404. They failed before this work as well. Fixing them means deciding
whether the app should have auth routes at all.

## Implementation Order

1. Migration and model — **done**
2. Controller and routes — **done**
3. React page and sidebar link — **done**
4. Sync logic to `vat_inputs` — **done**
5. Validation and focused tests — **done**
