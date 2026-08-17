<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['tin', 'name']);

        $supplierList = Supplier::query()
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

        return Inertia::render('ManageSupplier', [
            'supplierList' => $supplierList,
            'filters' => [
                'tin' => $filters['tin'] ?? '',
                'name' => $filters['name'] ?? '',
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tin'  => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:60'],
            'addr' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
        ]);

        try {
            Supplier::create([
                'tin'  => $this->formatTin($validated['tin']),
                'name' => strtoupper(trim($validated['name'])),
                'addr' => strtoupper(trim($validated['addr'])),
                'city' => strtoupper(trim($validated['city'])),
            ]);

            return redirect()->back()->with('success', 'Supplier created successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'tin'  => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:60'],
            'addr' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
        ]);

        try {
            $supplier = Supplier::findOrFail($id);
            $supplier->update([
                'tin'  => $this->formatTin($validated['tin']),
                'name' => strtoupper(trim($validated['name'])),
                'addr' => strtoupper(trim($validated['addr'])),
                'city' => strtoupper(trim($validated['city'])),
            ]);

            return redirect()->back()->with('success', 'Supplier updated successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $supplier = Supplier::findOrFail($id);
            $supplier->delete();

            return redirect()->back()->with('success', 'Supplier deleted successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
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
