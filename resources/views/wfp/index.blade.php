@extends('layouts.dashboard')

@section('title', 'Work and Financial Plan — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Work and Financial Plan</h1>
        <p>Download the official WFP template, complete it, then upload it here to review or update your plan.</p>
    </div>

    {{-- The whole WFP workflow (template download, upload, status, preview,
         download/remove) lives in one Livewire component. Access is
         restricted to the School Head role — the route guard and the
         component both enforce it. --}}
    <livewire:wfp-manager />
@endsection
