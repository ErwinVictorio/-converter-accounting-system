<?php

namespace App\Http\Controllers;

use App\Services\TemporaryPurchaseSupplierTotalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemporaryPurchaseSupplierTotalController extends Controller
{
    public function index()
    {
        return Inertia::render('Temporary/PurchaseSupplierTotals');
    }

    public function store(Request $request, TemporaryPurchaseSupplierTotalService $service): StreamedResponse
    {
        $validated = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        try {
            $report = $service->build($validated['excel_file']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'excel_file' => 'The supplier total report could not be generated. Check the workbook and try again.',
            ]);
        }

        $filename = 'PURCHASE_SUPPLIER_TOTALS_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($report) {
            try {
                IOFactory::createWriter($report, 'Xlsx')->save('php://output');
            } finally {
                $report->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
