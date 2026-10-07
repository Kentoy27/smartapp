@extends('layouts.dashboard')

@section('title', 'OPCRF Schedule — SmartApp')

@section('content')
    <div class="page-header">
        <h1>OPCRF Schedule</h1>
        <p>Set when each Part of the OPCRF may be opened. Staff see a locked Part with its reason and its opening time — the check runs on the server, not in the browser.</p>
    </div>

    {{-- Superadmin-only. The component re-checks authorization on every
         action and on every render, so the page cannot be reached by a
         crafted request even though the route already guards it. --}}
    <livewire:opcrf-schedule-manager />
@endsection