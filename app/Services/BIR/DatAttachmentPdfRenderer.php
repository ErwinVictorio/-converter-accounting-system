<?php

namespace App\Services\BIR;

use RuntimeException;

class DatAttachmentPdfRenderer
{
    public function render(array $report): string
    {
        $reactPdf = $this->renderWithReactPdf($report);

        if ($reactPdf !== null) {
            return $reactPdf;
        }

        return $this->renderFallbackPdf($report);
    }

    private function renderWithReactPdf(array $report): ?string
    {
        $node = $this->findExecutable('node');

        if ($node === null) {
            return null;
        }

        $script = base_path('scripts/render-dat-attachment-pdf.mjs');

        if (! is_file($script)) {
            throw new RuntimeException('DAT attachment PDF renderer script is missing.');
        }

        $input = tempnam(sys_get_temp_dir(), 'dat-report-');
        $output = tempnam(sys_get_temp_dir(), 'dat-report-') . '.pdf';

        file_put_contents($input, json_encode($report, JSON_THROW_ON_ERROR));

        $command = '"' . $node . '" "' . $script . '" "' . $input . '" "' . $output . '"';
        exec($command . ' 2>&1', $lines, $exitCode);

        @unlink($input);

        if ($exitCode !== 0) {
            @unlink($output);

            if ($this->isMissingReactPdf($lines)) {
                return null;
            }

            throw new RuntimeException('DAT attachment PDF rendering failed: ' . implode(' ', $lines));
        }

        $content = file_get_contents($output);
        @unlink($output);

        if ($content === false || $content === '') {
            throw new RuntimeException('DAT attachment PDF renderer produced an empty file.');
        }

        return $content;
    }

    private function renderFallbackPdf(array $report): string
    {
        $lines = $this->fallbackLines($report);
        $content = "BT\n/F1 8 Tf\n36 560 Td\n";

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -11 Td\n";
            }

            $content .= '(' . $this->pdfText($line) . ") Tj\n";
        }

        $content .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function fallbackLines(array $report): array
    {
        $company = $report['company'] ?? [];
        $lines = [
            (string) ($report['title'] ?? 'DAT ATTACHMENT REPORT'),
            (string) ($report['subtitle'] ?? 'RECONCILIATION OF LISTING FOR ENFORCEMENT'),
            'TIN : ' . ($company['tin'] ?? ''),
            "OWNER'S NAME: " . ($company['name'] ?? ''),
            "OWNER'S TRADE NAME : " . ($company['trade_name'] ?? ''),
            "OWNER'S ADDRESS: " . ($company['address'] ?? ''),
            '',
            implode(' | ', $report['columns'] ?? []),
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(' | ', array_map(fn ($value) => (string) $value, $row));
        }

        if (($report['totals'] ?? []) !== []) {
            $lines[] = implode(' | ', array_map(fn ($value) => (string) $value, $report['totals']));
        }

        $lines[] = 'END OF REPORT';

        return array_map(fn (string $line) => substr($line, 0, 180), $lines);
    }

    private function findExecutable(string $name): ?string
    {
        $command = DIRECTORY_SEPARATOR === '\\' ? "where {$name}" : "command -v {$name}";
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        exec($command . " 2>{$nullDevice}", $output, $exitCode);

        if ($exitCode !== 0 || ($output[0] ?? '') === '') {
            return null;
        }

        return trim($output[0]);
    }

    private function isMissingReactPdf(array $lines): bool
    {
        $output = implode(' ', $lines);

        return str_contains($output, 'ERR_MODULE_NOT_FOUND')
            || str_contains($output, 'Cannot find package')
            || str_contains($output, '@react-pdf/renderer');
    }

    private function pdfText(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
