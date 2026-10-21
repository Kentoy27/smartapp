@extends('layouts.dashboard')

@section('title', (auth()->user()?->canReviewAllOpcrfSubmissions() ? 'WFP Review' : 'WFP Uploads').' — SmartApp')

@section('content')
    <div class="page-header">
        <h1>{{ auth()->user()?->canReviewAllOpcrfSubmissions() ? 'WFP Review' : 'WFP Uploads' }}</h1>
        <p>Every School Head's uploaded Work and Financial Plan, with a copy of each archived workbook.</p>
    </div>

    {{-- The same list the dashboard card renders, as the page's own body.
         Admin-only: the route guard and the component both enforce it. --}}
    <livewire:wfp-uploads-list />
@endsection
