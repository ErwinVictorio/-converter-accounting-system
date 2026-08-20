# Importation RELIEF DAT Generation Plan

**Status: Implemented** (2026-08-20) — byte-exact against the BIR sample file.

Adds a third DAT type (`Importation`) alongside Purchase and Sales on the
**Generate RELIEF DAT** page.

## Source Of Truth

The layout below is **derived from a real BIR-accepted file**, not inferred:

```text
ImportaionFormat/008791976I072026.DAT     <- reverse-engineered (644 bytes, 1 header + 3 details)
ImportaionFormat/Relief_Importation_Template.xls   <- input template only (sheet R_Importation, 13 columns)
```

The `.xls` is the *input* sheet for BIR's Excel-to-DAT converter. It gave the
column order and the number rules; the `.DAT` gave the actual output layout.

Filename pattern, confirmed by the sample name itself:

```text
{TIN}I{MM}{YYYY}.DAT     ->  008791976I072026.DAT   (TIN 008791976, July 2026)
```

Same shape as the existing `P` and `S` generators (`{TIN}P042026.DAT`, `{TIN}S052026.DAT`).

### Verified sample

```text
H,I,"008791976","FORTRESS STEEL INC.","","","","FORTRESS STEEL INC.","LOT 433 J.P RIZAL NANGKA"," MARIKINA 1808",30303312.20,0.00,0.00,30303312.20,3636397.46,045,07/31/2026,12
D,I,"C2051",07/14/2026,"DAO FORTUNE CO LIMITED",06/10/2026,"CHINA",8094234.19,0,0,8094234.19,971308.10,"000",07/31/2026,008791976,07/31/2026
```

Checks run against it:

```text
CRLF line endings, trailing CRLF present, no bare LF   OK
Header field count = 18                                 OK
Detail field count = 16 (all 3 rows)                    OK
All 5 header totals == sum of the detail amounts        OK
vat_payable == round(taxable_goods * 12%, 2) per row    OK
taxable_goods == dutiable_value + charges - exempt      OK
```

## Structural Fit With The Existing Two Types

The header follows the same 10 identity + N totals + 3 trailer shape already
proven by both existing generators, so only the totals block differs:

| Type | Header | Detail |
| --- | --- | --- |
| Purchase (`P`) | 21 = 10 identity + **8** totals + 3 trailer | 17 |
| Sales (`S`) | 17 = 10 identity + **4** totals + 3 trailer | 15 |
| **Importation (`I`)** | **18 = 10 identity + 5 totals + 3 trailer** | **16** |

Header identity fields 3-10 and trailer fields 16-18 are **byte-identical**
across all three sample files, and already come from `config('bir.companies.008791976')`:

```text
"008791976","FORTRESS STEEL INC.","","","","FORTRESS STEEL INC.","LOT 433 J.P RIZAL NANGKA"," MARIKINA 1808"  ...  045,MM/DD/YYYY,12
```

The **detail line is where Importation genuinely differs.** Purchase and Sales
details open with a 7-field vendor block (TIN, company name, last/first/middle,
address1, address2). Importation replaces that entirely with entry number,
dates, supplier name, and country.

## Header Layout (18 fields)

| # | idx | Field | Source | Format |
| --- | --- | --- | --- | --- |
| 1 | 0 | Record marker | literal | `H` |
| 2 | 1 | Type | literal | `I` |
| 3 | 2 | Company TIN | `bir.companies.*.tin` | quoted, 9 digits |
| 4 | 3 | Name | `.name` | quoted |
| 5 | 4 | Last name | — | quoted empty `""` |
| 6 | 5 | First name | — | quoted empty `""` |
| 7 | 6 | Middle name | — | quoted empty `""` |
| 8 | 7 | Registered name | `.registered_name` | quoted |
| 9 | 8 | Address 1 | `.address1` | quoted |
| 10 | 9 | Address 2 | `.address2` | quoted, leading space preserved |
| 11 | 10 | **Total dutiable value** | sum `dutiable_value` | `headerNumber` |
| 12 | 11 | **Total charges** | sum `charges` | `headerNumber` |
| 13 | 12 | **Total exempt** | sum `exempt` | `headerNumber` |
| 14 | 13 | **Total taxable goods** | sum `taxable_goods` | `headerNumber` |
| 15 | 14 | **Total VAT payable** | sum `vat_payable` | `headerNumber` |
| 16 | 15 | RDO code | `.rdo_code` | bare, `045` |
| 17 | 16 | Period | `endOfMonth` | bare, `MM/DD/YYYY` |
| 18 | 17 | VAT rate | `final_header_field` | bare, `12` |

No `creditable`/`non-creditable` totals — that pair is specific to the Purchase
header, so `generate()` takes no `$nonCreditableInputVat` argument.

## Detail Layout (16 fields)

| # | idx | Field | `importation_entries` column | Format |
| --- | --- | --- | --- | --- |
| 1 | 0 | Record marker | literal | `D` |
| 2 | 1 | Type | literal | `I` |
| 3 | 2 | Import entry no. | `import_entry_no` | **quoted** |
| 4 | 3 | Assessment date | `assessment_date` | **bare**, `MM/DD/YYYY` |
| 5 | 4 | Supplier | `supplier` | **quoted**, BIR-safe text |
| 6 | 5 | Importation date | `importation_date` | **bare**, `MM/DD/YYYY` |
| 7 | 6 | Country | `country` | **quoted**, BIR-safe text |
| 8 | 7 | Dutiable value | `dutiable_value` | `detailNumber` |
| 9 | 8 | Charges | `charges` | `detailNumber` |
| 10 | 9 | Exempt | `exempt` | `detailNumber` |
| 11 | 10 | Taxable goods | `taxable_goods` | `detailNumber` |
| 12 | 11 | VAT payable | `vat_payable` | `detailNumber` |
| 13 | 12 | OR number | `or_number` | **quoted, verbatim** |
| 14 | 13 | Payment date | `payment_date` | **bare**, `MM/DD/YYYY` |
| 15 | 14 | Company TIN | `bir.companies.*.tin` | **bare**, 9 digits |
| 16 | 15 | Period | `endOfMonth` | bare, `MM/DD/YYYY` |

Three things worth calling out:

1. **There is no supplier TIN field.** Foreign suppliers have no Philippine TIN,
   which is exactly why BIR gives importations their own schedule. See
   [The double-count decision](#the-double-count-decision) — this settles it.
2. **`vat_rate` is not in the detail line.** The template has a per-row `vatRate`
   column, but the DAT carries a single rate in header field 18. The per-row
   `vat_rate` column stays useful for computing `vat_payable` and for the header.
3. **`or_number` is written verbatim, not numerically.** The sample carries
   `"000"` on two rows and `"0000"` on a third — different lengths, so BIR
   preserves whatever was typed. It must go through `quote()`, never
   `detailNumber()`, or leading zeros are destroyed.

## Number And Quoting Rules

Identical to the existing generators, so the helpers are copied unchanged:

```text
headerNumber()  always 2 decimals    ->  0 renders as "0.00"
detailNumber()  2 decimals, except   ->  0 renders as "0"
```

Both are confirmed in the sample: header zeros are `0.00`, detail zeros are `0`.

Quoted vs bare, confirmed field by field:

```text
Quoted   header: TIN, all 5 name fields (incl. empty as ""), address1, address2
         detail: import entry no., supplier, country, OR number
Bare     header: 5 totals, RDO code, period, VAT rate
         detail: all 3 dates, 5 amounts, company TIN, period
```

Note the asymmetry the byte-exact test will catch: the **header** TIN is quoted
(`"008791976"`), the **detail** TIN is bare (`008791976`). Same as `P` and `S`.

BIR-safe text (`birText()`) applies to `supplier` and `country`. Both are already
normalized on save by `ImportationController`, so this is defense in depth.

### What remains inferred

Everything above is confirmed except one field. All three sample rows have
`payment_date` = `07/31/2026`, which is also the period end, so detail field 14
cannot be distinguished from a repeated period field **by value alone**.

Field 14 is read as `payment_date` because the template's 13th column is
`paymentDate`, and because `P` and `S` both use a strictly 2-field trailer
(company TIN + period) — a 3-field trailer with the period repeated would break
that pattern. Confidence is high but this is the one item to spot-check on the
first live file, by entering an importation whose payment date is **not** the
last day of the month.

## Data Source

Read `importation_entries` **directly**. Do not route the DAT through `vat_inputs`.

`vat_inputs` has no `import_entry_no`, `assessment_date`, `importation_date`,
`country`, `or_number`, or `payment_date` — six of the twelve detail fields. The
`importation_entries` table maps 1:1 onto the detail line, so there is nothing to
reconstruct.

```php
$records = ImportationEntry::query()
    ->whereBetween('tax_month', [
        $period->copy()->startOfMonth()->toDateString(),
        $period->copy()->endOfMonth()->toDateString(),
    ])
    ->orderBy('sequence_number')
    ->orderBy('id')
    ->get();
```

`tax_month` is already normalized to the first of the month on save, and
`sequence_number` preserves the order shown in the UI.

## Files

New:

```text
app/Services/BIR/ReliefImportationDatGenerator.php
app/Services/BIR/BirImportationRowValidator.php
tests/Unit/ReliefImportationDatGeneratorTest.php
tests/Feature/ImportationDatFileTest.php
```

Changed:

```text
app/Models/ImportationEntry.php              added toBirImportationRow()
app/Http/Controllers/DatFileController.php   importation branch + P DAT exclusion
resources/js/Pages/GenerateDatFile.jsx       third DAT type option
tests/Feature/ImportationEntryTest.php       inverted the Phase 1 P DAT assertion
```

### `ReliefImportationDatGenerator`

Mirrors `ReliefSalesDatGenerator` — same private helpers (`quote`, `birText`,
`headerNumber`, `detailNumber`, `amount`, `digits`), same `RuntimeException`
field-count guards, same `implode("\r\n", $lines) . "\r\n"` assembly.

```php
public function generate(array $company, Collection $transactions, Carbon $period): string
public function filename(array $company, Carbon $period): string   // ...'I'.$period->endOfMonth()->format('mY').'.DAT'
private function generateHeader(...): string   // throws unless 18 fields
private function generateDetail(...): string   // throws unless 16 fields
```

Two helpers the existing generators do not need:

```php
private function datDate(mixed $value): string   // MM/DD/YYYY, bare (not quoted)
private function headerVatRate(Collection $transactions, array $company): string
```

`headerVatRate()` exists because the detail lines carry no rate but the header
carries exactly one. It returns the single distinct `vat_rate` across the month's
rows, formatted bare. **If the rows disagree, throw** rather than silently pick
one — BIR's own template demo data mixes 12.00 and 10.00 rows, so mixed rates are
realistic, and the DAT has nowhere to put a second rate. A hard failure surfaces
the problem; a silent pick would misreport it.

### `BirImportationRowValidator`

Same `validate(array $row, int $excelRow): array` signature as the other two, so
it drops straight into the existing `periodIssues` machinery.

```text
import_entry_no   required, non-empty
supplier          required, non-empty, no comma, no ampersand
country           required, non-empty, no comma, no ampersand
or_number         required, non-empty
assessment_date   required, parseable
importation_date  required, parseable
payment_date      required, parseable
dutiable_value / charges / exempt / taxable_goods / vat_payable
                  numeric (use 0 when there is no amount)
vat_rate          numeric, > 0
```

Plus two consistency checks, both confirmed as invariants against the sample and
the template demo rows:

```text
taxable_goods == dutiable_value + charges - exempt        (tolerance 0.01)
vat_payable   == taxable_goods * vat_rate / 100           (tolerance 0.01)
```

These are the checks that catch a typo before BIR does. Deliberately **no TIN
check and no address check** — the importation detail line has neither field.

#### Deviation: the charges term is checked leniently

As built, the `taxable_goods` check accepts **either** `dutiable + charges - exempt`
**or** `dutiable - exempt`.

Every row in the sample file *and* in BIR's own template demo data has
`charges = 0`, so the two formulas are indistinguishable from any available
evidence — the `- exempt` term is confirmed by two template rows, the `+ charges`
term is not confirmed by anything. Where `charges = 0` the two agree and the check
is exactly as strict as originally planned; where `charges` is non-zero either
convention passes.

Blocking a filing on a formula that no available data confirms is the wrong
trade — a false block stops the user from filing, and the `vat_payable` check
(which *is* confirmed on all three sample rows) already catches the case that
actually misstates tax. Tighten this once a real row with non-zero charges
settles the sign.

The `vat_payable` check remains strict.

### `DatFileController`

Widen the two `in:` rules and add a third branch, matching the existing style:

```php
'record_type' => ['nullable', 'in:purchase,sales,importation'],
```

```php
if ($recordType === 'importation') {
    return $this->downloadImportation($period, $importationGenerator, $importationValidator);
}
```

Add `importationPeriods()` (grouped month list for the dropdown) and
`downloadImportation()`. Both follow `salesPeriods()` / `downloadSales()` almost
line for line, including the `back()->with('error', ...)` empty-month and
row-error paths.

`importationPeriods()` uses MySQL `DATE_FORMAT` like the other two — the same
already-known caveat applies (sqlite tests do not exercise it; verify against the
dev MySQL DB).

### `GenerateDatFile.jsx`

```jsx
<option value="purchase">Purchase</option>
<option value="sales">Sales</option>
<option value="importation">Importation</option>
```

And the heading, currently a two-way ternary, becomes a lookup so it does not
grow a second nested ternary:

```jsx
const DAT_TYPE_LABELS = { purchase: "Purchases", sales: "Sales", importation: "Importations" };
// Generate RELIEF {DAT_TYPE_LABELS[data.record_type]} DAT
```

No other page changes — `record_type` already round-trips through the existing
`router.get` on change, the period dropdown, and the download submit.

## Tests

**`tests/Unit/ReliefImportationDatGeneratorTest.php`** — the important one.
Byte-exact regeneration, modeled directly on
`ReliefPurchaseDatGeneratorTest::test_generated_dat_matches_reference_file`:
parse `ImportaionFormat/008791976I072026.DAT` into company + row fixtures, feed
them back through the generator, and `assertSame($expected, $actual)`. This is
what proves the layout rather than asserting my reading of it.

```text
test_generated_dat_matches_reference_file        byte-exact vs the real sample
test_header_and_detail_field_counts_are_fixed    18 / 16, guards throw otherwise
test_filename_uses_the_importation_marker        008791976I072026.DAT
test_zero_amounts_render_as_bare_zero_in_details header 0.00 vs detail 0
test_or_number_keeps_leading_zeros               "000" survives, not 0
test_mixed_vat_rates_in_one_month_throw
```

**`tests/Feature/ImportationDatFileTest.php`** — end to end through the route:

```text
test_importation_dat_downloads_for_a_month       filename header + 18/16 counts
test_empty_month_returns_an_error
test_invalid_row_blocks_generation               validator wired into the route
```

## The Double-Count Decision

This is the one open decision, and the sample file settles it more firmly than I
could argue before.

Phase 1 syncs every importation entry into a `vat_inputs` row, so importations
**already appear in the Purchase (`P`) DAT** today. Adding an `I` DAT would
report the same amounts in two submitted schedules — the input VAT across the
schedules would exceed the return.

The sample removes the ambiguity: **the importation detail line has no supplier
TIN field at all.** The `P` schedule requires a valid 9-digit vendor TIN;
foreign suppliers do not have one. That is precisely why BIR publishes a separate
importation schedule, and it is the same constraint that forced the placeholder
TIN in Phase 1.

**Recommendation: keep the sync, exclude importation-sourced rows from the `P`
DAT at generation time.** — **shipped as recommended.**

- Keep the sync — the synced `vat_inputs` rows are what make importations visible
  in the existing VAT input listing and internal totals.
- Exclude at generation — one `whereNotIn` in `downloadPurchase()` and
  `purchasePeriods()`:

  ```php
  ->whereNotIn('id', ImportationEntry::whereNotNull('vat_input_id')->pluck('vat_input_id'))
  ```

Discriminate on `importation_entries.vat_input_id`, **not** on
`vat_inputs.is_imported`. `is_imported` is a user-facing flag that
`VatInputController` and `VatInputImport` both set on ordinary Excel-uploaded
rows, so filtering on it would silently drop legitimate purchases.

Side effect worth stating plainly: this makes the placeholder TIN in
`config('bir.importation.tin')` irrelevant to DAT output, since the excluded rows
are the only ones that used it. The TODO in `config/bir.php` can be closed once
this lands — the `I` DAT never needs a vendor TIN.

**This also inverts one existing test.**
`ImportationEntryTest::test_synced_entry_appears_in_the_generated_purchase_dat_file`
currently asserts the opposite of the new behavior. It must be rewritten to
assert the entry does **not** appear in the `P` DAT and **does** appear in the
`I` DAT. That test was written in Phase 1 to prove the sync reached the DAT
engine; the new pair of tests proves the correct routing instead.

Rewritten as
`test_synced_entry_is_routed_to_the_importation_dat_not_the_purchase_dat`.

**Visible effect on the dev database.** `purchasePeriods()` previously offered
`August 2026 (1 rows)`; that single row was the synced importation, so August 2026
now appears under **Importation** instead and the Purchase dropdown falls back to
`May 2026 (48 rows)`. Nothing was deleted — the `vat_inputs` row (id 381) is still
there and still visible in the VAT input listing.

If you would rather not change `P` DAT behavior yet, revert the two
`whereNotIn('id', $this->importationVatInputIds())` calls in `DatFileController`
— but then do not submit both files for the same month.

## Verification

```bash
php artisan test tests/Unit/ReliefImportationDatGeneratorTest.php   # 9/9 pass
php artisan test tests/Feature/ImportationDatFileTest.php           # 5/5 pass
php artisan test tests/Feature/ImportationEntryTest.php             # 6/6 pass
php artisan test --testsuite=Unit                                   # 20/20 pass
npm run build                                                       # clean
```

The headline result: **`test_generated_dat_matches_reference_file` passes**, so the
generator reproduces `ImportaionFormat/008791976I072026.DAT` byte for byte — the
same standard the Purchase generator is held to.

`importationPeriods()` uses MySQL-only `DATE_FORMAT`, which the sqlite test suite
never exercises, so it was verified against the dev MySQL database directly:

```text
importationPeriods()      [{"value":"2026-08","label":"August 2026","records_count":1}]
excluded vat_input ids    [381]
purchasePeriods()         [{"value":"2026-05","label":"May 2026","records_count":48}]
```

The one existing importation entry in the dev DB (`WRF` / `DFDFD`) is scratch test
data with inconsistent amounts, and the validator correctly refuses it:

```text
Entry WRF DFDFD: Row 2: taxable_goods (5.00) should equal dutiable_value + charges - exempt (1500.00 or 1445.00).
Entry WRF DFDFD: Row 2: vat_payable (22.00) should equal taxable_goods x 12.00% (0.60).
```

Delete or fix that row before generating an August 2026 importation DAT.

### Known pre-existing failures (not from this work)

`php artisan test` reports 33 passed / 22 failed. All 22 are the Laravel scaffold
tests in `tests/Feature/Auth/*` and `ProfileTest`, hitting `/login`, `/register`,
and `/profile` — routes this app does not define. They failed before this work too.

## Still Open

1. **Detail field 14 (`payment_date`) is inferred, not confirmed.** All three
   sample rows have it equal to the period end, so it cannot be distinguished from
   a repeated period field by value. Spot-check it on the first live file by
   entering an importation whose payment date is *not* the last day of the month.
2. **The `+ charges` sign** in the `taxable_goods` invariant — see the deviation
   note above.
3. **`config('bir.importation.tin')` is now dead weight for DAT output.** The `I`
   DAT has no vendor TIN field, and the rows that used the placeholder are the ones
   now excluded from the `P` DAT. The TODO in `config/bir.php` no longer blocks
   anything, but the value is still written into `vat_inputs.tin_number` on sync,
   so it remains wrong data in the listing.

## Related

- [IMPORTATION_MANUAL_ENTRY_PLAN.md](IMPORTATION_MANUAL_ENTRY_PLAN.md) — the
  manual entry module that produces the rows this DAT reads
- [SALES_RELIEF_DAT_Documentation.md](SALES_RELIEF_DAT_Documentation.md) — the
  same reverse-engineering exercise for the `S` format
