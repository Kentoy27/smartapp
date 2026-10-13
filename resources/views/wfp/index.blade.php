@extends('layouts.dashboard')

@section('title', 'Work and Financial Plan — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Work and Financial Plan</h1>
        <p>Generate your school's Work and Financial Plan from the official template and download it, ready to complete.</p>
    </div>

    {{-- The WFP workflow (generate and download from the account-code list)
         lives in one Livewire component. Access is restricted to the School
         Head role — the route guard and the component both enforce it. --}}
    <livewire:wfp-manager />
@endsection
