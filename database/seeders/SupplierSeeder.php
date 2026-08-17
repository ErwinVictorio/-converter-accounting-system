<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sqlPath = base_path('Db/suppliers.sql');

        if (! File::exists($sqlPath)) {
            $this->command?->warn('Db/suppliers.sql was not found. Supplier seeding skipped.');
            return;
        }

        $sql = File::get($sqlPath);
        preg_match_all('/\((\d+),\s*\'(?:\\\\\'|[^\'])*\'.*?\)(?=,|\;)/s', $sql, $matches);

        foreach ($matches[0] as $rowSql) {
            $values = $this->parseSqlTuple($rowSql);

            if (count($values) < 15) {
                continue;
            }

            $name = $this->cleanValue($values[1]);
            $address = $this->cleanValue($values[3]);
            $tin = $this->formatTin($values[10]);

            if ($name === '' || $address === '') {
                continue;
            }

            [$addr, $city] = $this->splitAddressAndCity($address);

            Supplier::updateOrCreate(
                [
                    'name' => $name,
                ],
                [
                    'tin' => $tin,
                    'addr' => $addr,
                    'city' => $city,
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

        $cityPatterns = [
            'QUEZON CITY',
            'Q.C.',
            'QC',
            'MAKATI CITY',
            'MAKATI',
            'CALOOCAN CITY',
            'CALOOCAN',
            'MANILA CITY',
            'MANILA',
            'MANDALUYONG CITY',
            'MANDALUYONG',
            'PASIG CITY',
            'PASIG',
            'TAGUIG CITY',
            'TAGUIG',
            'PARANAQUE CITY',
            'PARAÑAQUE CITY',
            'PARANAQUE',
            'PASAY CITY',
            'PASAY',
            'MUNTINLUPA CITY',
            'MUNTINLUPA',
            'MARIKINA CITY',
            'MARIKINA',
            'MALABON CITY',
            'MALABON',
            'NAVOTAS CITY',
            'NAVOTAS',
            'VALENZUELA CITY',
            'VALENZUELA',
            'SAN JUAN CITY',
            'SAN JUAN',
            'LAS PINAS CITY',
            'LAS PIÑAS CITY',
            'LAS PINAS',
            'CEBU CITY',
            'CEBU',
            'DAVAO CITY',
            'DAVAO',
            'HONG KONG',
            'HONGKONG',
            'KOWLOON',
        ];

        foreach ($cityPatterns as $city) {
            $pattern = '/(?:,\s*|\s+)(' . preg_quote($city, '/') . ')\.?$/u';

            if (preg_match($pattern, $address, $match)) {
                $addr = trim(preg_replace($pattern, '', $address) ?? '', " \t\n\r\0\x0B,.");
                $normalizedCity = $this->normalizeCity($match[1]);

                return [$addr !== '' ? $addr : $address, $normalizedCity];
            }
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

    private function normalizeCity(string $city): string
    {
        $city = strtoupper(trim($city, " \t\n\r\0\x0B."));

        return match ($city) {
            'Q.C.', 'QC' => 'QUEZON CITY',
            'HONGKONG' => 'HONG KONG',
            'PARAÑAQUE', 'PARAÑAQUE CITY' => 'PARANAQUE CITY',
            'LAS PIÑAS', 'LAS PIÑAS CITY' => 'LAS PINAS CITY',
            default => $city,
        };
    }
}
