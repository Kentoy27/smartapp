@extends('layouts.dashboard')

@section('title', ($monitoringMode ?? 'all') === 'mov' ? 'MOV Monitoring — SmartApp' : 'School Submission Monitoring — SmartApp')

@section('content')
    <livewire:psds-monitoring :mode="$monitoringMode ?? 'all'" />
@endsection
