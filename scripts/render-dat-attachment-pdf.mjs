import fs from "node:fs/promises";
import React from "react";
import ReactPDF, { Document, Page, StyleSheet, Text, View } from "@react-pdf/renderer";

const [, , inputPath, outputPath] = process.argv;

if (!inputPath || !outputPath) {
    throw new Error("Usage: node scripts/render-dat-attachment-pdf.mjs input.json output.pdf");
}

const report = JSON.parse(await fs.readFile(inputPath, "utf8"));

const styles = StyleSheet.create({
    page: {
        padding: 18,
        fontFamily: "Helvetica",
        fontSize: 6,
    },
    title: {
        fontSize: 12,
        fontWeight: 700,
        marginBottom: 4,
    },
    subtitle: {
        fontSize: 8,
        marginBottom: 14,
    },
    meta: {
        marginBottom: 2,
    },
    table: {
        borderTopWidth: 1,
        borderLeftWidth: 1,
        borderColor: "#555",
        marginTop: 12,
    },
    row: {
        flexDirection: "row",
    },
    cell: {
        borderRightWidth: 1,
        borderBottomWidth: 1,
        borderColor: "#555",
        padding: 3,
        flexGrow: 1,
        flexBasis: 0,
    },
    headerCell: {
        fontWeight: 700,
        backgroundColor: "#f1f5f9",
    },
    totalCell: {
        fontWeight: 700,
    },
    end: {
        marginTop: 10,
        fontSize: 7,
        fontWeight: 700,
    },
});

function Row({ children }) {
    return React.createElement(View, { style: styles.row }, children);
}

function Cell({ children, header = false, total = false }) {
    return React.createElement(
        View,
        { style: [styles.cell, header && styles.headerCell, total && styles.totalCell] },
        React.createElement(Text, null, String(children ?? ""))
    );
}

function AttachmentDocument({ report }) {
    const company = report.company || {};

    return React.createElement(
        Document,
        null,
        React.createElement(
            Page,
            { size: "LEGAL", orientation: "landscape", style: styles.page },
            React.createElement(Text, { style: styles.title }, report.title || "DAT ATTACHMENT REPORT"),
            React.createElement(Text, { style: styles.subtitle }, report.subtitle || ""),
            React.createElement(Text, { style: styles.meta }, `TIN : ${company.tin || ""}`),
            React.createElement(Text, { style: styles.meta }, `OWNER'S NAME: ${company.name || ""}`),
            React.createElement(Text, { style: styles.meta }, `OWNER'S TRADE NAME : ${company.trade_name || ""}`),
            React.createElement(Text, { style: styles.meta }, `OWNER'S ADDRESS: ${company.address || ""}`),
            React.createElement(
                View,
                { style: styles.table },
                React.createElement(
                    Row,
                    null,
                    ...(report.columns || []).map((column) => React.createElement(Cell, { key: column, header: true }, column))
                ),
                ...(report.rows || []).map((row, rowIndex) =>
                    React.createElement(
                        Row,
                        { key: `row-${rowIndex}` },
                        ...row.map((value, cellIndex) => React.createElement(Cell, { key: `cell-${cellIndex}` }, value))
                    )
                ),
                (report.totals || []).length > 0 &&
                    React.createElement(
                        Row,
                        null,
                        ...report.totals.map((value, cellIndex) =>
                            React.createElement(Cell, { key: `total-${cellIndex}`, total: true }, value)
                        )
                    )
            ),
            React.createElement(Text, { style: styles.end }, "END OF REPORT")
        )
    );
}

await ReactPDF.render(React.createElement(AttachmentDocument, { report }), outputPath);
