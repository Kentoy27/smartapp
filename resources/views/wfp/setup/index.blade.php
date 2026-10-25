@extends('layouts.dashboard')

@section('title', 'WFP Setup — SmartApp')

@section('content')
    <div class="wfp-setup">
        <div class="page-header">
            <h1>WFP Setup</h1>
            <p>Maintain the lookup library behind the Work and Financial Plan: funding sources, units, resource kinds, organizational classifications, and the activity templates the Add a Line form draws from.</p>
        </div>

        <div class="card">
            <div class="card-head users-toolbar">
                <p>Pick a category to maintain its records, import them in bulk from CSV, or export a working copy.</p>
            </div>

            <div class="wfp-setup__grid">
                @foreach (config('wfp_lookups.categories') as $slug => $spec)
                    <a
                        href="{{ route('wfp.setup.lookups', ['category' => $slug]) }}"
                        class="wfp-setup__card"
                        wire:navigate
                    >
                        <div class="wfp-setup__card-icon">
                            <x-icon name="file-text" :size="24" />
                        </div>
                        <div class="wfp-setup__card-body">
                            <h3>{{ $spec['label'] }}</h3>
                            <p>{{ $spec['blurb'] }}</p>
                        </div>
                        <span class="wfp-setup__card-arrow">
                            <x-icon name="chevron-down" :size="16" />
                        </span>
                    </a>
                @endforeach

                <a
                    href="{{ route('wfp.setup.templates') }}"
                    class="wfp-setup__card wfp-setup__card--templates"
                    wire:navigate
                >
                    <div class="wfp-setup__card-icon">
                        <x-icon name="clipboard-list" :size="24" />
                    </div>
                    <div class="wfp-setup__card-body">
                        <h3>Activity Templates</h3>
                        <p>Reusable line combinations that auto-fill the Add a Line form.</p>
                    </div>
                    <span class="wfp-setup__card-arrow">
                        <x-icon name="chevron-down" :size="16" />
                    </span>
                </a>
            </div>
        </div>
    </div>
@endsection
