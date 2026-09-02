<?php

namespace App\Http\Controllers;

use App\Imports\ImportationEntryImport;
use App\Models\ImportationEntry;
use App\Models\VatInput;
use App\Services\ImportationEntryWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ImportationController extends Controller
{
    public function __construct(private ImportationEntryWriter $entries) {}

    /**
     * The Importation screen now has two entry paths: manual keying and Excel
     * upload. Stored rows stay under Record > Importation Records.
     */
    public function index(Request $request)
    {
        return Inertia::render('Importation');
    }

    public function records(Request $request)
    {
        $taxMonth = $request->input('tax_month');
        $normalizedMonth = $taxMonth ? $this->entries->normalizeTaxMonth($taxMonth) : null;
        $search = trim((string) $request->input('search', ''));
        $searchTerms = collect([$search, str_replace(',', '', $search)])
            ->filter()
            ->unique()
            ->values();

        $entries = ImportationEntry::query()
            ->when($normalizedMonth, function ($query, string $month) {
                $query->whereDate('tax_month', $month);
            })
            ->when($searchTerms->isNotEmpty(), function ($query) use ($searchTerms) {
                $columns = [
                    'sequence_number',
                    'tax_month',
                    'import_entry_no',
                    'assessment_date',
                    'supplier',
                    'importation_date',
                    'country',
                    'total_landed_cost',
                    'dutiable_value',
                    'charges',
                    'exempt',
                    'taxable_goods',
                    'vat_rate',
                    'vat_payable',
                    'or_number',
                    'payment_date',
                ];

                $query->where(function ($q) use ($columns, $searchTerms) {
                    foreach ($searchTerms as $term) {
                        foreach ($columns as $column) {
                            $q->orWhere($column, 'LIKE', "%{$term}%");
                        }
                    }
                });
            })
            ->orderBy('supplier')
            ->orderByDesc('tax_month')
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $months = ImportationEntry::query()
            ->orderByDesc('tax_month')
            ->pluck('tax_month')
            ->groupBy(fn ($month) => Carbon::parse($month)->format('Y-m'))
            ->map(fn ($group, string $value) => [
                'value' => $value,
                'label' => Carbon::parse($value.'-01')->format('F Y'),
                'records_count' => $group->count(),
            ])
            ->values();

        return Inertia::render('Records/ImportationRecords', [
            'entries' => $entries,
            'months' => $months,
            'filters' => [
                'tax_month' => $taxMonth ? Carbon::parse($normalizedMonth)->format('Y-m') : '',
                'search' => $search,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'tax_month' => $this->entries->normalizeTaxMonth($request->input('tax_month')),
        ]);

        try {
            DB::transaction(function () use ($request) {
                $this->entries->create($request->all());
            });

            return redirect()->back()->with('success', 'Importation entry created successfully!');
        } catch (ValidationException $th) {
            throw $th;
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function upload(Request $request)
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $import = new ImportationEntryImport($this->entries);

            DB::transaction(function () use ($request, $import) {
                Excel::import($import, $request->file('excel_file'));
            });

            return redirect()->back()->with(
                'success',
                "Importation upload completed. {$import->importedCount()} row(s) imported."
            );
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', 'Importation upload failed: '.$th->getMessage());
        }
    }

    public function template()
    {
        $path = base_path('Docs/Importaion/Importation_Upload_Template_Updated.xlsx');

        abort_unless(is_file($path), 404);

        return response()->download($path, 'Importation_Upload_Template_Updated.xlsx');
    }

    public function update(Request $request, ImportationEntry $importationEntry)
    {
        $request->merge([
            'tax_month' => $this->entries->normalizeTaxMonth($request->input('tax_month')),
        ]);

        try {
            DB::transaction(function () use ($request, $importationEntry) {
                $this->entries->update($importationEntry, $request->all());
            });

            return redirect()->back()->with('success', 'Importation entry updated successfully!');
        } catch (ValidationException $th) {
            throw $th;
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function destroy(ImportationEntry $importationEntry)
    {
        try {
            DB::transaction(function () use ($importationEntry) {
                if ($importationEntry->vat_input_id) {
                    VatInput::query()->whereKey($importationEntry->vat_input_id)->delete();
                }

                $importationEntry->delete();
            });

            return redirect()->back()->with('success', 'Importation entry deleted successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }
}
