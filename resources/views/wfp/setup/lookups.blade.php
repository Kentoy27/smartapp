@extends('layouts.dashboard')

@section('title', $spec['label'].' — WFP Setup — SmartApp')

@section('content')
    <livewire:wfp-lookup-table :category="$category" />
@endsection
