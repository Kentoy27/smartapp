@extends('layouts.dashboard')

@section('title', 'Review Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Review Opcrf</h1>
        <p>Every staff OPCRF submission — newest first. Open a row to review the submitted form, download the full workbook, and see its MOVs. Add remarks, then Approve / Compliance (the submission auto-routes onward to the next superadmin in the review chain) or Return it for revision — the original uploaded workbook is never replaced, and every action is recorded in the review history. The list updates live as staff submit.</p>
    </div>

    {{-- SUBMISSIONS REVIEW TABLE: one Livewire component — new submissions
         appear without a reload (event + 15s poll), the Review action opens
         the read-only review modal per row. --}}
    <livewire:opcrf-review />
@endsection
