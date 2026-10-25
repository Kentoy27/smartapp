@extends('layouts.dashboard')

@section('title', 'School Allotments — SmartApp')

@section('content')
    {{-- The Budget office's page: set the ceiling each School Head may consume
         through their school's Monthly Expenditure Matrices, monitor the year's
         consumption, and certify the amount as available. The route guard and
         the component both enforce canManageAllotments(). --}}
    <livewire:school-allotment-manager />
@endsection
