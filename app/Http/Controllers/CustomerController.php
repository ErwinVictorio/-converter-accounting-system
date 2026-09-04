<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SalesVatInput;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only(['tin', 'name']);

        $customerList = Customer::query()
            ->select('id', 'tin', 'name', 'addr', 'city')
            ->when($filters['tin'] ?? null, function ($query, string $tin) {
                $query->where('tin', 'like', '%' . $tin . '%');
            })
            ->when($filters['name'] ?? null, function ($query, string $name) {
                $query->where('name', 'like', '%' . $name . '%');
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('ManageCustomer', [
            'customerList' => $customerList,
            'filters' => [
                'tin' => $filters['tin'] ?? '',
                'name' => $filters['name'] ?? '',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateCustomer($request);
        $this->rejectInvalidOrDuplicateTin($validated['tin']);

        try {
            $customer = Customer::create($this->payload($validated));
            $this->syncSalesRows($customer);

            return redirect()->back()->with('success', 'Customer created successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function update(Request $request, string $id)
    {
        $validated = $this->validateCustomer($request);

        $customer = Customer::findOrFail($id);
        $this->rejectInvalidOrDuplicateTin($validated['tin'], $customer->id);

        try {
            $customer->update($this->payload($validated));
            $this->syncSalesRows($customer);

            return redirect()->back()->with('success', 'Customer updated successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function destroy(string $id)
    {
        try {
            $customer = Customer::findOrFail($id);
            $customer->delete();

            return redirect()->back()->with('success', 'Customer deleted successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    private function validateCustomer(Request $request): array
    {
        return $request->validate([
            'tin' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:300'],
            'addr' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
        ]);
    }

    private function payload(array $validated): array
    {
        $name = $this->birText($validated['name']);

        return [
            'tin' => $this->formatTin($validated['tin']),
            'name' => $name,
            'name_key' => Customer::normalizeName($name),
            'addr' => $this->birText($validated['addr']),
            'city' => $this->birText($validated['city']),
        ];
    }

    private function syncSalesRows(Customer $customer): void
    {
        SalesVatInput::query()
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

    private function rejectInvalidOrDuplicateTin(?string $tin, ?int $ignoreId = null): void
    {
        $baseTin = $this->baseTin($tin);

        if (strlen($baseTin) !== 9 || $baseTin === '000000000') {
            throw ValidationException::withMessages([
                'tin' => 'Customer TIN must contain a valid first 9 digits and cannot be 000000000.',
            ]);
        }

        $duplicate = Customer::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->first(fn (Customer $customer) => $this->baseTin($customer->tin) === $baseTin);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'tin' => 'TIN already exists for another customer.',
            ]);
        }
    }

    private function baseTin(?string $value): string
    {
        return substr(preg_replace('/\D/', '', (string) $value), 0, 9);
    }

    private function birText(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = str_replace('&', ' AND ', $value);
        $value = str_replace(',', ' ', $value);
        $value = preg_replace('/[^A-Z0-9 .#\/\-\(\)]/', ' ', $value);

        return preg_replace('/\s+/', ' ', trim($value));
    }
}
