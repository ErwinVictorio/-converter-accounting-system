# System Flow / Workflow Diagram

Derived entirely from the current implementation — [routes/web.php](../routes/web.php), the
controllers in [app/Http/Controllers/](../app/Http/Controllers/), the importers in
[app/Imports/](../app/Imports/), the services in [app/Services/BIR/](../app/Services/BIR/), and the
Inertia pages in [resources/js/Pages/](../resources/js/Pages/).

Every route, table, file name and rule shown below exists in the code. Nothing is planned,
inferred, or aspirational. Where a module deliberately does *not* connect to another, that is
stated rather than left out.

---

## 1. System map

The four data modules are independent stores. They meet in exactly two places: the Dashboard,
which aggregates them, and Generate DAT File, which emits one file per module.

```mermaid
flowchart TD
    Login["/login<br/>LoginController"]
    Dash["/ (Dashboard)<br/>DashboardMetrics"]

    Login -->|"Auth::attempt OK<br/>intended('/')"| Dash

    subgraph DT["Data &amp; Transactions"]
        Import["/records<br/>Import Data (upload only)"]
        Imprt["/importation<br/>Importation manual entry"]
        Gen["/generate-datfile<br/>Generate DAT File"]
    end

    subgraph ST["Stores"]
        VI[("vat_inputs")]
        SV[("sales_vatsinputs")]
        EW[("expanded_wtax_entries")]
        IE[("importation_entries")]
    end

    subgraph MD["Master Data"]
        Sup["/suppliers"]
        Cus["/customers"]
        Brk["/brokers"]
        Cmp["/withholding-companies"]
    end

    subgraph RC["Record (read + maintain)"]
        RP["/records/purchases"]
        RS["/records/sales"]
        RE["/records/expanded-wtax"]
        RI["/records/importations"]
    end

    Dash --> DT
    Dash --> MD
    Dash --> RC

    Import -->|purchase| VI
    Import -->|sales| SV
    Import -->|expanded| EW
    Imprt --> IE
    IE -.->|"syncVatInput mirror"| VI

    Sup -.->|"read at upload"| Import
    Cus -.->|"read at upload<br/>+ back-fill on save"| SV
    Brk -.->|"gates Adjust"| RP
    Cmp -.->|"withholding agent"| Import
    Cmp -.->|"1601EQ header"| Gen

    VI --> RP
    SV --> RS
    EW --> RE
    IE --> RI

    VI --> Gen
    SV --> Gen
    EW --> Gen
    IE --> Gen

    Gen --> DAT["`.DAT` download"]

    VI --> Dash
    SV --> Dash
    EW --> Dash
    IE --> Dash
```

Sidebar grouping is defined in [app-sidebar.jsx](../resources/js/Components/app-sidebar.jsx):
**Main Menu** (Dashboard) · **Data & Transactions** (Import Data, Importation, Generate DAT File) ·
**Record** (4 listings) · **Master Data** (Customers, Suppliers, Brokers, Companies) · footer Log out.

---

## 2. Login → Dashboard

```mermaid
flowchart TD
    Start(["User opens any URL"]) --> Check{"auth<br/>middleware"}
    Check -->|"not authenticated"| Redir["redirect to route('login')"]
    Redir --> GetLogin["GET /login<br/>middleware: guest<br/>LoginController@create"]
    GetLogin --> Page["Inertia render 'Login'"]
    Page --> Post["POST /login<br/>LoginController@store"]

    Post --> Val{"validate<br/>username required<br/>password required"}
    Val -->|fails| Page
    Val -->|passes| Att{"Auth::attempt(credentials,<br/>remember)"}

    Att -->|false| Err["ValidationException on 'username':<br/>'These credentials do not match our records.'"]
    Err --> Page
    Att -->|true| Regen["session()->regenerate()"]
    Regen --> Intended["redirect()->intended('/')"]

    Check -->|"authenticated"| Intended
    Intended --> DashIdx["GET /<br/>Dashboard@index"]

    DashIdx --> Month["resolveTaxMonth(request tax_month)<br/>blank or unparseable =&gt;<br/>previous month, startOfMonth"]
    Month --> Metrics["DashboardMetrics@forMonth"]

    Metrics --> Q1["4 sources, each COUNT + SUM amount + SUM tax<br/>scoped to month start..end"]
    Q1 --> Q2["purchases read via<br/>excludingImportationMirrors()"]
    Q2 --> Q3["expanded record count via<br/>ExpandedWtaxEntry::consolidate()"]
    Q3 --> Q4["vatBreakdown: net = output − (input + importation)<br/>expanded tax withheld deliberately excluded"]
    Q4 --> Q5["monthlySeries: Jan–Dec, grouped in PHP<br/>availableMonths: rolling 24 months + months with data<br/>recentImportations: 5 newest for the month"]
    Q5 --> Render["Inertia render 'Dashboard'"]

    Render --> Logout["POST /logout<br/>middleware: auth"]
    Logout --> Kill["logout + session invalidate<br/>+ regenerateToken"]
    Kill --> GetLogin
```

**Source columns per module** ([DashboardMetrics::SOURCES](../app/Services/BIR/DashboardMetrics.php#L45)):

| Module | Table | Month column | Amount | Tax |
| --- | --- | --- | --- | --- |
| Sales | `sales_vatsinputs` | `reporting_period` | `net_amount` | `output_vat` |
| Purchases | `vat_inputs` | `date_uploaded` | `total_purchases` | `input_vat` |
| Importation | `importation_entries` | `tax_month` | `total_landed_cost` | `vat_payable` |
| Expanded | `expanded_wtax_entries` | `reporting_period` | `income_payment` | `tax_withheld` |

---

## 3. Import Data → Purchase / Sales / Expanded WTAX

One screen, one endpoint, three branches. The type is always chosen explicitly on the form — it is
never inferred from the workbook.

```mermaid
flowchart TD
    Idx["GET /records<br/>VatInputController@index"] --> Props["Inertia 'RecordEntry'<br/>birCompanies = directory->activeCompanies()"]
    Props --> Form["Form: excel_file, reporting_month,<br/>record_type, + agent TIN/branch when expanded"]
    Form --> PostV["POST /vat-import<br/>VatInputController@import"]

    PostV --> V{"validate<br/>file mimes xlsx,xls,csv max 10240<br/>reporting_month date<br/>record_type in purchase,sales,expanded<br/>agent TIN/branch required_if expanded"}
    V -->|fails| Form
    V -->|passes| Period["reportingPeriod =<br/>parse(reporting_month)->endOfMonth()"]

    Period --> Branch{"record_type"}

    Branch -->|purchase| P1["VatInputImport<br/>headingRow = 3"]
    Branch -->|sales| S1["SalesVatInputImport"]
    Branch -->|expanded| E1["withholdingAgentFromRequest()<br/>directory->resolve(tin, branch)"]

    P1 --> P2["per row: findSupplier()<br/>1. exact 12-digit TIN<br/>2. first 9 TIN digits<br/>3. exact supplier name"]
    P2 --> P3["supplier found =&gt; its tin/name/addr/city win<br/>not found =&gt; workbook values, address split on comma"]
    P3 --> P4["skip row when no name at all<br/>skip TOTAL / GRAND TOTAL / SUBTOTAL"]
    P4 --> P5{"existing row?<br/>supplier_name + is_imported<br/>+ is_adjusted=false + date_uploaded"}
    P5 -->|yes| P6["add amounts into it,<br/>recompute taxable/input_vat/total"]
    P5 -->|no| P7["VatInput::create<br/>is_imported = purchase_imported &gt; 0"]
    P6 --> VIt[("vat_inputs")]
    P7 --> VIt

    S1 --> S2{"layout sniff on first cell"}
    S2 -->|"'DOCUMENT NO'"| S3["format = summary"]
    S2 -->|"'CLIENT TIN'"| S4["format = bir"]
    S3 --> S5["importSalesSummaryRow<br/>customer at col 6, amounts 8–13<br/>document_no falls back to<br/>SALES-SUMMARY-{period}-{row}"]
    S4 --> S6["importBirSalesRow<br/>names cols 1–4, amounts 7–13<br/>document_no = BIR-R-SALES-{period}-{row}"]
    S5 --> S7["findCustomer by Customer::normalizeName<br/>=&gt; name_key match"]
    S6 --> S7
    S7 --> S8["updateOrCreate on<br/>document_no + customer_name + reporting_period"]
    S8 --> SVt[("sales_vatsinputs")]

    E1 --> E2["ExpandedWtaxUploadPreflight@check<br/>BEFORE anything is deleted"]
    E2 --> E3{"issues?"}
    E3 -->|"missing columns"| ER["back with error naming the columns<br/>+ both accepted layouts<br/>existing month untouched"]
    E3 -->|"Reporting_Month outside selected month"| ER
    E3 -->|"none"| E4["DB::transaction"]
    E4 --> E5["DELETE expanded_wtax_entries<br/>WHERE reporting_period = period<br/>AND agent tin + branch match"]
    E5 --> E6["ExpandedWtaxImport<br/>detect heading row"]
    E6 --> E7{"layout"}
    E7 -->|bir| E8["11 columns.<br/>income_payment, ewt_rate, tax_amount<br/>stored exactly as the workbook computed them.<br/>ATC read from the file; blank stays null"]
    E7 -->|system| E9["each non-zero rate column becomes one row<br/>income_payment = tax ÷ (rate/100)<br/>ATC from config rate mapping / TIN override<br/>branch forced to 0000"]
    E8 --> EWt[("expanded_wtax_entries")]
    E9 --> EWt

    VIt --> OK["back with success<br/>screen links to the matching Record page"]
    SVt --> OK
    EWt --> OK
```

Branch-specific behaviour worth calling out, because it is **not** symmetric:

| | Purchase | Sales | Expanded WTAX |
| --- | --- | --- | --- |
| Preflight check | none | none | `ExpandedWtaxUploadPreflight` |
| Re-upload same month | merges into matching rows | `updateOrCreate` per document | **deletes the month for that agent, then re-imports** |
| Master Data read | `Supplier` | `Customer` | `WithholdingCompany` (as agent, not payee) |
| Withholding agent stored on row | no | no | yes (`withholding_agent_tin`/`branch_code`/`name`) |
| Amounts | derived/summed where columns are absent | read from fixed column offsets | BIR layout: stored as filed. System layout: income payment computed from tax and rate |

---

## 4. Importation manual entry

The only module keyed by hand. Each entry is mirrored into `vat_inputs` so the purchase DAT engine
can emit it — and that mirror is then excluded from the purchase DAT and from dashboard purchases,
so the same transaction is never counted twice.

```mermaid
flowchart TD
    Idx["GET /importation<br/>ImportationController@index<br/>Inertia 'Importation' — form only, no listing"]
    Idx --> Post["POST /importation<br/>@store"]

    Post --> V["validateEntry()"]
    V --> V1{"required: tax_month, import_entry_no,<br/>assessment_date, supplier, importation_date,<br/>country, total_landed_cost, dutiable_value,<br/>exempt, vat_rate, vat_payable,<br/>or_number, payment_date"}
    V1 -->|fails| Idx
    V1 --> V2{"dutiable_value &gt; total_landed_cost?"}
    V2 -->|yes| VE["ValidationException:<br/>'Dutiable value cannot be more than<br/>the total landed cost.'"]
    V2 -->|no| V3{"exempt &gt; total_landed_cost?"}
    V3 -->|yes| VE2["ValidationException:<br/>'Exempt cannot be more than<br/>the total landed cost.'"]
    V3 -->|no| V4{"import_entry_no already used<br/>in this tax_month?"}
    V4 -->|yes| VE3["ValidationException:<br/>'This import entry no. already exists<br/>for the selected tax month.'"]
    V4 -->|no| Pay["payload()"]

    Pay --> D1["tax_month = startOfMonth<br/>text fields through birText()<br/><b>charges = landed − dutiable</b><br/><b>taxable_goods = landed − exempt</b>"]
    D1 --> Seq["sequence_number = max for month + 1"]
    Seq --> Tx["DB::transaction"]
    Tx --> Create["ImportationEntry::create"]
    Create --> Sync["syncVatInput(entry)"]

    Sync --> M1["mirror row in vat_inputs:<br/>supplier_name / company_name = entry supplier<br/>tin_number = config('bir.importation.tin')<br/>address1 = entry country<br/>address2 = config('bir.importation.address2')<br/>is_imported = true, vendor_type = company"]
    M1 --> M2["purchase_imported = capital_goods<br/>= taxable_net_of_vat = taxable_goods<br/>exempt = entry exempt<br/>input_vat = entry vat_payable<br/>date_uploaded = tax_month endOfMonth"]
    M2 --> M3{"entry.vat_input_id set<br/>and row found?"}
    M3 -->|yes| M4["update that vat_inputs row"]
    M3 -->|no| M5["create row, then<br/>forceFill vat_input_id back on the entry"]

    M4 --> Done["back with success"]
    M5 --> Done

    Upd["PUT /importation/{entry}<br/>@update"] --> V
    Upd -.->|"sequence_number unset<br/>original order preserved"| Tx
    Del["DELETE /importation/{entry}<br/>@destroy"] --> DelTx["transaction:<br/>delete mirror vat_inputs row by vat_input_id,<br/>then delete the entry"]

    M4 --> Excl
    M5 --> Excl
    Excl["VatInput::scopeExcludingImportationMirrors()<br/>whereNotIn id, (select vat_input_id<br/>from importation_entries where not null)"]
    Excl --> Ex1["Purchase DAT download skips mirrors"]
    Excl --> Ex2["Purchase period list skips mirrors"]
    Excl --> Ex3["Dashboard 'purchases' skips mirrors"]
```

`charges` and `taxable_goods` are the only derived amounts; the entry screen shows them read-only.
`config('bir.importation.tin')` is currently `000-472-103-000`, carrying a `TODO` in
[config/bir.php](../config/bir.php#L25) to confirm the Bureau of Customs TIN used for filing.

---

## 5. Master Data → transaction wiring

This is the part that is easiest to get wrong by assumption, so each of the four is drawn with the
*exact* point at which it touches a transaction. Two are read-only at upload time; one back-fills
existing rows; one is matched at query time and never stored on the row.

```mermaid
flowchart LR
    subgraph SUP["Suppliers — /suppliers"]
        S0["CRUD.<br/>TIN formatted to 9 or 12 digits,<br/>name/addr/city uppercased.<br/>Paginated 10, filter by tin + name."]
    end
    subgraph CUS["Customers — /customers"]
        C0["CRUD.<br/>birText name + normalized name_key,<br/>TIN formatted. Paginated 10."]
    end
    subgraph BRK["Brokers — /brokers"]
        B0["CRUD.<br/>broker_name + tin_number only.<br/>Full list, no pagination."]
    end
    subgraph CMP["Companies — /withholding-companies"]
        P0["CRUD + activate/deactivate.<br/>tin digits:9, branch_code digits:4,<br/>unique on the pair."]
    end

    S0 -->|"read during<br/>VatInputImport::findSupplier()"| SU1["Purchase upload:<br/>supplier TIN, name, addr, city<br/>overwrite the workbook values"]
    SU1 --> VIT[("vat_inputs")]
    S0 -.->|"no back-fill.<br/>editing a supplier does NOT<br/>update existing vat_inputs"| VIT
    S0 -->|"3rd fallback in<br/>companyLookup"| LK["GET /bir/company/{tin}"]

    C0 -->|"read during<br/>SalesVatInputImport::findCustomer()<br/>match on name_key"| CU1["Sales upload:<br/>customer tin, company_name,<br/>addr, city; type forced to company"]
    CU1 --> SVT[("sales_vatsinputs")]
    C0 ==>|"store + update call<br/>syncSalesRows()"| CU2["<b>back-fills</b> every sales row whose<br/>normalized customer_name = name_key:<br/>tin, type=company, company_name,<br/>address1/2 set; name parts nulled"]
    CU2 --> SVT

    B0 -->|"matched on first 9 TIN digits<br/>at query time"| BR1["/records/purchases computes<br/>is_broker in SQL per row<br/>(0 when is_adjusted)"]
    B0 -->|"isBrokerRecord() gate"| BR2["Adjust screen + adjustedLookup + update<br/>refused when TIN matches no broker,<br/>or the row is already adjusted"]
    BR1 -.->|"never written to<br/>the transaction row"| VIT

    P0 -->|"activeCompanies()"| DIR["WithholdingCompanyDirectory"]
    CFG["config('bir.companies')"] -->|fallback 2| DIR
    UPL["distinct agents already in<br/>expanded_wtax_entries"] -->|fallback 3| DIR
    DIR --> D1["Import Data: Known Company dropdown<br/>=&gt; agent stored on every uploaded row"]
    DIR --> D2["Generate DAT: which company's rows are<br/>listed, and the 1601EQ header identity"]
    P0 ==>|"companyForDat() — finds<br/>inactive rows too"| D3["TIN, branch, registered_name,<br/>address1/2, RDO for the DAT header"]
```

Enforced rules on **Companies**, which exist because expanded rows carry the agent by value rather
than by foreign key ([WithholdingCompanyController](../app/Http/Controllers/WithholdingCompanyController.php)):

- `hasFiledRows()` true → **TIN and branch cannot be edited**; the message tells the user to add a
  new company instead.
- `hasFiledRows()` true → **DELETE is refused**; deactivate instead.
- Deactivated companies are hidden from the dropdowns but `companyForDat()` still finds them, so a
  month already filed under one can still be regenerated.
- Directory priority is **managed active → config → already-uploaded**; first occurrence of a
  `tin|branch_code` pair wins, so a later source never overrides an earlier one.

---

## 6. Record listings, validation, and Broker adjustment

```mermaid
flowchart TD
    subgraph LIST["Record listings (read-only except Purchase actions)"]
        L1["/records/purchases<br/>RecordController@purchases<br/>paginate 15, search supplier_name or tin_number<br/>is_broker computed in SQL via SUBSTR match"]
        L2["/records/sales<br/>@sales — GROUP BY customer identity,<br/>SUM of the six amount columns"]
        L3["/records/expanded-wtax<br/>@expandedWtax — consolidate() in PHP,<br/>then LengthAwarePaginator 15"]
        L4["/records/importations<br/>ImportationController@records<br/>paginate 15, filter by tax_month"]
    end

    L1 --> A1{"row actions"}
    A1 -->|"BIR info dialog"| BI["PUT /records/{id}/bir-info<br/>@updateBirInfo — <b>any</b> purchase row"]
    A1 -->|"Adjust (broker rows only)"| ADJ["GET /records/{id}/edit"]

    BI --> BI1["validate vendor_type, TIN regex,<br/>name fields required_if type,<br/>reject TIN starting 000000000"]
    BI1 --> BI2["normalize: birText() uppercase,<br/>&amp; =&gt; AND, commas stripped,<br/>address split on last comma"]
    BI2 --> BI3["update the row in place.<br/>No new row, no amount change."]

    ADJ --> G{"isBrokerRecord()<br/>not is_adjusted<br/>AND first 9 TIN digits match a broker"}
    G -->|no| GR["redirect /records with error<br/>'Only broker records can be edited.'"]
    G -->|yes| EP["Inertia 'EditVatInputRecord'"]

    EP --> LU["GET /records/{id}/adjusted-lookup<br/>(debounced, as the TIN is typed)"]
    LU --> LU1{"9 TIN digits present?"}
    LU1 -->|no| LU2["adjustedRecord: null"]
    LU1 -->|yes| LU3["find VatInput where is_adjusted<br/>AND is_imported matches<br/>AND same date_uploaded<br/>AND first 9 TIN digits match"]
    LU3 --> LU4["prefill the form from it,<br/>so the transfer targets<br/>the existing adjusted row"]

    EP --> PUT["PUT /records/{id}<br/>@update"]
    PUT --> C1{"isBrokerRecord() again"}
    C1 -->|no| GR
    C1 --> C2{"each amount ≤ the broker row's<br/>original amount?"}
    C2 -->|no| CE["error on that field:<br/>'Amount cannot be greater than<br/>the original broker amount.'"]
    C2 -->|yes| C3{"sum of the 4 amounts &gt; 0?"}
    C3 -->|no| CE2["error 'total':<br/>'Please enter at least one<br/>amount to transfer.'"]
    C3 -->|yes| C4{"TIN starts 000000000?"}
    C4 -->|yes| CE3["error tin_number"]
    C4 -->|no| TX["DB::transaction"]

    TX --> T1["lockForUpdate() the matching<br/>adjusted row, if any"]
    T1 --> T2{"found?"}
    T2 -->|yes| T3["<b>add</b> the transferred amounts to it,<br/>recompute other_than_capital_goods,<br/>taxable_net_of_vat, input_vat = total × 0.12,<br/>total_purchases, total"]
    T2 -->|no| T4["create it: is_adjusted = true,<br/>is_broker = false, capital_goods = 0,<br/>vat_rate = 12, input_vat = total × 0.12,<br/>same date_uploaded as the broker row"]
    T3 --> T5["<b>subtract</b> the same amounts<br/>from the broker row,<br/>set its is_broker = true"]
    T4 --> T5
    T5 --> Fin["redirect /records with<br/>'VAT input record adjusted successfully.'"]
```

The adjustment is a **transfer, not a copy**: the four amounts are subtracted from the broker row
and added to a real-vendor row that carries `is_adjusted = true`. The pair therefore still sums to
the original total, and because an `is_adjusted` row is never itself adjustable, the transfer cannot
cascade.

### Where row validation actually runs

The four `Bir*RowValidator` services are **not** invoked during upload. They run only on the
Generate DAT screen — once to annotate each period, and again to block the download.

```mermaid
flowchart LR
    subgraph VAL["app/Services/BIR"]
        VP["BirPurchaseRowValidator"]
        VS["BirSalesRowValidator"]
        VI["BirImportationRowValidator"]
        VE["BirExpandedWtaxRowValidator"]
    end

    VP --> R1["TIN: 9 digits, not 000000000<br/>vendor_type in company/individual<br/>company_name required for company<br/>last/first/middle required for individual<br/>address1 required<br/>no comma or ampersand in text fields<br/>6 amount fields must be numeric"]
    VS --> R2["same shape, customer side"]
    VI --> R3["13 required fields · no comma/ampersand<br/>dates parseable · amounts numeric<br/>vat_rate &gt; 0<br/><b>cross-checks</b> taxable_goods and<br/>vat_payable against the other columns"]
    VE --> R4["payee_tin ≥ 9 digits, not 000000000<br/>branch_code required<br/>payee_type consistency: company_name<br/>alongside individual names is an error<br/>tax_rate numeric and &gt; 0<br/><b>cross-checks</b> tax_withheld against<br/>income_payment × rate"]

    R1 --> U1["Generate DAT index:<br/>periodIssues[period] =<br/>invalid_count + first 10 errors"]
    R2 --> U1
    R3 --> U1
    R4 --> U1
    U1 --> U2["UI disables Download<br/>while invalid_count &gt; 0"]
    R1 --> U3["Download: re-validated.<br/>Any error =&gt; back with the<br/>first 5 messages, no file"]
    R2 --> U3
    R3 --> U3
    R4 --> U3
```

Upload-time checking is separate and exists only for Expanded WTAX
(`ExpandedWtaxUploadPreflight`: missing columns, and rows whose month falls outside the selected
month). Purchase and Sales uploads store whatever parses and are first judged at DAT time.

---

## 7. Generate DAT File

```mermaid
flowchart TD
    Idx["GET /generate-datfile<br/>DatFileController@index"] --> IV["validate record_type in<br/>purchase, sales, importation, expanded<br/>(default purchase)<br/>+ optional agent tin/branch"]
    IV --> Agent["selectedWithholdingAgent()<br/>directory->resolve()"]
    Agent --> Which{"record_type"}

    Which -->|purchase| PP["purchasePeriods()<br/>vat_inputs, excludingImportationMirrors<br/>DATE_FORMAT group by month"]
    Which -->|sales| SP["salesPeriods()<br/>sales_vatsinputs where reporting_period not null<br/>DATE_FORMAT group by month"]
    Which -->|importation| IP["importationPeriods()<br/>importation_entries<br/>DATE_FORMAT group by month"]
    Which -->|expanded| EPd["expandedPeriods()<br/>filtered to the selected agent<br/>grouped in PHP, driver-independent<br/>records_count = consolidated line count"]

    PP --> Ren["Inertia 'GenerateDatFile'<br/>availablePeriods, periodIssues,<br/>birCompanies, selectedWithholdingAgent,<br/>defaultCompany = config bir.companies.008791976"]
    SP --> Ren
    IP --> Ren
    EPd --> Ren

    Ren --> Look["GET /bir/company/{tin}<br/>@companyLookup"]
    Look --> LK1["1. config('bir.companies.{tin}') — 12 then 9 digits"]
    LK1 --> LK2["2. latest vat_inputs row with matching first 9 digits"]
    LK2 --> LK3["3. suppliers row by full or 9-digit TIN"]
    LK3 --> LK4["else 404 'TIN not found.'"]

    Ren --> Dl["GET /download-datfile<br/>@download<br/>period required date"]
    Dl --> DW{"record_type"}

    DW -->|purchase| DP["vat_inputs, excludingImportationMirrors,<br/>date_uploaded within month, order by id"]
    DW -->|sales| DS["sales_vatsinputs within month, order by id<br/>=&gt; groupSalesRows(): group on 9-digit TIN,<br/>type, normalized name, name parts,<br/>addresses; sum the 4 amounts"]
    DW -->|importation| DI["importation_entries within month,<br/>order by sequence_number, id"]
    DW -->|expanded| DE["expanded_wtax_entries for the agent,<br/>within month, order by payee_name,<br/>tax_rate, id"]

    DP --> Empty{"empty?"}
    DS --> Empty
    DI --> Empty
    DE --> Empty
    Empty -->|yes| EM["back with 'No ... records found<br/>for the selected reporting month.'"]
    Empty -->|no| RV{"row validator errors?"}
    RV -->|yes| RE2["back with 'Cannot generate DAT.<br/>Fix these ... rows first: ' + first 5"]
    RV -->|no| Cons["expanded only:<br/>consolidate() AFTER validating,<br/>no re-sort — order carries through"]

    Cons --> Comp{"company header from"}
    Comp -->|"purchase / sales / importation"| CH1["config('bir.companies.008791976')<br/>hardcoded, plus final_header_field = '12'"]
    Comp -->|expanded| CH2["directory->companyForDat(agent)<br/>managed row (incl. inactive) =&gt;<br/>config =&gt; caller's name"]

    CH1 --> Gen["Relief*DatGenerator@generate"]
    CH2 --> Gen
    Gen --> Name["filename()"]
    Name --> F1["purchase: {tin}P{mYYYY}.DAT"]
    Name --> F2["sales: {tin}S{mYYYY}.DAT"]
    Name --> F3["importation: {tin}I{mYYYY}.DAT"]
    Name --> F4["expanded: {tin}{branch}{mYYYY}1601EQ.DAT"]
    F1 --> Resp["response(content)<br/>Content-Type: text/plain<br/>Content-Disposition: attachment"]
    F2 --> Resp
    F3 --> Resp
    F4 --> Resp
```

Two asymmetries in the current implementation, both deliberate in the code comments:

1. **Only Expanded WTAX is filed per company.** Purchase, Sales and Importation build their header
   from the hardcoded `config('bir.companies.008791976')` entry regardless of what is selected on
   screen. The Known Company selector appears only in expanded mode.
2. **Expanded periods are grouped in PHP**; purchase, sales and importation period listings use
   MySQL's `DATE_FORMAT`, so those three listings are MySQL-only.

---

## 8. Route → controller → store reference

| Method | Route | Controller | Reads / writes |
| --- | --- | --- | --- |
| GET | `/login` | `LoginController@create` | — (guest) |
| POST | `/login` | `LoginController@store` | `users` |
| POST | `/logout` | `LoginController@destroy` | session (auth) |
| GET | `/` | `Dashboard@index` | all 4 stores, read-only |
| GET | `/records` | `VatInputController@index` | directory (companies) |
| POST | `/vat-import` | `VatInputController@import` | `vat_inputs` / `sales_vatsinputs` / `expanded_wtax_entries` |
| GET | `/records/purchases` | `RecordController@purchases` | `vat_inputs` + `brokers` |
| GET | `/records/sales` | `RecordController@sales` | `sales_vatsinputs` |
| GET | `/records/expanded-wtax` | `RecordController@expandedWtax` | `expanded_wtax_entries` |
| GET | `/records/importations` | `ImportationController@records` | `importation_entries` |
| GET | `/records/{id}/adjusted-lookup` | `VatInputController@adjustedLookup` | `vat_inputs` (JSON) |
| GET | `/records/{id}/edit` | `VatInputController@edit` | `vat_inputs` + `brokers` gate |
| PUT | `/records/{id}` | `VatInputController@update` | `vat_inputs` (2 rows) |
| PUT | `/records/{id}/bir-info` | `VatInputController@updateBirInfo` | `vat_inputs` (1 row) |
| GET | `/generate-datfile` | `DatFileController@index` | period + issue listing |
| GET | `/bir/company/{tin}` | `DatFileController@companyLookup` | config → `vat_inputs` → `suppliers` |
| GET | `/download-datfile` | `DatFileController@download` | the selected store, read-only |
| GET/POST/PUT/DELETE | `/brokers`, `/create`, `/brokers/{id}` | `ManageBrokerController` | `brokers` |
| GET/POST/PUT/DELETE | `/suppliers`, `/suppliers/{id}` | `SupplierController` | `suppliers` |
| GET/POST/PUT/DELETE | `/customers`, `/customers/{id}` | `CustomerController` | `customers` + back-fills `sales_vatsinputs` |
| GET | `/importation` | `ImportationController@index` | — (form only) |
| POST/PUT/DELETE | `/importation`, `/importation/{entry}` | `ImportationController` | `importation_entries` + mirrored `vat_inputs` |
| GET/POST/PUT/DELETE | `/withholding-companies`, `/{company}` | `WithholdingCompanyController` | `withholding_companies` |
| PATCH | `/withholding-companies/{company}/deactivate` | `@deactivate` | `withholding_companies.is_active` |
| PATCH | `/withholding-companies/{company}/activate` | `@activate` | `withholding_companies.is_active` |

Every route except `GET /login`, `POST /login` is inside the `auth` middleware group; `GET /login`
and `POST /login` are inside `guest`.

---

## 9. What is deliberately absent

Stated so the diagram is not read as incomplete:

- **No Broker adjustment for Sales, Expanded WTAX or Importation.** The Adjust flow exists only on
  purchase rows, gated by a broker TIN match.
- **No Suppliers back-fill.** Editing a supplier does not rewrite existing `vat_inputs` rows; only
  Customers back-fill (`syncSalesRows`).
- **No upload path for Importation.** It is manual entry only.
- **No manual entry for Purchase, Sales or Expanded WTAX.** They are upload only; existing purchase
  rows can be corrected through the BIR-info dialog and the Adjust screen, but not created by hand.
- **No per-company selection for Purchase / Sales / Importation DAT.** Header identity is the
  hardcoded config company.
- **No foreign key from `expanded_wtax_entries` to `withholding_companies`.** The agent is carried
  by value, which is why the controller enforces deactivate-not-delete and locks TIN/branch edits.
- **No expanded withholding tax in the VAT breakdown.** `vatBreakdown()` reads only sales, purchase
  and importation tax; 1601EQ tax withheld is reported on its own card.
