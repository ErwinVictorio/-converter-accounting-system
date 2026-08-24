<?php

namespace App\Http\Controllers;

use App\Services\BIR\DashboardMetrics;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class Dashboard extends Controller
{
    /**
     * BIR data & DAT file automation overview for one tax month, across Sales,
     * Purchases, Importation and Expanded Withholding Tax.
     *
     * All aggregation lives in DashboardMetrics; this only resolves which month to
     * report on. Nothing here writes, and DAT generation is untouched.
     */
    public function index(Request $request, DashboardMetrics $metrics)
    {
        $selected = $this->resolveTaxMonth($request->input('tax_month'));

        return Inertia::render('Dashboard', $metrics->forMonth($selected));
    }

    /**
     * The reporting month defaults to the one just closed, matching the
     * importation entry form.
     */
    private function resolveTaxMonth(?string $value): Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return Carbon::now()->startOfMonth()->subMonth();
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $value .= '-01';
        }

        try {
            return Carbon::parse($value)->startOfMonth();
        } catch (\Throwable) {
            return Carbon::now()->startOfMonth()->subMonth();
        }
    }
}
