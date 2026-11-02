@extends('layouts.dashboard')

@section('title', 'School Division Mapping — SmartApp')

@section('content')
    {{-- The administrator's explicit mapping of existing financial records to
         their school division. Access is restricted to the Administrator role
         (the route guard and the component both enforce it) — nothing here is
         ever assigned automatically. --}}
    <livewire:school-division-mapper />
@endsection
