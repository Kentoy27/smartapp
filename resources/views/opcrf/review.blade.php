@extends('layouts.dashboard')

@section('title', auth()->user()->canReviewAllOpcrfSubmissions() ? 'OPCRF — SmartApp' : 'Review Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        @if (auth()->user()->canReviewAllOpcrfSubmissions())
            <h1>OPCRF</h1>
            <p>Review complete Parts I–IV workbooks assigned to you, add validator remarks, and forward each validated submission to the first Superadmin. Commitment submissions are not included here.</p>
        @else
            <h1>Review Opcrf</h1>
            <p>Every staff OPCRF submission — newest first. Open a row to review the submitted form, download the full workbook, and see its MOVs. Add remarks, then Approve (records approval and auto-routes onward to the next superadmin in the review chain), Final Approval (ends the chain), or Return it for revision — the original uploaded workbook is never replaced, and every action is recorded in the review history. The list updates live as staff submit.</p>
        @endif
    </div>

    {{-- SUBMISSIONS REVIEW TABLE: one Livewire component — new submissions
         appear without a reload (event + 15s poll), the Review action opens
         the read-only review modal per row. --}}
    <livewire:opcrf-review />
@endsection
