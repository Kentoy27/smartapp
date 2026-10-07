@extends('layouts.dashboard')

@section('title', 'Dashboard — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Welcome back, {{ $user->username }}</h1>
        <p>Here's what's happening with your account today.</p>
    </div>

    {{-- Everything below the greeting is data-driven and refreshes itself.
         Staff get the OPCRF Template card (DashboardSummary), which polls for
         changes so a submission, approval or return-for-revision shows up here
         without a reload. A superadmin gets OPCRF Analytics instead — the
         review workload at a glance, over the same submissions their Review
         Opcrf page lists. The District List has its own page (Districts &
         Schools), so it is on neither dashboard. --}}
    @if ($user->is_superadmin)
        <livewire:opcrf-analytics />
    @else
        <livewire:dashboard-summary />

        {{-- The OPCRF upload button and its two modals (upload window, then
             review-before-submitting). Kept here, as a SIBLING of the
             dashboard summary rather than inside it: the summary polls every
             15s, and a nested component is re-mounted on every poll — which
             discarded the picked file and closed the review modal seconds
             after it opened. As a sibling it keeps its state for as long as
             the user is in the flow.

             Staff-only, as it was inside the summary: the component refuses
             to mount for a superadmin, so rendering it for one would turn
             their whole dashboard into a 404. --}}
        @if (! auth()->user()->is_superadmin)
            <livewire:opcrf-upload />
        @endif
    @endif
@endsection
