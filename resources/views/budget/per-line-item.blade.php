@extends('layouts.dashboard')

@section('title', 'Per Line Item Expenditure — SmartApp')

@section('content')
    {{-- The Budget office's read-only copy of a school's annual allotment
         report: the same printed form the School Head reads, with a school
         picker because the office oversees every school. The route guard and
         the component both enforce canCheckLineItems(), so only the Budget
         role can reach it. --}}
    <livewire:budget-line-item-allotment />
@endsection
