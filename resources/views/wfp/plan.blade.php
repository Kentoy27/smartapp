@extends('layouts.dashboard')

@section('title', 'Work and Financial Plan — SmartApp')

@section('content')
    <livewire:wfp-plan-editor :plan="$plan" />
@endsection
