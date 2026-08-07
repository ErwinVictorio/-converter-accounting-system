<?php

namespace App\Http\Controllers;

use App\Models\RecordEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DatFileController extends Controller
{
    public function index()
    {

        return Inertia::render('GenerateDatFile');
    }

    public function download(Request $request)
    {
        $request->validate([
            'startDate' => 'required|date',
            'endDate'   => 'required|date|after_or_equal:startDate',
        ]);



        $records = RecordEntry::whereBetween('created_at', [
            $request->startDate . ' 00:00:00',
            $request->endDate . ' 23:59:59'
        ])->get();

        if ($records->isEmpty()) {
            return redirect()->back()->with('error', 'No records found for the selected date range.');
        }

        $fileName = 'PURCHASES_' . $request->startDate . '_TO_' . $request->endDate . '.dat';

        return response()->streamDownload(function () use ($records) {
            $handle = fopen('php://output', 'w');

            foreach ($records as $record) {
                $name = $record->resgister_name ?? $record->registered_name ?? '';

                $line = implode(',', [
                    $name,
                    $record->supplier_name,
                    $record->supplier_address,
                    number_format((float)$record->amount_of_gross_purchase, 2, '.', ''),
                    number_format((float)$record->exempt_purchase, 2, '.', ''),
                ]) . "\r\n";

                fwrite($handle, $line);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
