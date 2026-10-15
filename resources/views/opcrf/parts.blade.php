@extends('layouts.dashboard')

@section('title', 'Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Opcrf</h1>
        <p>Part 1 has its own schedule. Parts 2, 3, and 4 open together during the end-of-year term. Submitted work remains saved and available after the deadline.</p>
    </div>

    {{-- THE PARTS GRID: one card per OPCRF Part, state computed on the
         server from the superadmin's schedule (App\Support\OpcrfAccess). The
         Access button is only rendered when the server says the Part can be
         opened — and the Part route checks again when it is followed. --}}
    <livewire:opcrf-parts />

    {{-- SUBMISSIONS TABLE: one Livewire component — new submissions
         appear without a reload, and approved submissions with a stored
         workbook offer their download. --}}
    <livewire:opcrf-movs />
@endsection