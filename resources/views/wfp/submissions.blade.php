@extends('layouts.dashboard')

@section('title', 'WFP Uploads — SmartApp')

@section('content')
    <div class="page-header">
        <h1>WFP Uploads</h1>
        <p>Every school's uploaded Work and Financial Plan, with a copy of each archived workbook.</p>
    </div>

    {{-- The same list the dashboard card renders, as the page's own body.
         Admin-only: the route guard and the component both enforce it. --}}
    <livewire:wfp-uploads-list />
@endsection
