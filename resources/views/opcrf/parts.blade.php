@extends('layouts.dashboard')

@section('title', 'Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Opcrf</h1>
        <p>Each Part of the OPCRF opens on its own schedule. A locked Part is still listed, with when it opens — nothing here is hidden from you.</p>
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