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
        .head .school { font-weight: 700; font-size: 13px; }
        .head .district { font-size: 12px; }
        .head h1 {
            font-size: 15px;
            letter-spacing: 0.06em;
            margin: 12px 0 8px;
            text-transform: uppercase;
        }
        .head .purpose { max-width: 1000px; margin: 8px auto 0; text-align: left; }

        table.aip {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 16px;
            font-size: 9px;
        }

        table.aip th,
        table.aip td {
            border: 1px solid #334155;
            padding: 5px 6px;
            vertical-align: top;
            text-align: left;
        }

        table.aip th {
            background: #bdc0bf;
            font-size: 8px;
            text-align: center;
            font-weight: 700;
        }
        table.aip tr.pillar td { background: #bdc0bf; font-weight: 700; }
        .template-note { margin-top: 8px; font-size: 10px; }

        td.money { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: 700; }
        tr.total td.label { text-align: right; }
        tr.subtotal td { font-style: italic; }
        tr.subtotal td.label { text-align: right; }

        .signatures {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
            margin-top: 34px;
        }

        .signatures .sig { min-width: 0; }
        .signatures .sig .line { height: 28px; }
        .signatures .sig .who { font-weight: 700; text-transform: uppercase; }
        .signatures .sig .role { font-size: 11px; }
        .signatures .sig .label { min-height: 18px; }

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
            @page { size: legal landscape; margin: 1in; }
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
            <div class="school">{{ $plan->school?->name ?? '(NAME OF SCHOOL)' }}</div>
            <div class="district">{{ $plan->school?->district?->name ?? '(District)' }}</div>
            <h1>FY {{ $plan->year }} ANNUAL IMPLEMENTATION PLAN</h1>
            <p class="purpose">{{ str_replace(':year', (string) $plan->year, config('aip.template.purpose')) }}</p>
        </div>

        @if ($plan->form_data !== null)
            @php($formRows = collect($plan->form_data['rows'] ?? []))
            @php($pillars = collect(config('aip.pillars', []))->merge($formRows->pluck('pillar')->filter()->unique())->unique()->values())
            <table class="aip">
                <thead>
                    <tr>
                        <th scope="col">Strategic Objective (Based on SIP)</th>
                        <th scope="col">Strategy</th>
                        <th scope="col">Updated Programs, Projects, and Activities (PPAs)</th>
                        <th scope="col">Resources / Materials Needed</th>
                        <th scope="col">Budget (If the fund source is MOOE, type “c/o approved WFP”. If the fund source is not MOOE, indicate the amount.)</th>
                        <th scope="col">Persons Responsible</th>
                        <th scope="col">Indicative Timeline (Indicate the month(s) covered)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pillars as $pillar)
                        <tr class="pillar"><td colspan="7">{{ $pillar }}</td></tr>
                        @php($pillarRows = $formRows->filter(fn (array $row): bool => ($row['pillar'] ?? '') === $pillar))
                        @forelse ($pillarRows as $row)
                            <tr>
                                <td>{{ $row['strategic_objective'] ?: '—' }}</td>
                                <td>{{ $row['strategy'] ?: '—' }}</td>
                                <td>{{ $row['ppa'] ?: '—' }}</td>
                                <td>{{ $row['resources'] ?: '—' }}</td>
                                <td>{{ $row['budget'] ?: '—' }}</td>
                                <td>{{ $row['persons_responsible'] ?: '—' }}</td>
                                <td>{{ $row['timeline'] ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7">&nbsp;</td></tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
            <p class="template-note">Note: Rows may be added as needed.</p>
        @else
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
        @endif

        <div class="signatures">
            <div class="sig">
                <div class="label">Prepared by:</div>
                <div class="line"></div>
                <div class="who">{{ $plan->creator?->name ?? '' }}</div>
                <div class="role">{{ $plan->creator?->position ?: 'Position/Designation' }}</div>
            </div>
            <div class="sig">
                <div class="label">Reviewed by:</div>
                <div class="line"></div>
                <div class="who">{{ config('aip.template.reviewed_by.name') }}</div>
                <div class="role">{{ config('aip.template.reviewed_by.role') }}</div>
            </div>
            <div class="sig">
                <div class="label">Recommending Approval</div>
                <div class="line"></div>
                <div class="who">{{ config('aip.template.recommending_approval.name') }}</div>
                <div class="role">{{ config('aip.template.recommending_approval.role') }}</div>
            </div>
            <div class="sig">
                <div class="label">Approved:</div>
                <div class="line"></div>
                <div class="who">{{ config('aip.template.approved_by.name') }}</div>
                <div class="role">{{ config('aip.template.approved_by.role') }}</div>
            </div>
        </div>

        @unless ($plan->form_data !== null)
            <div class="status-line">
                Status: {{ \App\Models\AipPlan::statusLabel((string) $plan->status) }}
                @if ($plan->submitted_at) · Submitted {{ $plan->submitted_at->format('M j, Y g:i A') }} @endif
                @if ($plan->approved_at) · Approved {{ $plan->approved_at->format('M j, Y g:i A') }} @endif
            </div>
        @endunless
    </div>
</body>
</html>
