<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $sqlPath = base_path('Db/customers.sql');

        if (! File::exists($sqlPath)) {
            $this->command?->warn('Db/customers.sql was not found. Customer seeding skipped.');
            return;
        }

        $sql = File::get($sqlPath);
        preg_match_all('/\((\d+),\s*.*?\)(?=,|\;)/s', $sql, $matches);

        foreach ($matches[0] as $rowSql) {
            $values = $this->parseSqlTuple($rowSql);

            if (count($values) < 14) {
                continue;
            }

            $name = $this->cleanValue($values[2] ?? '');
            $address = $this->cleanValue($values[4] ?? '');
            $tin = $this->formatTin($values[12] ?? '');

            if ($name === '' || $address === '') {
                continue;
            }

            [$addr, $city] = $this->splitAddressAndCity($address);

            Customer::updateOrCreate(
                [
                    'name_key' => Customer::normalizeName($name),
                ],
                [
                    'name' => mb_substr($name, 0, 300),
                    'tin' => $tin,
                    'addr' => mb_substr($addr, 0, 500),
                    'city' => mb_substr($city, 0, 100),
                ]
            );
        }
    }

    private function parseSqlTuple(string $rowSql): array
    {
        $tuple = trim($rowSql);
        $tuple = trim($tuple, '()');

        return str_getcsv($tuple, ',', "'", '\\');
    }

    private function cleanValue(?string $value): string
    {
        $value = (string) $value;
        $value = str_replace(["\\'", '\r\n', '\n', '\r'], ["'", ' ', ' ', ' '], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return strtoupper(trim($value));
    }

    private function splitAddressAndCity(string $address): array
    {
        $address = $this->cleanValue($address);
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));

        if (count($parts) >= 2 && strlen($parts[count($parts) - 1]) <= 100) {
            return [
                implode(' ', array_slice($parts, 0, -1)),
                $parts[count($parts) - 1],
            ];
        }

        return [$address, ''];
    }

    private function formatTin(?string $value): string
    {
        $digits = substr(preg_replace('/\D/', '', (string) $value), 0, 12);

        if (strlen($digits) > 9 && strlen($digits) < 12) {
            $digits = str_pad($digits, 12, '0');
        }

        if (strlen($digits) === 12) {
            return substr($digits, 0, 3) . '-' .
                substr($digits, 3, 3) . '-' .
                substr($digits, 6, 3) . '-' .
                substr($digits, 9, 3);
        }

        if (strlen($digits) === 9) {
            return substr($digits, 0, 3) . '-' .
                substr($digits, 3, 3) . '-' .
                substr($digits, 6, 3);
        }

        return $digits;
    }
}
