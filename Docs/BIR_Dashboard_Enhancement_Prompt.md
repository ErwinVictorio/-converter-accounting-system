# Dashboard Enhancement — BIR Sales, Purchases & Importation

## Goal

Update the existing **Dashboard** so it is no longer focused only on **Importation**.

The system handles three main BIR data sources/modules:

- **Sales**
- **Purchases**
- **Importation**

Convert the dashboard into a **general BIR Data & DAT File Automation Overview** while keeping the existing UI style, shadcn components, spacing, responsiveness, and current project structure.

Do not redesign the whole dashboard from scratch.

## Dashboard Header

Change:

**Importation & BIR DAT File Automation Overview**

to:

**BIR Data & DAT File Automation Overview**

Keep the existing **Tax Month selector** and make dashboard data respond to the selected month/year where applicable.

## Main Summary Cards

Replace the Importation-focused cards with:

1. **Total Sales**
   - Total sales amount for selected month
   - Number of sales records

2. **Total Purchases**
   - Total purchase amount for selected month
   - Number of purchase records

3. **Total Importations**
   - Total importation amount / landed cost
   - Number of importation records

4. **Total VAT**
   - Combined VAT summary from available Sales, Purchases, and Importation data

Use actual existing database fields and relationships. **Do not invent database columns.**

## Analytics

### Monthly Transactions

Create a January–December chart showing:

- Sales
- Purchases
- Importations

### Monthly Amount Trend

Create a chart comparing monthly amounts for:

- Sales
- Purchases
- Importations

Use separate chart series for each module.

## Monthly Summary

Replace the Importation-only summary with a general summary for the selected Tax Month.

Use available data such as:

- Total Sales
- Total Purchases
- Total Importation / Landed Cost
- Output VAT
- Input VAT
- Importation VAT
- Combined VAT summary

Only show calculations supported by the existing database.

## Implementation Requirements

- First inspect existing **Sales, Purchases, Importation, and related BIR tables/models**.
- Reuse existing models, queries, services, and relationships where possible.
- Do not create duplicate calculations.
- Do not modify the database structure unless absolutely necessary.
- Do not remove Importation analytics; integrate them into the broader dashboard.
- Keep complex calculations in the backend/service layer, not React components.
- Preserve the existing Tax Month filtering behavior.
- Keep the current **shadcn-based UI design**.
- Maintain responsive desktop, tablet, and mobile layouts.
- Add proper loading, empty, and zero-data states.
- Format Philippine currency consistently as **₱0.00**.
- Keep changes focused on the Dashboard and supporting backend logic.

## Before Implementation

Inspect the current project structure first and determine which existing **Sales, Purchases, and Importation** data can safely be used for dashboard calculations before making changes.
