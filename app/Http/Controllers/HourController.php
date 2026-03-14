<?php

namespace App\Http\Controllers;

use App\Models\Hour;
use Illuminate\Http\Request;

class HourController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $hours = Hour::all();

        return view('hours.index', [
            'hours' => $hours
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('hours.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        Hour::create([
            'user_id' => 1, // TODO: sostituire con auth()->id() quando implementi l'autenticazione
            'date' => request('date'),
            'hours' => request('hours'),
            'client_id' => request('client_id'),
            'notes' => request('notes'),
            'billable' => request()->has('billable'),
        ]);

        return redirect('/hours');
    }

    /**
     * Display the specified resource.
     */
    public function show(Hour $hour)
    {
        return view('hours.show', [
            'hour' => $hour
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Hour $hour)
    {
        return view('hours.edit', [
            'hour' => $hour
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Hour $hour)
    {
        $hour->update([
            'date' => request('date'),
            'hours' => request('hours'),
            'client_id' => request('client_id'),
            'notes' => request('notes'),
            'billable' => request()->has('billable'),
        ]);

        return redirect("/hours/{$hour->id}");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Hour $hour)
    {
        $hour->delete();

        return redirect('/hours');
    }
}
