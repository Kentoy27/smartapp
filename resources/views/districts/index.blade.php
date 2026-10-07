@extends('layouts.dashboard')

@section('title', 'Districts & Schools — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Districts &amp; Schools</h1>
        <p>Every division and the schools under it. Select a district to see and manage its schools, or add new ones — the counts below refresh as you work.</p>
    </div>

    {{-- The District List: every district with its school count, refreshing
         itself as schools are added or renamed. --}}
    <livewire:district-list />

    {{-- The add / rename / delete manager that the list's buttons open.

         It is a SIBLING of the list rather than a child of it, on purpose: a
         Livewire child of a polling parent gets re-instantiated by that
         parent's poll, which reset the modal's state and closed the window
         ~15s after opening — right in the middle of adding a school. Out here
         it has no polling ancestor, so it stays open until it is closed. The
         list's buttons still reach it: it listens for the same window-level
         open-districts event either way. --}}
    <livewire:district-manager />
@endsection