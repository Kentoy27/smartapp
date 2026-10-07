@extends('layouts.dashboard')

@section('title', 'Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Opcrf</h1>
        <p>Your OPCRF submissions — everything you've turned in, newest first.</p>
    </div>

    {{-- SUBMISSIONS TABLE: one Livewire component — new submissions
         appear without a reload, and approved submissions with a stored
         workbook offer their download. Staff submit from the dashboard's
         OPCRF Template card. --}}
    <livewire:opcrf-movs />
@endsection
