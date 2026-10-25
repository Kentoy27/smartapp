@extends('layouts.dashboard')

@section('title', 'Monthly Expenditure — SmartApp')

@section('content')
    {{-- The Budget office's read-only, per-line view of every school's Monthly
         Expenditure Matrix. The route guard and the component both enforce
         canCheckLineItems(), so the SH who owns the lines and the
         administrative side that oversees the schools cannot reach it. --}}
    <livewire:budget-line-item-checker />
@endsection
