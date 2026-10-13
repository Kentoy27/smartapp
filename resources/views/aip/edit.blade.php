@extends('layouts.dashboard')

@section('title', 'Annual Implementation Plan — SmartApp')

@section('content')
    {{-- The whole editing workflow (projects, activities, budgets, save draft,
         submit, import from the WFP, attach to a MOV, export, preview) lives
         in one Livewire component. Access is the School Head's own school —
         the route guard and the component both enforce it. --}}
    <livewire:aip-editor :plan="$plan" />
@endsection
