{{-- FULL EXCEL-STYLE SHEET: shared by the staff review-before-submitting
     modal ($sheet + $self_rating) and the superadmin's read-only review
     ($sheet + $sheetSelfRating, $full = false hides the confirm line).
     Expects: $sheet (layout array), $rating (string), $full (bool). --}}

<div class="opcrf-sheet">
    <div class="opcrf-sheet-banner">
        {{ $sheet['title'] !== '' ? $sheet['title'] : 'OFFICE PERFORMANCE COMMITMENT AND REVIEW FORM (OPCRF)' }}
    </div>

    <div class="opcrf-sheet-headerblock opcrf-sheet-headerblock--split">
        <div class="opcrf-sheet-headercol">
            @forelse ($sheet['headers'] as $pair)
                <div class="opcrf-sheet-headrow" wire:key="hdr-{{ $pair['ref'] }}-{{ $full ? 'staff' : 'review' }}">
                    <span class="opcrf-sheet-headlabel">{{ $pair['label'] }}</span>
                    <span class="opcrf-sheet-headvalue {{ $pair['value'] === '' ? 'is-empty' : '' }}">
                        {{ $pair['value'] !== '' ? $pair['value'] : '—' }}
                    </span>
                </div>
            @empty
                <div class="opcrf-sheet-headrow">
                    <span class="opcrf-sheet-headvalue is-empty">No header details were found in the uploaded file.</span>
                </div>
            @endforelse
        </div>

        @if (! empty($sheet['evaluators']))
            <div class="opcrf-sheet-headercol opcrf-sheet-headercol--evaluator">
                @foreach ($sheet['evaluators'] as $pair)
                    <div class="opcrf-sheet-headrow" wire:key="eval-{{ $pair['ref'] }}-{{ $full ? 'staff' : 'review' }}">
                        <span class="opcrf-sheet-headlabel">{{ $pair['label'] }}</span>
                        <span class="opcrf-sheet-headvalue {{ $pair['value'] === '' ? 'is-empty' : '' }}">
                            {{ $pair['value'] !== '' ? $pair['value'] : '—' }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @foreach ($sheet['parts'] as $pi => $part)
        <div class="opcrf-sheet-part" wire:key="part-{{ $part['key'] !== '' ? $part['key'] : $pi }}-{{ $full ? 'staff' : 'review' }}">
            @if ($part['title'] !== '')
                <div class="opcrf-sheet-partbanner">
                    <span class="opcrf-sheet-partkey">PART {{ $part['key'] }}</span>
                    <span class="opcrf-sheet-parttitle">{{ $part['title'] }}</span>
                </div>
                @if ($part['note'] !== '')
                    <div class="opcrf-sheet-partnote">{{ $part['note'] }}</div>
                @endif
            @endif

            <div class="opcrf-sheet-tablewrap">
                <table class="opcrf-sheet-table">
                    <thead>
                        <tr>
                            <th class="opcrf-sheet-num" scope="col">#</th>
                            <th class="opcrf-sheet-obj" scope="col">Objectives <span class="opcrf-sheet-th-sub">(based on Office Functions)</span></th>
                            <th class="opcrf-sheet-time" scope="col">Timeline</th>
                            <th class="opcrf-sheet-crit" scope="col">Performance Measure <span class="opcrf-sheet-th-sub">(Quality, Efficiency, Timeliness)</span></th>
                            <th class="opcrf-sheet-acc" scope="col">Actual Accomplishments</th>
                            <th class="opcrf-sheet-rate" scope="col">Rating <span class="opcrf-sheet-th-sub">(Q, E, T)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($part['rows'] as $entry)
                            @foreach ($entry['criteria'] as $ci => $crit)
                                <tr wire:key="objrow-{{ $part['key'] }}-{{ $entry['row'] }}-{{ $ci }}-{{ $full ? 'staff' : 'review' }}">
                                    @if ($ci === 0)
                                        <td class="opcrf-sheet-num" rowspan="{{ count($entry['criteria']) }}">{{ $entry['number'] }}</td>
                                        <td class="opcrf-sheet-obj" rowspan="{{ count($entry['criteria']) }}">{{ $entry['objectives'] !== '' ? $entry['objectives'] : '—' }}</td>
                                        <td class="opcrf-sheet-time" rowspan="{{ count($entry['criteria']) }}">{{ $entry['timeline'] !== '' ? $entry['timeline'] : '—' }}</td>
                                    @endif
                                    <td class="opcrf-sheet-crit">{{ $crit['label'] !== '' ? $crit['label'] : '—' }}</td>
                                    <td class="opcrf-sheet-acc">{{ $crit['accomplishments'] !== '' ? $crit['accomplishments'] : '—' }}</td>
                                    <td class="opcrf-sheet-rate">{{ $crit['rating'] !== null ? (fmod($crit['rating'], 1.0) === 0.0 ? number_format($crit['rating']) : number_format($crit['rating'], 2)) : '—' }}</td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="6" class="opcrf-sheet-empty">No objectives were found in this part.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        @if ($part['has_total_row'])
                            <tr class="opcrf-sheet-totalrow">
                                <td colspan="5">PART {{ $part['key'] }} TOTAL SCORE</td>
                                <td class="opcrf-sheet-rate">{{ $part['total_score'] !== null ? number_format($part['total_score'], 2) : '—' }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td colspan="5">AVERAGE (QET)</td>
                            <td class="opcrf-sheet-rate">
                                @if ($rating !== '')
                                    {{ number_format((float) $rating, 2) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endforeach
</div>

@if (! empty($sheet['signers']))
    <div class="opcrf-sheet-signers">
        <div class="opcrf-sheet-signers-head">Signed after the review</div>
        <div class="opcrf-sheet-signers-row">
            @foreach ($sheet['signers'] as $signer)
                <div class="opcrf-sheet-signer" wire:key="signer-{{ $signer['ref'] }}-{{ $full ? 'staff' : 'review' }}">
                    <span class="opcrf-sheet-signer-name {{ $signer['name'] === '' ? 'is-empty' : '' }}">
                        {{ $signer['name'] !== '' ? $signer['name'] : '—' }}
                    </span>
                    <span class="opcrf-sheet-signer-role">{{ $signer['role'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
