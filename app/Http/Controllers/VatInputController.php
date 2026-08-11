<?php

namespace App\Http\Controllers;

use App\Imports\VatInputImport;
use App\Models\VatInput;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class VatInputController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');

        $vatInputs = VatInput::query()
            // Search Filter (Supplier Name or TIN)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('supplier_name', 'LIKE', "%{$search}%")
                        ->orWhere('tin_number', 'LIKE', "%{$search}%");
                });
            })
            // I-sort mula sa pinakahuling na-update o id
            ->orderBy('id', 'asc')
            // Standard Pagination
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('RecordEntry', [
            'vatInputs' => $vatInputs,
            'filters'   => [
                'search' => $search,
            ],
        ]);
    }
    public function import(Request $request)
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $file = $request->file('excel_file');

            // Ipasa ang $file at ang original filename nito para malaman ng package ang file extension
            Excel::import(new VatInputImport, $file, null, \Maatwebsite\Excel\Excel::XLSX);
            // O mas simple:
            // Excel::import(new VatInputImport, $file);

            return back()->with('success', 'VAT Input Report successfully imported!');
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }
}
