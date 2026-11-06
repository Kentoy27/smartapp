@extends('layouts.dashboard')

@section('title', 'Annual Implementation Plan — SmartApp')

@section('content')
    {{-- The editor enforces School Head ownership or SDS Viewer access to a
         plan returned for revision. --}}
    <livewire:aip-editor :plan="$plan" />
@endsection
