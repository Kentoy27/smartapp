@extends('layouts.dashboard')

@section('title', 'Monthly Expenditure Matrix — SmartApp')

@section('content')
    <livewire:mem-editor :matrix="$matrix" />
@endsection
