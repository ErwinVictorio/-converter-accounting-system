# Dashboard Analytics Guide

A plain-language guide to every figure on the Dashboard — what it shows, where the numbers
come from, and how the Tax Month affects them.

This describes the system exactly as it works today. Where a figure is calculated in a way
that may surprise you, it is flagged rather than glossed over.

---

## 1. Start here: how the Tax Month works

The Dashboard reports on **one month at a time**. The Tax Month selector at the top right
controls everything on the page except the two charts (see §5).

Three things are worth knowing before you read any figure:

**The Dashboard opens on last month, not the current month.**
When you first land on the page, it selects the month that has just closed. This matches
how the Importation entry screen behaves. If today is 15 September, the Dashboard opens on
**August**.

**A record's month is the Reporting Month chosen at upload — not the invoice dates inside
the file.**
When you upload a Sales, Purchases or Withholding Tax file, you pick a Reporting Month on
the upload screen. Every row in that file is filed under that month. If you upload a file
of July invoices but leave the Reporting Month on August, the whole file appears under
**August** on the Dashboard.

> This is the single most common reason a figure looks wrong. Check the Reporting Month
> used at upload before assuming the total is miscalculated.

**Importation entries use the Tax Month typed on the entry form**, not an upload date.

**What the selector offers:** the last 24 months, plus any month that actually holds
records in any of the four modules — so older filings stay reachable even after two years.

---

## 2. The four summary cards

### Total Sales

* **What it shows:** Total sales recorded for the selected tax month.
* **Source:** Sales records uploaded on the Records screen (Sales file).
* **Calculation:** Adds the **Net Amount** column of every sales row filed under the
  selected month. The system reads this column as it appears in your file — it does not
  recompute it from gross, discounts or charges.
* **Tax Month:** Changing the Tax Month refreshes the card using only the sales rows filed
  under that month.
* **Also on the card:** the number of sales records in the month, and a percentage badge
  comparing the amount with the month immediately before.

### Total Purchases

* **What it shows:** Total purchases recorded for the selected tax month.
* **Source:** Purchase records uploaded on the Records screen (Purchases file).
* **Calculation:** Adds the **Total Purchases** amount of every purchase row filed under
  the selected month — **excluding the copies created by Importation entries** (see the
  note below).
* **Tax Month:** Changing the Tax Month refreshes the card using only the purchase rows
  filed under that month.

> **Why importation copies are excluded.** Every manual Importation entry is also written
> into the purchase list, so the Purchase DAT file can include it without a second
> generator. If the Dashboard counted those copies, every importation would be counted
> twice — once as a purchase and once as an importation — and its VAT twice over. The
> Purchase DAT download applies exactly the same exclusion, so the card and the file
> always agree.

### Total Importations

* **What it shows:** Total landed cost of importations for the selected tax month.
* **Source:** Importation entries added manually on the Importation screen.
* **Calculation:** Adds the **Total Landed Cost** of every importation entry whose Tax
  Month is the selected month.
* **Tax Month:** Changing the Tax Month refreshes the card using only the entries filed
  under that month.

> Note that this card uses **landed cost**, not taxable goods. On each entry the system
> derives *Charges* as landed cost less dutiable value, and *Taxable Goods* as landed cost
> less exempt. The VAT amount itself is **typed in** on the form, not calculated from the
> taxable goods — so it always matches the amount actually paid and receipted.

### Total VAT

* **What it shows:** The company's **net VAT position** for the selected tax month.
* **Source:** The VAT amounts on Sales, Purchases and Importation records.
* **Calculation:**

  ```
  Output VAT (Sales)
    less Input VAT (Purchases)
    less Importation VAT
  = Total VAT
  ```

  A **positive** figure is VAT **payable**; a **negative** figure is a **creditable**
  balance carried forward. Both are normal, so the sign is shown as-is rather than hidden.
  The card label reads *Payable* or *Creditable* underneath to make this explicit.
* **Tax Month:** All three components are taken from the selected month only.
* **Expanded Withholding Tax is deliberately not part of this figure.** Withholding tax is
  remitted on 1604E and is not creditable against output VAT, so folding it in would
  understate what the company owes.

> **For Accounting to confirm:** this card reports VAT **net of input and importation VAT**.
> If your intended reading of "Total VAT" is output VAT alone, or output plus input, say so
> — the calculation is a single line and easy to change.

---

## 3. Expanded Withholding Tax panel (1604E)

This panel sits on its own below the VAT cards, because it is filed on 1604E rather than on
a VAT return. All three figures come from the Expanded Withholding Tax file uploaded on the
Records screen.

### Tax Withheld

* **What it shows:** Total tax withheld for the selected month — the amount remittable.
* **Source:** The withholding tax file's own `tax_amount` column.
* **Calculation:** Adds the tax withheld on every withholding line filed under the selected
  month. The uploaded amount is stored and added as it stands; no rate is applied to it.
* **Tax Month:** Only lines filed under the selected month are included.

### Income Payments

* **What it shows:** The gross amount paid to payees that the tax was withheld on.
* **Source:** The same withholding tax file, from its own `income_payment` column.
* **Calculation:** Adds the income payment on every withholding line filed under the
  selected month.
* **Tax Month:** Only lines filed under the selected month are included.

> **Both amounts are read, not computed.** The BIR 1601EQ Schedule 1 workbook carries both
> `income_payment` and `tax_amount` already computed, and the import stores each exactly as
> the file states it. Neither figure is derived from the other, so what the dashboard totals
> is what the workbook filed.
>
> This changed when the BIR-format upload landed. The earlier workbook had no income payment
> column, so the system back-computed one as *Tax Withheld ÷ Rate* and inherited whatever
> rounding the withheld amount carried. Nothing does that any more. A month imported before
> the change still holds its back-computed figures until it is re-uploaded in the BIR format.

### Withholding Lines

* **What it shows:** How many 1604E detail lines the month will file.
* **Source:** The same withholding tax file.
* **Calculation:** Counts the **consolidated** lines — worksheet rows sharing reporting
  month, TIN, ATC and rate are one line, with their income payment and tax amount added
  together.
* **Tax Month:** Only lines filed under the selected month are counted.

> **This is not a count of worksheet rows.** It is deliberately the number of detail lines
> the DAT will contain, so the card cannot disagree with the file it produces. Two rows for
> the same payee under the same ATC and rate count as **one**; one payee withheld at two
> different rates counts as **two**, because 1604E wants one detail row per payee per ATC.

> **Re-uploading replaces the month.** When a withholding file is uploaded for a month that
> already has data, the existing lines for that month are removed first and the file is read
> in fresh. This prevents the tax withheld from being doubled. Any manual correction made to
> that month is lost, so re-upload deliberately.

---

## 4. Monthly Summary strip

Eight figures for the selected month, in two rows.

**Top row — amounts**

| Figure | What it adds up |
| --- | --- |
| Total Sales | Net Amount on sales records |
| Total Purchases | Total Purchases on purchase records (importation copies excluded) |
| Importation (Landed Cost) | Total Landed Cost on importation entries |
| WTAX Income Payments | Income payments on withholding lines, as uploaded |

**Bottom row — the VAT breakdown**

| Figure | What it shows |
| --- | --- |
| Output VAT | VAT on sales for the month |
| Input VAT | VAT on purchases for the month (importation copies excluded) |
| Importation VAT | VAT paid on importations for the month |
| Combined VAT | Output VAT less Input VAT less Importation VAT — the same figure as the **Total VAT** card |

**Tax Month:** every figure in this strip is for the selected month only.

The bottom row exists so the Total VAT card can be checked component by component: the
three parts and the result are shown side by side, and they are computed once and shared, so
the card and the strip can never disagree.

---

## 5. The two charts

**Both charts show the full calendar year — January to December — of the selected month's
year. They are not limited to the selected month.**

Changing the Tax Month within the same year does **not** change either chart. Selecting a
month in a different year switches both charts to that year. The year in use is printed
beside each chart title.

Months with no records are drawn as zero, so the axis is always a full twelve months.
Each chart carries the same four series, in the same colours as the cards:
**Sales**, **Purchases**, **Importation** and **Expanded WTax**.

### Monthly Transactions

* **What it shows:** **How many records** each module holds in each month of the year. This
  is a **count of records — not peso amounts.** The vertical axis is a whole number.
* **Source:** All four modules.
* **Calculation:** For each month, counts the records filed under that month:

  | Series | What one bar counts |
  | --- | --- |
  | Sales | Sales records |
  | Purchases | Purchase records, importation copies excluded |
  | Importation | Importation entries |
  | Expanded WTax | 1604E detail lines (one per payee, per ATC, per rate — consolidated) |

* **Tax Month:** Only the **year** matters. The selected month does not filter this chart.

> **Read the counts as "records as stored", not as invoices.** Two behaviours affect them:
> purchases are **merged per supplier per reporting month** — if the same supplier appears
> on five rows of one upload, that is **one** purchase record, with the amounts added
> together; and withholding rows are consolidated into filing lines, as described in §3.
> Sales records and importation entries are one-for-one.

### Monthly Amount Trend

* **What it shows:** The **peso total** for each module in each month of the year, as four
  trend lines.
* **Source:** All four modules.
* **Calculation:** For each month, adds the same amounts the cards use:

  | Series | What the line plots |
  | --- | --- |
  | Sales | Net Amount on sales records |
  | Purchases | Total Purchases on purchase records, importation copies excluded |
  | Importation | Total Landed Cost on importation entries |
  | Expanded WTax | Income payments on withholding lines — **not** the tax withheld |

* **Tax Month:** Only the **year** matters, exactly as above.

> The vertical axis abbreviates to **₱1.5M** and **₱250K** so the labels fit. Hover any
> month to read all four exact amounts in full pesos.

---

## 6. Recent Importation Entries

* **What it shows:** The five most recently added importation entries for the selected tax
  month, with entry number, seller, country, taxable goods, VAT and when it was added.
* **Source:** Importation entries added on the Importation screen.
* **Calculation:** No totals — these are individual entries, newest first by date added.
* **Tax Month:** Only entries whose Tax Month is the selected month appear. When there are
  none, the table says so; *View All* opens the Importation screen on the same month.

---

## 7. Things worth knowing

**Comparison badges.** The small percentage badge on a card compares the month you selected
with the month immediately before it. It is hidden when the previous month has nothing to
compare against. Green is an increase and red a decrease — except on **Total VAT** and **Tax
Withheld**, where the badge is grey, because a higher figure there is not automatically good
news.

**"No BIR data yet."** This banner appears only when the whole system is empty. A month that
simply has no records shows zero figures instead — so you can tell "nothing uploaded yet"
apart from "nothing happened that month".

**Purchase merging.** Purchase rows for the same supplier in the same reporting month are
combined into one record, with the amounts added together. This keeps the record count lower
than the number of spreadsheet rows, and it applies across uploads for the same month, not
only within one file.

**One detail on purchase totals, reported as implemented.** When a purchase row is first
created, its Total Purchases is taken from the file's own total column if present.
When a later row for the same supplier and month merges into it, the total is recalculated
as *imported + local + services + others*. The two paths can therefore produce slightly
different totals for the same set of rows depending on whether merging occurred. This is
existing behaviour and has not been changed — flag it if the merged total ever looks off.

**Where amounts are read versus calculated.** The Dashboard adds up what your files say; it
does not re-derive VAT. Sales output VAT and purchase input VAT are read from the file when
those columns are present (purchase input VAT falls back to *taxable net of VAT × VAT rate*,
default 12%, when the column is blank). Importation VAT is typed on the entry form.
Withholding income payments and tax amounts are both read from the file's own columns, as
described in §3 — nothing on this screen back-computes either of them.

**One figure is a count of filing lines, not of rows.** The Expanded WTax record count on the
card and in the Monthly Transactions chart counts consolidated 1604E detail lines, so it
matches the DAT the month will produce. Every other count on the Dashboard is a count of
stored records. See §3, *Withholding Lines*.

**The Dashboard is read-only.** Opening it, or changing the Tax Month, never changes a
record. It does not generate DAT files either — that is the Generate DAT File screen.

---

## 8. Quick Reference

**Cards — selected month only**

| Card | Shows | Adds up |
| --- | --- | --- |
| **Total Sales** | Sales for the month | Net Amount on sales records |
| **Total Purchases** | Purchases for the month | Total Purchases on purchase records, importation copies excluded |
| **Total Importations** | Importations for the month | Total Landed Cost on importation entries |
| **Total VAT** | Net VAT position | Output VAT − Input VAT − Importation VAT (withholding tax excluded) |

**Expanded Withholding Tax panel — selected month only**

| Figure | Shows | Adds up |
| --- | --- | --- |
| **Tax Withheld** | Amount remittable on 1604E | Tax withheld on withholding lines, as uploaded |
| **Income Payments** | Gross paid to payees | Income payment on withholding lines, as uploaded |
| **Withholding Lines** | How many 1604E detail lines the month will file | Count of consolidated lines — one per payee, per ATC, per rate |

**Monthly Summary — selected month only**

| Row | Figures |
| --- | --- |
| Amounts | Total Sales · Total Purchases · Importation (Landed Cost) · WTAX Income Payments |
| VAT breakdown | Output VAT · Input VAT · Importation VAT · Combined VAT (same as Total VAT card) |

**Charts — full calendar year, not the selected month**

| Chart | Measures | Series |
| --- | --- | --- |
| **Monthly Transactions** | **Record counts** per month | Sales · Purchases · Importation · Expanded WTax |
| **Monthly Amount Trend** | **Peso totals** per month | Sales (net amount) · Purchases (total purchases) · Importation (landed cost) · Expanded WTax (income payments) |

**Table**

| Section | Shows |
| --- | --- |
| **Recent Importation Entries** | Five newest importation entries for the selected month |

**Three rules to remember**

1. The **Reporting Month picked at upload** decides which month a record lands in — not the
   dates inside the file.
2. The **cards, panel and summary follow the Tax Month**; the **two charts follow only its
   year**.
3. **Withholding tax never enters the VAT figures** — it is a 1604E amount, reported on its
   own panel and its own chart series.
