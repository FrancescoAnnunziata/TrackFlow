<?php

namespace App\Http\Controllers;

use App\Http\Requests\HourRequest;
use App\Models\Hour;
use Illuminate\Auth\Access\Gate;
use Illuminate\Support\Facades\Auth;

class HourController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('hours.index', [
            'hours' => Auth::user()->hours,
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
    public function store(HourRequest $request)
    {
        $hour = Auth::user()->hours()->create([
            'date' => $request->date,
            'hours' => $request->hours,
            'notes' => $request->notes,
            'billable' => $request->boolean('billable'),
        ]);

        $hour->clients()->sync($request->client_ids);

        return redirect('/hours');
    }

    /**
     * Display the specified resource.
     */
    public function show(Hour $hour)
    {
        Gate::authorize('update', $hour);

        return view('hours.show', [
            'hour' => $hour,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Hour $hour)
    {
        Gate::authorize('update', $hour);

        return view('hours.edit', [
            'hour' => $hour,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(HourRequest $request, Hour $hour)
    {
        Gate::authorize('update', $hour);

        $hour->update([
            'date' => $request->date,
            'hours' => $request->hours,
            'notes' => $request->notes,
            'billable' => $request->boolean('billable'),
        ]);

        $hour->clients()->sync($request->client_ids);

        return redirect("/hours/{$hour->id}");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Hour $hour)
    {
        Gate::authorize('update', $hour);

        $hour->delete();

        return redirect('/hours');
    }
}
