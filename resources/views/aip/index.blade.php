@extends('layouts.dashboard')

@section('title', 'Annual Implementation Plan — SmartApp')

@section('content')
    <div class="page-header">
        <h1>Annual Implementation Plan (AIP)</h1>
        <p>AIP records will appear here when they are available.</p>
    </div>

    <section class="card" aria-labelledby="aip-records-title">
        <h2 class="card-title" id="aip-records-title">AIP Records</h2>
        <div class="table-wrap">
            <table class="data-table aip-table">
                <caption>Annual Implementation Plan records</caption>
                <thead>
                    <tr>
                        <th scope="col">Activity</th>
                        <th scope="col">Timeline</th>
                        <th scope="col">Budget</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="table-empty" colspan="4">No AIP records yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
@endsection
