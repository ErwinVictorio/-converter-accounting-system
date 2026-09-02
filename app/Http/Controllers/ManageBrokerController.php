<?php

namespace App\Http\Controllers;

use App\Models\Brokers;
use Illuminate\Http\Request;
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

        try {
            $broker = Brokers::findOrFail($id);
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
}
