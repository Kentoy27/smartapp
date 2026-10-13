@extends('layouts.dashboard')

@section('title', 'Per Line Item Expenditure — SmartApp')

@section('content')
    {{-- The annual MOOE allotment report (the year's Monthly Expenditure
         Matrices, rolled up per line item). Access is restricted to the School
         Head role — the route guard and the component both enforce it. --}}
    <livewire:wfp-line-item-allotment />
@endsection
