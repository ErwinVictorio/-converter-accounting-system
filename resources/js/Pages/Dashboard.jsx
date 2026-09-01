import { useEffect, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { motion } from "motion/react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  Banknote,
  BarChart3,
  Calculator,
  Database,
  FileText,
  Loader2,
  Percent,
  ReceiptText,
  Ship,
  ShoppingCart,
  TrendingDown,
  TrendingUp,
  TrendingUpDown,
  Wallet,
} from "lucide-react";

import MainLayout from "@/Layouts/MainLayout";
import { Card } from "@/Components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/Components/ui/select";

/**
 * One definition per module, shared by the cards, both charts, the legends and
 * the monthly summary, so a colour or a label can never drift between them.
 */
const MODULES = [
  {
    key: "sales",
    label: "Sales",
    cardLabel: "Total Sales",
    color: "#0344a4",
    tone: "blue",
    icon: ReceiptText,
  },
  {
    key: "purchases",
    label: "Purchases",
    cardLabel: "Total Purchases",
    color: "#059669",
    tone: "green",
    icon: ShoppingCart,
  },
  {
    key: "importation",
    label: "Importation",
    cardLabel: "Total Importations",
    color: "#d97706",
    tone: "amber",
    icon: Ship,
  },
];

/**
 * Expanded withholding tax is filed on 1601EQ/QAP, not on a VAT return, so it stays
 * out of the VAT card row and gets its own panel. It is still a monthly figure
 * per payee, so it does belong in both trend charts.
 */
const EXPANDED = {
  key: "expanded",
  label: "Expanded WTAX",
  color: "#db2777",
  tone: "pink",
  icon: Percent,
};

// Both charts and their shared legend cover every module, VAT or not.
const SERIES = [...MODULES, EXPANDED];

// Every peso figure in the UI reads ₱0.00. The sign sits outside the symbol,
// which is how a credit balance is written.
const peso = (value) => {
  const amount = Number(value ?? 0);
  const formatted = Math.abs(amount).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  return `${amount < 0 ? "-" : ""}₱${formatted}`;
};

/**
 * Chart axis ticks only. A full ₱0.00 string cannot fit an axis gutter, so the
 * ticks abbreviate -- every figure the user actually reads off the page still
 * uses peso() above.
 */
const pesoAxis = (value) => {
  const amount = Number(value ?? 0);
  const magnitude = Math.abs(amount);

  if (magnitude >= 1_000_000) return `₱${(amount / 1_000_000).toFixed(1)}M`;
  if (magnitude >= 1_000) return `₱${Math.round(amount / 1_000)}K`;

  return `₱${amount}`;
};

const count = (value) => Number(value ?? 0).toLocaleString("en-PH");

const plural = (value, word) => `${count(value)} ${Number(value) === 1 ? word : `${word}s`}`;

/**
 * Month-over-month change as a percentage. Currency never appears in a badge,
 * so the badges stay short and the ₱0.00 rule holds everywhere else. Returns
 * null when there is no baseline to compare against.
 */
const percentChange = (current, previous) => {
  const from = Number(previous ?? 0);
  const to = Number(current ?? 0);

  if (from === 0 || from === to) return null;

  return ((to - from) / Math.abs(from)) * 100;
};

// Decorative only -- the country name itself is what the database stores and what
// the DAT file carries. Unknown origins simply render without a flag.
const FLAGS = {
  china: "🇨🇳",
  japan: "🇯🇵",
  "south korea": "🇰🇷",
  korea: "🇰🇷",
  taiwan: "🇹🇼",
  singapore: "🇸🇬",
  malaysia: "🇲🇾",
  thailand: "🇹🇭",
  vietnam: "🇻🇳",
  indonesia: "🇮🇩",
  india: "🇮🇳",
  "hong kong": "🇭🇰",
  philippines: "🇵🇭",
  "united states": "🇺🇸",
  usa: "🇺🇸",
  germany: "🇩🇪",
  australia: "🇦🇺",
};

const flagFor = (country) => FLAGS[String(country ?? "").trim().toLowerCase()] ?? "";

const TONES = {
  blue: "bg-blue-50 text-[#0344a4]",
  green: "bg-emerald-50 text-emerald-600",
  amber: "bg-amber-50 text-amber-600",
  violet: "bg-violet-50 text-violet-600",
  pink: "bg-pink-50 text-pink-600",
  slate: "bg-slate-100 text-slate-500",
};

// 200-300ms throughout.
const container = {
  hidden: { opacity: 0 },
  visible: { opacity: 1, transition: { duration: 0.25, staggerChildren: 0.06 } },
};

const item = {
  hidden: { opacity: 0, y: 12 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" } },
};

const axis = {
  tick: { fontSize: 11, fill: "#94a3b8" },
  stroke: "#e2e8f0",
};

function DeltaBadge({ current, previous, neutral = false }) {
  const change = percentChange(current, previous);

  if (change === null) return null;

  const up = change > 0;
  const palette = neutral
    ? "bg-slate-100 text-slate-600"
    : up
      ? "bg-emerald-50 text-emerald-600"
      : "bg-rose-50 text-rose-600";

  return (
    <span
      className={`flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold ${palette}`}
    >
      {up ? <TrendingUp className="h-3 w-3" /> : <TrendingDown className="h-3 w-3" />}
      {Math.abs(change) >= 999 ? ">999" : Math.abs(change).toFixed(1)}%
    </span>
  );
}

function StatCard({ icon: Icon, tone, label, value, footnote, badge }) {
  return (
    <motion.div variants={item} whileHover={{ y: -4 }} transition={{ duration: 0.2 }}>
      <Card className="flex h-full w-full min-w-0 items-start gap-3.5 p-5 shadow-sm ring-1 ring-slate-950/5 transition-shadow duration-200 hover:shadow-lg">
        <div
          className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-full ${TONES[tone]}`}
        >
          <Icon className="h-5 w-5" />
        </div>

        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">
            {label}
          </p>
          {/*
            text-2xl lang sa 2xl: 4-across ang grid mula xl, kaya pinakamakitid
            ang card sa 1280-1535 -- doon mag-o-overflow ang 7-digit na halaga.
          */}
          <p
            className="mt-1 truncate text-lg font-extrabold tracking-tight tabular-nums text-slate-900 sm:text-xl 2xl:text-2xl"
            title={value}
          >
            {value}
          </p>

          <div className="mt-1.5 flex items-center gap-2">
            {badge}
            <span className="truncate text-[11px] font-medium text-slate-500">{footnote}</span>
          </div>
        </div>
      </Card>
    </motion.div>
  );
}

function SeriesLegend() {
  return (
    <div className="flex flex-wrap items-center gap-x-3.5 gap-y-1">
      {SERIES.map((module) => (
        <span
          key={module.key}
          className="flex items-center gap-1.5 text-[11px] font-semibold text-slate-500"
        >
          <span
            className="h-2 w-2 shrink-0 rounded-full"
            style={{ backgroundColor: module.color }}
          />
          {module.label}
        </span>
      ))}
    </div>
  );
}

function ChartCard({ icon: Icon, tone, title, note, children }) {
  return (
    <motion.div variants={item} className="min-w-0">
      <Card className="w-full min-w-0 p-5 shadow-sm ring-1 ring-slate-950/5">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
          <div className="flex items-center gap-2.5">
            <span
              className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${TONES[tone]}`}
            >
              <Icon className="h-4 w-4" />
            </span>
            <h2 className="text-xs font-bold uppercase tracking-[0.1em] text-slate-700">
              {title}
              {note && (
                <span className="ml-1.5 font-medium normal-case text-slate-400">{note}</span>
              )}
            </h2>
          </div>

          <SeriesLegend />
        </div>

        {/*
          Dito nakatira ang taas ng chart -- hinahabol ng ResponsiveContainer
          (height="100%") at ng EmptyChart (h-full), kaya isang lugar lang ang
          babaguhin at pareho pa rin ang taas kahit walang data.
        */}
        <motion.div
          className="h-[260px] w-full min-w-0 sm:h-[300px] lg:h-[320px]"
          initial={{ opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.3, ease: "easeOut" }}
        >
          {children}
        </motion.div>
      </Card>
    </motion.div>
  );
}

/**
 * Shared by both charts. `format` and `year` are passed as element props and
 * survive the clone recharts does when it injects active/payload/label.
 */
function ChartTooltip({ active, payload, label, format, year }) {
  if (!active || !payload?.length) return null;

  return (
    <div className="min-w-[176px] rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-lg">
      <p className="text-xs font-bold text-slate-900">
        {label} {year}
      </p>

      <div className="mt-1.5 space-y-1">
        {payload.map((entry) => (
          <p key={entry.dataKey} className="flex items-center gap-2 text-xs">
            <span
              className="h-2 w-2 shrink-0 rounded-full"
              style={{ backgroundColor: entry.color }}
            />
            <span className="text-slate-500">{entry.name}</span>
            <span className="ml-auto font-semibold tabular-nums text-slate-900">
              {format(entry.value)}
            </span>
          </p>
        ))}
      </div>
    </div>
  );
}

function EmptyChart({ year }) {
  return (
    <div className="flex h-full flex-col items-center justify-center gap-2 text-center">
      <BarChart3 className="h-8 w-8 text-slate-300" />
      <p className="text-sm text-slate-500">
        No sales, purchase, importation or withholding records in {year}.
      </p>
    </div>
  );
}

function SummaryFigure({ icon: Icon, tone, label, value, hint, badge, divider }) {
  return (
    <div
      className={`flex min-w-0 items-center gap-3 lg:px-4 ${
        divider ? "lg:border-l lg:border-slate-100" : ""
      }`}
    >
      <span
        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${TONES[tone]}`}
      >
        <Icon className="h-4 w-4" />
      </span>
      <div className="min-w-0">
        <p className="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400">{label}</p>
        <div className="mt-0.5 flex items-center gap-1.5">
          <p
            className="truncate text-[15px] font-extrabold tabular-nums text-slate-900"
            title={value}
          >
            {value}
          </p>
          {badge}
        </div>
        {hint && <p className="text-[10px] font-medium text-slate-400">{hint}</p>}
      </div>
    </div>
  );
}

export default function Dashboard({
  filters = {},
  monthLabel = "",
  months = [],
  stats = {},
  summary = {},
  transactions = [],
  amounts = [],
  chartYear,
  recent = [],
  hasAnyData = false,
}) {
  const [selectedMonth, setSelectedMonth] = useState(filters.tax_month ?? "");
  const [loading, setLoading] = useState(false);

  // Keeps the trigger honest if the props change from anywhere but the select.
  useEffect(() => {
    setSelectedMonth(filters.tax_month ?? "");
  }, [filters.tax_month]);

  const handleMonthChange = (value) => {
    setSelectedMonth(value);
    router.get(
      "/",
      { tax_month: value },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
      }
    );
  };

  const vat = stats.vat ?? {};
  const summaryVat = summary.vat ?? {};
  const expanded = stats.expanded ?? {};

  // Negative net VAT is a credit carried forward, not an error.
  const vatIsPayable = Number(vat.net ?? 0) >= 0;

  const amountFigures = [
    { icon: ReceiptText, tone: "blue", label: "Total Sales", value: peso(summary.total_sales) },
    {
      icon: ShoppingCart,
      tone: "green",
      label: "Total Purchases",
      value: peso(summary.total_purchases),
    },
    {
      icon: Ship,
      tone: "amber",
      label: "Importation (Landed Cost)",
      value: peso(summary.total_importation),
    },
    {
      icon: Percent,
      tone: "pink",
      label: "WTAX Income Payments",
      value: peso(summary.total_expanded),
    },
  ];

  const vatFigures = [
    {
      icon: ReceiptText,
      tone: "blue",
      label: "Output VAT",
      value: peso(summaryVat.output),
      hint: "Sales",
    },
    {
      icon: ShoppingCart,
      tone: "green",
      label: "Input VAT",
      value: peso(summaryVat.input),
      hint: "Purchases",
    },
    {
      icon: Ship,
      tone: "amber",
      label: "Importation VAT",
      value: peso(summaryVat.importation),
      hint: "Importation",
    },
    {
      icon: Wallet,
      tone: "violet",
      label: "Combined VAT",
      value: peso(summaryVat.net),
      hint:
        Number(summaryVat.net ?? 0) >= 0
          ? "Output less input — payable"
          : "Output less input — creditable",
    },
  ];

  /**
   * The 1601EQ/QAP panel. Tax withheld leads because that is the amount remitted;
   * the income payments it was computed from and the line count follow. None of
   * these figures touch the VAT breakdown above -- withholding tax is not
   * creditable against output VAT.
   */
  const expandedFigures = [
    {
      icon: Percent,
      tone: "pink",
      label: "Tax Withheld",
      value: peso(expanded.tax_withheld),
      hint: "Remittable for the month",
      badge: (
        <DeltaBadge
          current={expanded.tax_withheld}
          previous={expanded.previous_tax_withheld}
          neutral
        />
      ),
    },
    {
      icon: Banknote,
      tone: "pink",
      label: "Income Payments",
      value: peso(expanded.amount),
      hint: "Gross paid to payees",
      badge: <DeltaBadge current={expanded.amount} previous={expanded.previous_amount} />,
    },
    {
      icon: FileText,
      tone: "slate",
      label: "Withholding Lines",
      value: count(expanded.records),
      hint: "One per payee, per ATC",
    },
  ];

  const hasTransactionData = transactions.some(
    (point) => point.sales || point.purchases || point.importation || point.expanded
  );
  const hasAmountData = amounts.some(
    (point) => point.sales || point.purchases || point.importation || point.expanded
  );

  return (
    <>
      <Head title="Dashboard" />

      <motion.div className="space-y-4 lg:space-y-5" initial="hidden" animate="visible" variants={container}>
        {/* Page header + tax month filter */}
        <motion.div
          variants={item}
          className="flex flex-col gap-3 sm:gap-4 lg:flex-row lg:items-center lg:justify-between"
        >
          <div className="min-w-0">
            <h1 className="text-xl font-extrabold tracking-tight text-slate-900 sm:text-2xl">
              Dashboard
            </h1>
            <p className="mt-0.5 text-sm text-slate-500">
              BIR Data &amp; DAT File Automation Overview
            </p>
          </div>

          <Card className="flex w-full min-w-0 items-center gap-3 px-4 py-2.5 shadow-sm ring-1 ring-slate-950/5 sm:w-auto">
            <span className="shrink-0 text-xs font-semibold text-slate-500">Tax Month:</span>
            <Select value={selectedMonth} onValueChange={handleMonthChange}>
              <SelectTrigger className="h-8 w-full min-w-0 border-0 px-1 text-sm font-bold text-slate-900 shadow-none focus:ring-0 sm:w-[180px]">
                <SelectValue placeholder="Select month" />
              </SelectTrigger>
              <SelectContent
                position="popper"
                align="end"
                sideOffset={6}
                className="!max-h-72 w-[var(--radix-select-trigger-width)] overflow-y-auto border border-slate-200 !bg-white shadow-lg"
              >
                {months.map((month) => (
                  <SelectItem key={month.value} value={month.value}>
                    {month.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Loader2
              className={`h-4 w-4 shrink-0 animate-spin text-[#0344a4] transition-opacity duration-200 ${
                loading ? "opacity-100" : "opacity-0"
              }`}
              aria-hidden={!loading}
            />
          </Card>
        </motion.div>

        {/* Nothing uploaded anywhere yet -- distinct from a month that happens to be empty. */}
        {!hasAnyData && (
          <motion.div variants={item}>
            <Card className="flex flex-col gap-4 border border-dashed border-slate-300 p-5 shadow-none ring-0 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                  <Database className="h-5 w-5" />
                </span>
                <div>
                  <p className="text-sm font-bold text-slate-900">No BIR data yet</p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    Upload sales, purchase or withholding records, or add an importation entry, and
                    this overview fills in on its own.
                  </p>
                </div>
              </div>

              <div className="flex shrink-0 flex-wrap gap-2">
                <Link
                  href="/records"
                  className="rounded-md bg-[#0344a4] px-3 py-2 text-xs font-semibold text-white transition-colors duration-200 hover:bg-[#023384]"
                >
                  Upload records
                </Link>
                <Link
                  href="/importation"
                  className="rounded-md border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition-colors duration-200 hover:bg-slate-50"
                >
                  Add importation
                </Link>
              </div>
            </Card>
          </motion.div>
        )}

        {/* Everything below reflects the selected month, so it dims while that reloads. */}
        <motion.div
          className="space-y-4 lg:space-y-5"
          animate={{ opacity: loading ? 0.55 : 1 }}
          transition={{ duration: 0.2 }}
          aria-busy={loading}
        >
          {/* Main summary cards */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:gap-5 xl:grid-cols-4">
            {MODULES.map((module) => {
              const figures = stats[module.key] ?? {};

              return (
                <StatCard
                  key={module.key}
                  icon={module.icon}
                  tone={module.tone}
                  label={module.cardLabel}
                  value={peso(figures.amount)}
                  badge={
                    <DeltaBadge current={figures.amount} previous={figures.previous_amount} />
                  }
                  footnote={`${plural(figures.records, "record")} · ${monthLabel}`}
                />
              );
            })}

            <StatCard
              icon={Calculator}
              tone="violet"
              label="Total VAT"
              value={peso(vat.net)}
              badge={<DeltaBadge current={vat.net} previous={vat.previous_net} neutral />}
              footnote={`${vatIsPayable ? "Payable" : "Creditable"} · ${monthLabel}`}
            />
          </div>

          {/*
            Expanded withholding tax sits below the VAT cards rather than among
            them: it is remitted on 1601EQ/QAP, so folding it into that row would read
            as though it were part of the VAT position.
          */}
          <motion.div variants={item} className="min-w-0">
            <Card className="w-full min-w-0 p-5 shadow-sm ring-1 ring-slate-950/5">
              <div className="mb-5 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                <div className="flex items-center gap-2.5">
                  <span
                    className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                      TONES[EXPANDED.tone]
                    }`}
                  >
                    <EXPANDED.icon className="h-4 w-4" />
                  </span>
                  <h2 className="text-xs font-bold uppercase tracking-[0.1em] text-slate-700">
                    Expanded Withholding Tax
                    <span className="ml-1.5 font-medium normal-case text-slate-400">
                      (1601EQ · {monthLabel})
                    </span>
                  </h2>
                </div>

                {/*
                  Plain /records: the page picks its own record type in local
                  state, so a ?record_type= link would land on the purchase table.
                */}
                <Link
                  href="/records"
                  className="text-xs font-semibold text-[#0344a4] transition-colors duration-200 hover:text-[#023384]"
                >
                  View records
                </Link>
              </div>

              {/*
                lg: at hindi sm: -- sa lg pa lang lumalabas ang divider at side
                padding ng SummaryFigure, kaya bago nito ay magkasiksikan ang
                tatlo nang walang hati sa pagitan.
              */}
              <div className="grid grid-cols-1 gap-5 lg:grid-cols-3 lg:gap-0">
                {expandedFigures.map((figure, index) => (
                  <SummaryFigure key={figure.label} {...figure} divider={index > 0} />
                ))}
              </div>
            </Card>
          </motion.div>

          {/* Analytics */}
          <div className="grid grid-cols-1 gap-3 sm:gap-4 lg:gap-5 xl:grid-cols-2">
            <ChartCard
              icon={BarChart3}
              tone="blue"
              title="Monthly Transactions"
              note={`(${chartYear})`}
            >
              {hasTransactionData ? (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={transactions}
                    margin={{ top: 12, right: 8, left: -18, bottom: 0 }}
                    barGap={2}
                    barCategoryGap="20%"
                  >
                    <CartesianGrid strokeDasharray="3 3" stroke="#eef2f7" vertical={false} />
                    <XAxis dataKey="month" tickLine={false} {...axis} />
                    <YAxis allowDecimals={false} tickLine={false} {...axis} />
                    <Tooltip
                      content={<ChartTooltip format={count} year={chartYear} />}
                      cursor={{ fill: "#f8fafc" }}
                    />
                    {SERIES.map((module) => (
                      <Bar
                        key={module.key}
                        dataKey={module.key}
                        name={module.label}
                        fill={module.color}
                        radius={[3, 3, 0, 0]}
                        maxBarSize={14}
                      />
                    ))}
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <EmptyChart year={chartYear} />
              )}
            </ChartCard>

            <ChartCard
              icon={TrendingUpDown}
              tone="blue"
              title="Monthly Amount Trend"
              note={`(${chartYear})`}
            >
              {hasAmountData ? (
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={amounts} margin={{ top: 12, right: 12, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#eef2f7" vertical={false} />
                    <XAxis dataKey="month" tickLine={false} {...axis} />
                    <YAxis
                      tickFormatter={pesoAxis}
                      width={64}
                      tickLine={false}
                      tick={axis.tick}
                      stroke={axis.stroke}
                    />
                    <Tooltip content={<ChartTooltip format={peso} year={chartYear} />} />
                    {SERIES.map((module) => (
                      <Line
                        key={module.key}
                        type="monotone"
                        dataKey={module.key}
                        name={module.label}
                        stroke={module.color}
                        strokeWidth={2.5}
                        dot={{ r: 3, fill: module.color, strokeWidth: 0 }}
                        activeDot={{ r: 5 }}
                      />
                    ))}
                  </LineChart>
                </ResponsiveContainer>
              ) : (
                <EmptyChart year={chartYear} />
              )}
            </ChartCard>
          </div>

          {/* Monthly summary */}
          <motion.div variants={item} className="min-w-0">
            <Card className="w-full min-w-0 p-5 shadow-sm ring-1 ring-slate-950/5">
              <h2 className="mb-5 text-xs font-bold uppercase tracking-[0.1em] text-slate-700">
                Monthly Summary
                <span className="ml-1.5 font-medium normal-case text-slate-400">
                  ({monthLabel})
                </span>
              </h2>

              <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 lg:gap-0">
                {amountFigures.map((figure, index) => (
                  <SummaryFigure key={figure.label} {...figure} divider={index > 0} />
                ))}
              </div>

              <div className="my-5 border-t border-slate-100" />

              <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 lg:gap-0">
                {vatFigures.map((figure, index) => (
                  <SummaryFigure key={figure.label} {...figure} divider={index > 0} />
                ))}
              </div>
            </Card>
          </motion.div>

          {/* Importation analytics stay on the dashboard */}
          <motion.div variants={item} className="min-w-0">
            <Card className="w-full min-w-0 overflow-hidden shadow-sm ring-1 ring-slate-950/5">
              <div className="flex items-center justify-between px-5 py-4">
                <h2 className="text-xs font-bold uppercase tracking-[0.1em] text-slate-700">
                  Recent Importation Entries
                </h2>
                <Link
                  href={`/importation?tax_month=${selectedMonth}`}
                  className="text-xs font-semibold text-[#0344a4] transition-colors duration-200 hover:text-[#023384]"
                >
                  View All
                </Link>
              </div>

              {/* Ang table lang ang pinapayagang mag-scroll pahalang, hindi ang page. */}
              <div className="w-full overflow-x-auto">
                <table className="w-full min-w-[860px] text-sm">
                  <thead>
                    <tr className="border-y border-slate-100 bg-slate-50/60 text-[10px] uppercase tracking-[0.08em] text-slate-500">
                      <th className="px-5 py-3 text-left font-bold">Import Entry No.</th>
                      <th className="px-5 py-3 text-left font-bold">Name of Seller</th>
                      <th className="px-5 py-3 text-left font-bold">Country of Origin</th>
                      <th className="px-5 py-3 text-left font-bold">Tax Month</th>
                      <th className="px-5 py-3 text-right font-bold">Taxable Goods</th>
                      <th className="px-5 py-3 text-right font-bold">VAT</th>
                      <th className="px-5 py-3 text-left font-bold">Date Added</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recent.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-5 py-10 text-center text-sm text-slate-500">
                          No importation entries for {monthLabel}.
                        </td>
                      </tr>
                    ) : (
                      recent.map((entry, index) => (
                        <motion.tr
                          key={entry.id}
                          initial={{ opacity: 0 }}
                          animate={{ opacity: 1 }}
                          transition={{ duration: 0.2, delay: index * 0.04 }}
                          className="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/70"
                        >
                          <td className="whitespace-nowrap px-5 py-3.5 font-semibold text-slate-900">
                            {entry.import_entry_no}
                          </td>
                          <td className="px-5 py-3.5 text-slate-700">{entry.supplier}</td>
                          <td className="whitespace-nowrap px-5 py-3.5 text-slate-700">
                            <span className="mr-1.5">{flagFor(entry.country)}</span>
                            {entry.country}
                          </td>
                          <td className="whitespace-nowrap px-5 py-3.5 text-slate-600">
                            {entry.tax_month}
                          </td>
                          <td className="whitespace-nowrap px-5 py-3.5 text-right font-medium tabular-nums text-slate-900">
                            {peso(entry.taxable_goods)}
                          </td>
                          <td className="whitespace-nowrap px-5 py-3.5 text-right font-medium tabular-nums text-slate-900">
                            {peso(entry.vat_payable)}
                          </td>
                          <td className="whitespace-nowrap px-5 py-3.5 text-slate-500">
                            {entry.created_at}
                          </td>
                        </motion.tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </Card>
          </motion.div>
        </motion.div>
      </motion.div>
    </>
  );
}

Dashboard.layout = (page) => <MainLayout>{page}</MainLayout>;
