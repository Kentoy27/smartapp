{{-- THE REST OF THE WORKBOOK: every tab beyond PART I, in the same
     Excel-style design as the main sheet partial. Expects $workbook (the
     analyzer's workbook() array) and $uid (a per-instance key prefix so
     several modals on one page never collide). Each section carries
     data-sheet="part2|part3|part4|extra" so the modal's sheet-tab strip
     can filter the worksheets live. --}}
@php $wbUid = $uid ?? 'wb'; @endphp

@if (! empty($workbook['part_two']['sections']))
    @foreach ($workbook['part_two']['sections'] as $pi => $section)
        <div class="opcrf-sheet opcrf-sheet--tab" data-sheet="part2" wire:key="{{ $wbUid }}-p2sec-{{ $section['key'] }}-{{ $pi }}">
            <div class="opcrf-sheet-banner">
                PART {{ $section['key'] }}: {{ $section['title'] }}
            </div>

            @if ($section['note'] !== '')
                <div class="opcrf-sheet-partnote">{{ $section['note'] }}</div>
            @endif

            <div class="opcrf-sheet-tablewrap">
                <table class="opcrf-sheet-table">
                    <thead>
                        <tr>
                            <th scope="col">Competencies</th>
                            <th scope="col">Behavioural Indicators</th>
                            <th scope="col" class="opcrf-sheet-rate">Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($section['groups'] as $gi => $group)
                            @foreach ($group['indicators'] as $ii => $indicator)
                                <tr wire:key="{{ $wbUid }}-ind-{{ $section['key'] }}-{{ $gi }}-{{ $ii }}">
                                    @if ($ii === 0)
                                        <td class="opcrf-sheet-obj" rowspan="{{ count($group['indicators']) }}">
                                            <strong>{{ $group['label'] }}</strong>
                                            @if ($group['average'] !== null)
                                                <span class="opcrf-sheet-avg">Average: {{ number_format($group['average'], 2) }}</span>
                                            @endif
                                        </td>
                                    @endif
                                    <td>{{ $indicator['text'] }}</td>
                                    <td class="opcrf-sheet-rate">{{ $indicator['rating'] !== null ? number_format($indicator['rating'], 2) : '—' }}</td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="3" class="opcrf-sheet-empty">No competencies were found in this section.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    @if (! empty($workbook['part_two']['total_rows']))
        <div class="opcrf-sheet opcrf-sheet--tab" wire:key="{{ $wbUid }}-p2totals">
            <div class="opcrf-sheet-tablewrap">
                <table class="opcrf-sheet-table">
                    <tbody>
                        @foreach ($workbook['part_two']['total_rows'] as $ti => $total)
                            <tr class="opcrf-sheet-totalrow" wire:key="{{ $wbUid }}-p2t-{{ $ti }}">
                                <td>{{ $total['label'] }}</td>
                                <td class="opcrf-sheet-rate">{{ $total['score'] !== null ? number_format($total['score'], 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if (! empty($workbook['part_two']['signers']))
        <div class="opcrf-sheet opcrf-sheet--tab" wire:key="{{ $wbUid }}-p2signers">
            <div class="opcrf-sheet-signers">
                <div class="opcrf-sheet-signers-head">Signed on this tab</div>
                <div class="opcrf-sheet-signers-row">
                    @foreach ($workbook['part_two']['signers'] as $signer)
                        <div class="opcrf-sheet-signer" wire:key="{{ $wbUid }}-p2s-{{ $signer['ref'] }}">
                            <span class="opcrf-sheet-signer-name {{ $signer['name'] === '' ? 'is-empty' : '' }}">
                                {{ $signer['name'] !== '' ? $signer['name'] : '—' }}
                            </span>
                            <span class="opcrf-sheet-signer-role">{{ $signer['role'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
@endif

@if (! empty($workbook['part_three']['components']) || ! empty($workbook['part_three']['agreement']))
    <div class="opcrf-sheet opcrf-sheet--tab" data-sheet="part3" wire:key="{{ $wbUid }}-p3">
        <div class="opcrf-sheet-banner">PART III: SUMMARY OF RATINGS</div>

        @if (! empty($workbook['part_three']['components']))
            <div class="opcrf-sheet-tablewrap">
                <table class="opcrf-sheet-table">
                    <thead>
                        <tr>
                            <th scope="col">Final Performance Components</th>
                            <th scope="col" class="opcrf-sheet-time">Weight Allocation</th>
                            <th scope="col" class="opcrf-sheet-rate">Obtained Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workbook['part_three']['components'] as $ci => $component)
                            <tr wire:key="{{ $wbUid }}-c-{{ $ci }}">
                                <td><span class="opcrf-sheet-partkey">{{ $component['part'] }}</span> {{ $component['component'] }}</td>
                                <td>{{ $component['weight'] !== '' ? $component['weight'] : '—' }}</td>
                                <td class="opcrf-sheet-rate">{{ $component['obtained'] !== null ? number_format($component['obtained'], 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if (! empty($workbook['part_three']['agreement']))
            <div class="opcrf-sheet-signers">
                <div class="opcrf-sheet-signers-head">Ratee–Rater agreement</div>
                <div class="opcrf-sheet-signers-row">
                    @foreach ($workbook['part_three']['agreement'] as $ai => $who)
                        <div class="opcrf-sheet-signer" wire:key="{{ $wbUid }}-ag-{{ $ai }}">
                            <span class="opcrf-sheet-signer-name {{ $who['name'] === '' ? 'is-empty' : '' }}">
                                {{ $who['name'] !== '' ? $who['name'] : '—' }}
                            </span>
                            <span class="opcrf-sheet-signer-role">{{ $who['role'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif

@if (! empty($workbook['part_four']['office_plan']) || ! empty($workbook['part_four']['development_plan']) || ! empty($workbook['part_four']['signers']))
    <div class="opcrf-sheet opcrf-sheet--tab" data-sheet="part4" wire:key="{{ $wbUid }}-p4">
        <div class="opcrf-sheet-banner">PART IV: IMPROVEMENT AND DEVELOPMENT PLANS</div>

        @if (! empty($workbook['part_four']['office_plan']))
            <div class="opcrf-sheet-partbanner">
                <span class="opcrf-sheet-partkey">PART IV-A</span>
                <span class="opcrf-sheet-parttitle">Office Improvement Plan</span>
            </div>
            <div class="opcrf-sheet-tablewrap">
                <table class="opcrf-sheet-table">
                    <thead>
                        <tr>
                            <th scope="col">Gap Analysis <span class="opcrf-sheet-th-sub">(SWOT)</span></th>
                            <th scope="col">Improvement Area</th>
                            <th scope="col">General Objective</th>
                            <th scope="col">Improvement Intervention</th>
                            <th scope="col">Timeline</th>
                            <th scope="col">Resources Needed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workbook['part_four']['office_plan'] as $ri => $row)
                            <tr wire:key="{{ $wbUid }}-op-{{ $row['row'] }}-{{ $ri }}">
                                <td>{{ $row['gap'] !== '' ? $row['gap'] : '—' }}</td>
                                <td>{{ $row['area'] !== '' ? $row['area'] : '—' }}</td>
                                <td>{{ $row['objective'] !== '' ? $row['objective'] : '—' }}</td>
                                <td>{{ $row['intervention'] !== '' ? $row['intervention'] : '—' }}</td>
                                <td>{{ $row['timeline'] !== '' ? $row['timeline'] : '—' }}</td>
                                <td>{{ $row['resources'] !== '' ? $row['resources'] : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($workbook['part_four']['office_feedback'] !== '')
                <div class="opcrf-sheet-partnote"><strong>Feedback:</strong> {{ $workbook['part_four']['office_feedback'] }}</div>
            @endif
        @endif

        @if (! empty($workbook['part_four']['development_plan']))
            <div class="opcrf-sheet-partbanner">
                <span class="opcrf-sheet-partkey">PART IV-B</span>
                <span class="opcrf-sheet-parttitle">Individual Development Plan</span>
            </div>
            <div class="opcrf-sheet-tablewrap">
                <table class="opcrf-sheet-table">
                    <thead>
                        <tr>
                            <th scope="col">Strengths</th>
                            <th scope="col">Improvement Needs</th>
                            <th scope="col">Learning Objective</th>
                            <th scope="col">Developmental Intervention</th>
                            <th scope="col">Timeline</th>
                            <th scope="col">Resources Needed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workbook['part_four']['development_plan'] as $ri => $row)
                            <tr wire:key="{{ $wbUid }}-dp-{{ $row['row'] }}-{{ $ri }}">
                                <td>{{ $row['gap'] !== '' ? $row['gap'] : '—' }}</td>
                                <td>{{ $row['area'] !== '' ? $row['area'] : '—' }}</td>
                                <td>{{ $row['objective'] !== '' ? $row['objective'] : '—' }}</td>
                                <td>{{ $row['intervention'] !== '' ? $row['intervention'] : '—' }}</td>
                                <td>{{ $row['timeline'] !== '' ? $row['timeline'] : '—' }}</td>
                                <td>{{ $row['resources'] !== '' ? $row['resources'] : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($workbook['part_four']['development_feedback'] !== '')
                <div class="opcrf-sheet-partnote"><strong>Feedback:</strong> {{ $workbook['part_four']['development_feedback'] }}</div>
            @endif
        @endif

        @if (! empty($workbook['part_four']['signers']))
            <div class="opcrf-sheet-signers">
                <div class="opcrf-sheet-signers-head">Signed on this tab</div>
                <div class="opcrf-sheet-signers-row">
                    @foreach ($workbook['part_four']['signers'] as $signer)
                        <div class="opcrf-sheet-signer" wire:key="{{ $wbUid }}-p4s-{{ $signer['ref'] }}">
                            <span class="opcrf-sheet-signer-name {{ $signer['name'] === '' ? 'is-empty' : '' }}">
                                {{ $signer['name'] !== '' ? $signer['name'] : '—' }}
                            </span>
                            <span class="opcrf-sheet-signer-role">{{ $signer['role'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif

@foreach ($workbook['extra_sheets'] as $si => $extra)
    <div class="opcrf-sheet opcrf-sheet--tab" data-sheet="extra" wire:key="{{ $wbUid }}-xs-{{ $si }}">
        <div class="opcrf-sheet-banner">{{ $extra['name'] }}</div>
        <div class="opcrf-sheet-tablewrap">
            <table class="opcrf-sheet-table">
                <tbody>
                    @forelse ($extra['rows'] as $row)
                        <tr wire:key="{{ $wbUid }}-xs-{{ $si }}-{{ $row['row'] }}">
                            <td class="opcrf-sheet-num">{{ $row['row'] }}</td>
                            <td>
                                @foreach ($row['cells'] as $cell)
                                    <span class="opcrf-sheet-xscell" wire:key="{{ $wbUid }}-xs-{{ $si }}-{{ $cell['ref'] }}">
                                        <strong>{{ $cell['column'] }}:</strong> {{ $cell['value'] }}
                                    </span>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="opcrf-sheet-empty">This tab is empty.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endforeach
