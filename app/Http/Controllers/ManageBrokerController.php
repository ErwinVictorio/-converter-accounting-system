<?php

namespace App\Http\Controllers;

use App\Models\Brokers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ManageBrokerController extends Controller
{
    public function index()
    {
        $brokerList = Brokers::select('tin_number', 'broker_name', 'id')
            ->orderBy('broker_name')
            ->orderBy('id')
            ->get();

        return Inertia::render('ManageBrokers', [
            'brokerList' => $brokerList,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'broker_name' => 'required',
            'tin'         => 'nullable',
        ]);

        $this->rejectInvalidOrDuplicateTin($validated['tin'] ?? null);

        try {
            Brokers::create([
                'broker_name' => $validated['broker_name'],
                'tin_number'  => $validated['tin'],
            ]);

            return redirect()->back()->with('success', 'Broker created successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'broker_name' => 'required',
            'tin'         => 'nullable',
        ]);

        $broker = Brokers::findOrFail($id);
        $this->rejectInvalidOrDuplicateTin($validated['tin'] ?? null, $broker->id);

        try {
            $broker->update([
                'broker_name' => $validated['broker_name'],
                'tin_number'  => $validated['tin'],
            ]);

            return redirect()->back()->with('success', 'Broker updated successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    public function destroy(string $id)
    {
        try {
            $broker = Brokers::findOrFail($id);
            $broker->delete();

            return redirect()->back()->with('success', 'Broker deleted successfully!');
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    private function rejectInvalidOrDuplicateTin(?string $tin, ?int $ignoreId = null): void
    {
        $baseTin = $this->baseTin($tin);

        if ($baseTin === '') {
            return;
        }

        if (strlen($baseTin) !== 9 || $baseTin === '000000000') {
            throw ValidationException::withMessages([
                'tin' => 'Broker TIN must contain a valid first 9 digits and cannot be 000000000.',
            ]);
        }

        $duplicate = Brokers::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->first(fn (Brokers $broker) => $this->baseTin($broker->tin_number) === $baseTin);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'tin' => 'TIN already exists for another broker.',
            ]);
        }
    }

    private function baseTin(?string $value): string
    {
        return substr(preg_replace('/\D/', '', (string) $value), 0, 9);
    }
}
