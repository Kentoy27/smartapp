@extends('layouts.dashboard')

@section('title', 'Upload MOV — SmartApp')

@section('content')
    {{-- ONE MOV AT A TIME. There is no page header and no checklist here:
         clicking a MOV in the sidebar — or arriving on /movs?mov=N — renders
         that MOV alone, with its own table of pictures and its own upload row.
         Nothing is rendered until a MOV is chosen. --}}
    <livewire:mov-uploader />
@endsection