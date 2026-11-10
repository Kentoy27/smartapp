@extends('layouts.dashboard')

@section('title', 'School Improvement Plan (SIP) - SmartApp')

@section('content')
    <div class="page-header">
        <h1>School Improvement Plan (SIP)</h1>
        <p>
            @if (auth()->user()?->hasAdminAccess())
                Browse SIP records uploaded by schools across all divisions.
            @else
                Browse each school's SIP by division. School Heads can upload or replace their school's file.
            @endif
        </p>
    </div>

    @livewire('sip-table')
@endsection
