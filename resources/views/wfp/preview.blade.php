@extends('layouts.dashboard')

@section('title', 'WFP Preview — SmartApp')

@section('content')
    {{-- The preview list and the plan it opens. The component re-checks the
         gate, and the plan it hands over re-checks it again for the plan.
         The heading is the component's own: with a plan open, the plan
         brings its own, and a page carries one heading. --}}
    <livewire:wfp-preview />
@endsection
