<?php

namespace App\Imports;

use Illuminate\Validation\ValidationException;

/** Shared amount interpretation for Sales import and its replacement preflight. */
class SalesAmountNormalizer
{
    public static function zeroRatedColumn(array $headings): ?int
    {
        foreach ($headings as $index => $heading) {
            if (preg_replace('/[^a-z0-9]/', '', strtolower((string) $heading)) === 'zeroratedsales') {
                return $index;
            }
        }

        return null;
    }

    public static function summary(array $data, ?int $zeroRatedColumn): array
    {
        $raw = [
            'exempt_sales' => 0,
            'zero_rated_sales' => $zeroRatedColumn === null ? null : ($data[$zeroRatedColumn] ?? null),
            'taxable_net_of_vat' => $data[13] ?? null,
            'output_vat' => $data[12] ?? null,
            'net_amount' => $data[11] ?? null,
            'gross_amount' => $data[8] ?? null,
        ];

        $zeroRated = self::isNumber($raw['output_vat']) && self::number($raw['output_vat']) == 0;
        if (! $zeroRated) {
            return self::normalize($raw) + ['s_zero_rated' => false];
        }

        // Validate the original cells before replacing the taxable classification.
        $errors = [];
        foreach ($raw as $field => $value) {
            if (! self::isNumber($value)) {
                $errors[] = "{$field} must be numeric. Use 0 when there is no amount.";
            }
        }
        $net = self::number($raw['net_amount']);
        $explicit = self::number($raw['zero_rated_sales']);
        if ($net == 0 && collect($raw)->contains(fn ($value) => self::number($value) != 0)) {
            $errors[] = 'Zero-rated sales require the actual full net_amount. Do not leave the net amount blank or zero.';
        }
        if ($explicit != 0 && round($explicit - $net, 2) != 0) {
            $errors[] = 'Zero VAT classifies the full net_amount as zero-rated; Zero Rated Sales must match that amount or be left blank.';
        }
        $raw['zero_rated_sales'] = $net;
        $raw['taxable_net_of_vat'] = 0;
        $raw['output_vat'] = 0;
        $result = self::normalize($raw);
        $result['errors'] = array_values(array_unique([...$errors, ...$result['errors']]));

        return $result + ['s_zero_rated' => true];
    }

    public static function hasSummaryAmount(array $data, ?int $zeroRatedColumn): bool
    {
        $result = self::summary($data, $zeroRatedColumn);

        return collect($result['amounts'])
            ->except(['gross_amount'])->contains(fn ($amount) => $amount != 0)
            || $result['errors'] !== [];
    }

    public static function bir(array $data): array
    {
        $result = self::normalize([
            'exempt_sales' => $data[7] ?? null,
            'zero_rated_sales' => $data[8] ?? null,
            'taxable_net_of_vat' => $data[9] ?? null,
            'output_vat' => $data[11] ?? null,
            'net_amount' => $data[12] ?? null,
            'gross_amount' => $data[13] ?? null,
        ]);
        $amounts = $result['amounts'];

        return $result + ['s_zero_rated' => $result['errors'] === []
            && $amounts['zero_rated_sales'] != 0
            && $amounts['exempt_sales'] == 0
            && $amounts['taxable_net_of_vat'] == 0];
    }

    public static function hasBirAmount(array $data): bool
    {
        foreach ([7, 8, 9, 11, 12] as $index) {
            if (trim((string) ($data[$index] ?? '')) !== '' && self::number($data[$index]) != 0) {
                return true;
            }
            if (! self::isNumber($data[$index] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(array $raw): array
    {
        $amounts = array_map(self::number(...), $raw);
        $errors = [];
        foreach ($raw as $field => $value) {
            if (! self::isNumber($value)) {
                $errors[] = "{$field} must be numeric. Use 0 when there is no amount.";
            }
        }

        // Newly detected exempt-only bucket rows also need an explicit net amount;
        // otherwise consolidation would incorrectly produce negative taxable sales.
        if ($amounts['exempt_sales'] != 0 && $amounts['zero_rated_sales'] == 0
            && $amounts['taxable_net_of_vat'] == 0 && $amounts['net_amount'] == 0) {
            $errors[] = 'Exempt-only sales require the actual full net_amount. Do not leave the net amount blank or zero.';
        }

        // Only explicit nonzero zero-rated buckets opt into these consistency rules.
        // Ordinary taxable/exempt imports keep their existing amount interpretation.
        if ($amounts['zero_rated_sales'] != 0 && $errors === []) {
            $nonTaxable = $amounts['zero_rated_sales'] + $amounts['exempt_sales'];
            if ($amounts['taxable_net_of_vat'] == 0) {
                if (round($amounts['net_amount'] - $nonTaxable, 2) != 0) {
                    $errors[] = 'Zero-rated classification conflicts with net_amount: supply the full net amount matching the exempt and zero-rated buckets, or explicitly supply the taxable portion.';
                } else {
                    $amounts['output_vat'] = 0.0;
                    $amounts['taxable_net_of_vat'] = 0.0;
                }
            } elseif (abs(round($amounts['net_amount'] - $nonTaxable - $amounts['taxable_net_of_vat'] - $amounts['output_vat'], 2)) > 0.01) {
                $errors[] = 'Mixed zero-rated sales amounts conflict: net_amount must equal exempt_sales + zero_rated_sales + taxable_net_of_vat + output_vat.';
            }
        }

        return ['amounts' => $amounts, 'errors' => $errors];
    }

    public static function validated(array $result, int $rowNumber): array
    {
        if ($result['errors'] !== []) {
            throw ValidationException::withMessages([
                'file' => array_map(fn ($error) => "Row {$rowNumber}: {$error}", $result['errors']),
            ]);
        }

        return $result['amounts'] + ['s_zero_rated' => $result['s_zero_rated'] ?? false];
    }

    private static function isNumber(mixed $value): bool
    {
        return $value === null || trim((string) $value) === ''
            || is_numeric(self::numericText($value));
    }

    private static function number(mixed $value): float
    {
        return (float) self::numericText($value);
    }

    private static function numericText(mixed $value): string
    {
        // Keep the existing accounting-parentheses magnitude convention; CM is
        // subtracted by document type during consolidation.
        return str_replace(',', '', preg_replace('/^\((.*)\)$/', '$1', trim((string) $value)));
    }
}
