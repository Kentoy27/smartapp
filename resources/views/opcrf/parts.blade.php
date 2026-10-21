@extends('layouts.dashboard')

@section('title', 'Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Opcrf</h1>
        @if (auth()->user()?->canReviewAllOpcrfSubmissions())
            <p>Review submitted OPCRFs from School Heads, organized by school and district.</p>
        @else
            <p>Part 1 has its own schedule. Parts 2, 3, and 4 open together during the end-of-year term. Submitted work remains saved and available after the deadline.</p>
        @endif
    </div>

    {{-- THE PARTS GRID: one card per OPCRF Part, state computed on the
         server from the superadmin's schedule (App\Support\OpcrfAccess). The
         Access button is only rendered when the server says the Part can be
         opened — and the Part route checks again when it is followed. --}}
    <livewire:opcrf-parts />

    @if (auth()->user()?->canReviewAllOpcrfSubmissions())
        <livewire:opcrf-viewer-submissions />
    @else
        {{-- The School Head's own submissions and revision workflow. --}}
        <livewire:opcrf-movs />
    @endif
@endsection