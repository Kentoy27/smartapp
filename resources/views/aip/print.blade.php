<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $plan->label() }} — {{ $plan->fiscalYearLabel() }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #f1f5f9;
            color: #0f172a;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            font-size: 12px;
        }

        .print-toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            max-width: 1100px;
            margin: 0 auto 14px;
        }

        .print-toolbar button,
        .print-toolbar a {
            padding: 9px 18px;
            border-radius: 8px;
            border: 1px solid #3b82f6;
            background: #3b82f6;
            color: #fff;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .print-toolbar a {
            background: #fff;
            color: #1d4ed8;
        }

        .sheet {
            max-width: 1100px;
            margin: 0 auto;
            background: #fff;
            padding: 28px 30px;
            border: 1px solid #e2e8f0;
        }

        .head { text-align: center; line-height: 1.5; }
        .head .republic { font-size: 12px; }
        .head .department { font-size: 12px; }
        .head .division { font-size: 12px; }
        .head .school { font-weight: 700; font-size: 13px; }
        .head h1 {
            font-size: 15px;
            letter-spacing: 0.06em;
            margin: 8px 0 2px;
            text-transform: uppercase;
        }
        .head .sy { font-size: 12px; }
        .head .title { font-size: 12px; font-style: italic; margin-top: 2px; }

        table.aip {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        table.aip th,
        table.aip td {
            border: 1px solid #334155;
            padding: 5px 6px;
            vertical-align: top;
            text-align: left;
        }

        table.aip th {
            background: #e2e8f0;
            font-size: 10px;
            text-transform: uppercase;
            text-align: center;
            font-weight: 700;
        }

        td.money { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: 700; }
        tr.total td.label { text-align: right; }
        tr.subtotal td { font-style: italic; }
        tr.subtotal td.label { text-align: right; }

        .signatures {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            margin-top: 34px;
        }

        .signatures .sig { flex: 1; }
        .signatures .sig .who { font-weight: 700; margin-top: 26px; }
        .signatures .sig .role { font-size: 11px; }

        .status-line {
            margin-top: 18px;
            font-size: 11px;
            color: #475569;
        }

        @media print {
            body { background: #fff; padding: 0; font-size: 10px; }
            .print-toolbar { display: none; }
            .sheet { border: 0; padding: 0; max-width: none; }
            table.aip th, table.aip td { padding: 3px 4px; }
            @page { size: landscape; margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <a href="{{ url()->previous() }}">Back</a>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="sheet">
        <div class="head">
            <div class="republic">Republic of the Philippines</div>
            <div class="department">Department of Education</div>
            <div class="division">{{ $plan->school?->district?->name ?? 'Schools Division Office' }}</div>
            <div class="school">{{ $plan->school?->name ?? 'School' }}</div>
            <h1>Annual Implementation Plan</h1>
            <div class="sy">School Year {{ $plan->fiscalYearLabel() }}</div>
            @if (trim((string) $plan->title) !== '')
                <div class="title">{{ $plan->title }}</div>
            @endif
        </div>

        <table class="aip">
            <thead>
                <tr>
                    <th scope="col" style="width: 15%">School Improvement Project Title</th>
                    <th scope="col" style="width: 15%">Project Objective</th>
                    <th scope="col" style="width: 13%">Output for the Year</th>
                    <th scope="col" style="width: 17%">Activities</th>
                    <th scope="col" style="width: 11%">Person(s) Responsible</th>
                    <th scope="col" style="width: 11%">Schedule / Venue</th>
                    <th scope="col" style="width: 9%">Budget per Activity</th>
                    <th scope="col" style="width: 9%">Budget Source</th>
                </tr>
            </thead>
            <tbody>
                @php($projects = $plan->projects()->with('activities')->get())

                @forelse ($projects as $project)
                    @php($activities = $project->activities->isEmpty() ? collect([null]) : $project->activities)
                    @php($span = $activities->count())

                    @foreach ($activities as $index => $activity)
                        <tr>
                            @if ($index === 0)
                                <td rowspan="{{ $span }}">{{ $project->title }}</td>
                                <td rowspan="{{ $span }}">{{ $project->objective ?: '—' }}</td>
                                <td rowspan="{{ $span }}">{{ $project->output_for_year ?: '—' }}</td>
                            @endif
                            <td>{{ $activity?->activity ?? '' }}</td>
                            <td>{{ $activity?->person_responsible ?: '—' }}</td>
                            <td>{{ $activity?->scheduleLabel() ?? '—' }}</td>
                            <td class="money">{{ $activity?->budget !== null ? number_format((float) $activity->budget, 2) : '—' }}</td>
                            <td>{{ $activity?->budget_source ?: '—' }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center">No projects encoded yet.</td>
                    </tr>
                @endforelse

                @if ($projects->isNotEmpty())
                    <tr class="total">
                        <td class="label" colspan="6">TOTAL BUDGET</td>
                        <td class="money">{{ number_format($plan->annualBudgetTotal(), 2) }}</td>
                        <td></td>
                    </tr>

                    @foreach ($plan->budgetBySource() as $source => $total)
                        <tr class="subtotal">
                            <td class="label" colspan="6">Subtotal — {{ $source }}</td>
                            <td class="money">{{ number_format((float) $total, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>

        <div class="signatures">
            <div class="sig">
                <div>Prepared by:</div>
                <div class="who">{{ $plan->creator?->name ?? '' }}</div>
                <div class="role">School Head</div>
            </div>
            <div class="sig">
                <div>Reviewed by:</div>
                <div class="who">{{ $plan->approvedBy?->name ?? '' }}</div>
                <div class="role">Schools Division Office</div>
            </div>
            <div class="sig">
                <div>Approved by:</div>
                <div class="who"></div>
                <div class="role">Schools Division Superintendent</div>
            </div>
        </div>

        <div class="status-line">
            Status: {{ \App\Models\AipPlan::statusLabel((string) $plan->status) }}
            @if ($plan->submitted_at) · Submitted {{ $plan->submitted_at->format('M j, Y g:i A') }} @endif
            @if ($plan->approved_at) · Approved {{ $plan->approved_at->format('M j, Y g:i A') }} @endif
        </div>
    </div>
</body>
</html>
