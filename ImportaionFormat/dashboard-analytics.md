# Codex Prompt -- Dashboard Analytics

Update the **Dashboard UI** based on the provided reference image.

## Tech Stack

-   Laravel + Inertia React
-   **shadcn/ui**
-   **Motion for React** (`motion/react`) --- already installed
-   **Recharts / shadcn Chart** for analytics
-   Lucide icons

## Dashboard Summary Cards

Add 4 summary cards:

-   **Total Importations**
-   **This Month**
-   **Total Taxable Goods**
-   **Total VAT**

Use real values from the existing database.

## Importations Per Month

Add a responsive bar chart:

-   January to December
-   Show the total number of importation records per month
-   Use Recharts / shadcn Chart
-   Data must come from the database

## DAT File Status

Add a status card with:

-   Total Records
-   Ready for DAT
-   Incomplete Records
-   Readiness percentage
-   Donut/Pie chart for Ready vs Incomplete
-   **Generate DAT File** button

Do not change the existing DAT generation logic.

## Monthly Amount Summary

Display:

-   Total Landed Cost
-   Dutiable Value
-   Taxable Goods
-   Total VAT

Use the selected Tax Month for calculations.

## Recent Importation Entries

Add a table containing:

-   Import Entry No.
-   Name of Seller
-   Country of Origin
-   Tax Month
-   Taxable Goods
-   VAT
-   Status
-   Date Added

Show only recent records.

## Tax Month Filter

Add a **Tax Month** selector at the top of the dashboard.

-   Default to the **previous month**
-   Changing the Tax Month should update the summary cards, charts, DAT
    status, and recent records
-   Keep filtering efficient and use existing database columns

## Motion Animations

Use the already-installed **Motion for React** package.

Import from:

``` jsx
import { motion } from "motion/react";
```

Use subtle professional animations:

-   Dashboard content fades/slides in on page load
-   Summary cards use a small stagger animation
-   Cards slightly lift on hover
-   Analytics/chart sections fade in
-   Recent entries section fades/slides in
-   Keep animations smooth and fast, preferably **200--300ms**
-   Avoid excessive or distracting animations
-   Respect `prefers-reduced-motion`

## UI Style

-   Follow the provided dashboard reference
-   Keep the existing sidebar/header structure
-   Clean corporate design
-   Consistent spacing and typography
-   Use shadcn `Card`, `Badge`, `Button`, `Select`, `Table`, and Chart
    components
-   Use Lucide icons where appropriate
-   Responsive on desktop, tablet, and mobile
-   Maintain the project's existing theme/style

## Important Constraints

-   **Use REAL database data only --- NO hardcoded analytics or sample
    data**
-   Use existing models, tables, and columns
-   Calculate dashboard totals/counts from existing importation records
-   **DO NOT modify the database structure**
-   **DO NOT create or modify migrations**
-   **DO NOT change the existing DAT file generation logic**
-   **DO NOT break existing Importation features**
-   Do not install another animation library because Motion is already
    installed
-   Reuse existing components and project structure whenever possible
-   Focus only on the Dashboard UI, analytics, filtering, and animations
