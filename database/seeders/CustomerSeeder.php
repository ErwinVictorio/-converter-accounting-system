<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\SalesVatInput;
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

        $seeded = 0;

        foreach ($matches[0] as $rowSql) {
            $values = $this->parseSqlTuple($rowSql);

            if (count($values) < 14) {
                continue;
            }

            $name = $this->birText($values[2] ?? '');
            $address = $this->cleanValue($values[4] ?? '');
            $tin = $this->formatTin($values[12] ?? '');

            if ($name === '' || $address === '') {
                continue;
            }

            [$addr, $city] = $this->splitAddressAndCity($address);
            $nameKey = Customer::normalizeName($name);
            $payload = [
                'name' => mb_substr($name, 0, 300),
                'tin' => $tin,
                'addr' => mb_substr($addr, 0, 500),
                'city' => mb_substr($city, 0, 100),
            ];

            $updated = Customer::query()
                ->where('name_key', $nameKey)
                ->update($payload);

            if ($updated === 0) {
                Customer::query()->create([
                    'name_key' => $nameKey,
                    ...$payload,
                ]);
            }

            $seeded++;
        }

        $synced = $this->syncSalesRows();

        $this->command?->info("Customer seed loaded {$seeded} customers from Db/customers.sql.");
        $this->command?->info("Existing sales rows synced: {$synced} sales rows matched customer TIN/address/city.");
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
                $this->birText(implode(' ', array_slice($parts, 0, -1))),
                $this->birText($parts[count($parts) - 1]),
            ];
        }

        return [$this->birText($address), ''];
    }

    private function syncSalesRows(): int
    {
        $synced = 0;

        Customer::query()
            ->select(['id', 'tin', 'name', 'name_key', 'addr', 'city'])
            ->orderBy('id')
            ->chunkById(500, function ($customers) use (&$synced): void {
                foreach ($customers as $customer) {
                    $synced += SalesVatInput::query()
                        ->whereRaw($this->salesCustomerNameKeySql() . ' = ?', [$customer->name_key])
                        ->update([
                            'customer_tin' => $customer->tin,
                            'customer_type' => 'company',
                            'company_name' => $customer->name,
                            'last_name' => null,
                            'first_name' => null,
                            'middle_name' => null,
                            'address1' => $customer->addr,
                            'address2' => $customer->city,
                        ]);
                }
            });

        return $synced;
    }

    private function salesCustomerNameKeySql(): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(customer_name), '&', 'AND'), ' ', ''), '.', ''), ',', ''), '-', ''), '/', ''), '(', ''), ')', '')";
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

    private function birText(?string $value): string
    {
        $value = $this->cleanValue($value);
        $value = str_replace('&', ' AND ', $value);
        $value = str_replace(',', ' ', $value);
        $value = preg_replace('/[^A-Z0-9 .#\/\-\(\)]/', ' ', $value);

        return preg_replace('/\s+/', ' ', trim($value));
    }
}
