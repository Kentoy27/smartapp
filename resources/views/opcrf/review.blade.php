@extends('layouts.dashboard')

@section('title', 'Review Opcrf — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Review Opcrf</h1>
        <p>Every staff OPCRF submission — newest first. Open a row to review the submitted form, download the full workbook, and see its MOVs. Approving a submission records who approved it and when; attaching the corrected/filled workbook in the same step makes it the official copy the staff member can download. The list updates live as staff submit.</p>
    </div>

    {{-- SUBMISSIONS REVIEW TABLE: one Livewire component — new submissions
         appear without a reload (event + 15s poll), the Review action opens
         the read-only review modal per row. --}}
    <livewire:opcrf-review />
@endsection
