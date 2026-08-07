<?php

namespace App\Http\Controllers;

use App\Models\RecordEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecordEntryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $records = RecordEntry::query()
            ->when($search, function ($query, $search) {
                $query->where('registered_name', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%")
                    ->orWhere('supplier_address', 'like', "%{$search}%");
            })
            ->latest('updated_at')
            ->paginate(10) // 10 items bawat page
            ->withQueryString();

        return Inertia::render('RecordEntry', [
            'recordList' => $records,
            'filters' => [
                'search' => $search ?? '',
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {


        $validated = $request->validate([
            "registeredName" => 'required',
            "supplierName" => 'required',
            "supplierAddress" => 'required',
            "grossPurchase" => 'required',
            "exemptPurchase" => 'required',
        ]);


        try {
            RecordEntry::create([
                'resgister_name' => $validated['registeredName'],
                'supplier_name' => $validated['supplierName'],
                'supplier_address' => $validated['supplierAddress'],
                'amount_of_gross_purchase' => $validated['grossPurchase'],
                'exempt_purchase' => $validated['exemptPurchase'],
            ]);


            return redirect()->back()->with('success', 'New Record is successfully Added');
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
