@extends('layouts.dashboard')

@section('title', 'Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Opcrf</h1>
        <p>Your OPCRF submissions — everything you've turned in, newest first.</p>
    </div>

    {{-- SUBMISSIONS TABLE: one Livewire component — the table refreshes
         when MOVs change, and the Actions column opens the MOVs manager
         modal per row. The manual submission form was removed: staff now
         submit from the dashboard's OPCRF Template card. --}}
    <livewire:opcrf-movs />
@endsection
