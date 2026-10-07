{{-- FULL EXCEL-STYLE SHEET: shared by the staff review-before-submitting
     modal ($sheet + $self_rating) and the superadmin's read-only review
     ($sheet + $sheetSelfRating, $full = false hides the confirm line).

     Shared on purpose: the reviewer reads the file through the identical
     markup the submitter saw on their own screen, so nothing can be
     approved in review that was not visible at submission — and the two
     views cannot drift apart. Expects: $sheet (layout array), $rating
     (string), $full (bool). --}}

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
                    {{-- THE PART'S OWN COLUMN HEADINGS: every caption is read
                         out of the workbook's header row, so a part that words
                         a column differently (Part I-C's "Organizational
                         Effectiveness Area", its Timeline in J and Weight in K)
                         is shown as the template words it — with the shipped
                         wording as the fallback for a workbook whose header row
                         cannot be read. The band captions above the table do
                         the same. --}}
                    @php
                        $captions = $part['captions'] ?? [];
                        $bands = $part['bands'] ?? ['planning' => '', 'evaluation' => ''];

                        // A heading is the template's own caption when it has
                        // one, and the shipped default otherwise. The default
                        // carries a second line (the parenthetical the shipped
                        // template splits across two header rows); a real
                        // caption is already the whole string, so the second
                        // line is dropped rather than repeated.
                        $heading = function (string $field, string $main, string $sub = '') use ($captions): string {
                            $caption = trim((string) ($captions[$field] ?? ''));

                            if ($caption === '') {
                                return $sub === '' ? $main : $main.' '.$sub;
                            }

                            return $caption;
                        };
                    @endphp

                    @if (($bands['planning'] ?? '') !== '' || ($bands['evaluation'] ?? '') !== '')
                        <div class="opcrf-sheet-bandnote">
                            @if (($bands['planning'] ?? '') !== '')
                                <span class="opcrf-sheet-band">{{ $bands['planning'] }}</span>
                            @endif
                            @if (($bands['evaluation'] ?? '') !== '')
                                <span class="opcrf-sheet-band">{{ $bands['evaluation'] }}</span>
                            @endif
                        </div>
                    @endif

                    <thead>
                        <tr>
                            <th class="opcrf-sheet-num" scope="col">#</th>
                            <th class="opcrf-sheet-obj" scope="col">{{ $heading('objective', 'Objectives', '(based on Office Functions)') }}</th>
                            <th class="opcrf-sheet-time" scope="col">{{ $heading('timeline', 'Timeline') }}</th>
                            <th class="opcrf-sheet-wcol" scope="col">{{ $heading('weight', 'Weight', 'Allocation') }}</th>
                            <th class="opcrf-sheet-crit" scope="col">{{ $heading('measure', 'Performance Measure', '(Quality, Efficiency, Timeliness)') }}</th>
                            <th class="opcrf-sheet-scale" scope="col">{{ $heading('scale', 'Rating Scale', '5 Outstanding → 1 Poor') }}</th>
                            <th class="opcrf-sheet-acc" scope="col">{{ $heading('accomplishments', 'Actual Accomplishments') }}</th>
                            <th class="opcrf-sheet-rate" scope="col">{{ $heading('rating', 'Rating', '(Q, E, T)') }}</th>
                            <th class="opcrf-sheet-wcol" scope="col">{{ $heading('average', 'Average', '(QET)') }}</th>
                            <th class="opcrf-sheet-wcol" scope="col">{{ $heading('weighted_average', 'Weighted Average') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($part['rows'] as $entry)
                            @foreach ($entry['criteria'] as $ci => $crit)
                                <tr wire:key="objrow-{{ $part['key'] }}-{{ $entry['row'] }}-{{ $ci }}-{{ $full ? 'staff' : 'review' }}">
                                    @if ($ci === 0)
                                        <td class="opcrf-sheet-num" rowspan="{{ count($entry['criteria']) }}">{{ $entry['number'] }}</td>
                                        <td class="opcrf-sheet-obj" rowspan="{{ count($entry['criteria']) }}">
                                            {{ $entry['objectives'] !== '' ? $entry['objectives'] : '—' }}

                                            {{-- The template's block-level planning
                                                 columns, typed beside the objective
                                                 and merged down its rows. --}}
                                            @if (($entry['kra'] ?? '') !== '')
                                                <span class="opcrf-sheet-cellsub"><strong>KRA:</strong> {{ $entry['kra'] }}</span>
                                            @endif
                                            @if (($entry['attribution'] ?? '') !== '')
                                                <span class="opcrf-sheet-cellsub"><strong>Organizational Outcome Attribution:</strong> {{ $entry['attribution'] }}</span>
                                            @endif
                                            @if (($entry['target_description'] ?? '') !== '' || ($entry['target_value'] ?? '') !== '')
                                                <span class="opcrf-sheet-cellsub">
                                                    <strong>Performance Target:</strong>
                                                    @if (($entry['target_value'] ?? '') !== '') {{ $entry['target_value'] }} @endif
                                                    {{ $entry['target_description'] ?? '' }}
                                                </span>
                                            @endif
                                            @if (($entry['movs'] ?? '') !== '')
                                                <span class="opcrf-sheet-cellsub"><strong>Means of Verification:</strong> {{ $entry['movs'] }}</span>
                                            @endif
                                        </td>
                                        <td class="opcrf-sheet-time" rowspan="{{ count($entry['criteria']) }}">{{ $entry['timeline'] !== '' ? $entry['timeline'] : '—' }}</td>
                                        <td class="opcrf-sheet-wcol" rowspan="{{ count($entry['criteria']) }}">{{ isset($entry['weight']) && $entry['weight'] !== '' ? (is_numeric($entry['weight']) ? rtrim(rtrim(number_format((float) $entry['weight'], 4, '.', ''), '0'), '.') : $entry['weight']) : '—' }}</td>
                                    @endif
                                    <td class="opcrf-sheet-crit">{{ $crit['label'] !== '' ? $crit['label'] : '—' }}</td>
                                    <td class="opcrf-sheet-scale">
                                        @forelse ($crit['scale'] ?? [] as $scaleItem)
                                            <span class="opcrf-sheet-scalelevel"><b>{{ $scaleItem['level'] }}</b> {{ $scaleItem['text'] }}</span>
                                        @empty
                                            —
                                        @endforelse
                                    </td>
                                    <td class="opcrf-sheet-acc">{{ $crit['accomplishments'] !== '' ? $crit['accomplishments'] : '—' }}</td>
                                    <td class="opcrf-sheet-rate">{{ $crit['rating'] !== null ? (fmod($crit['rating'], 1.0) === 0.0 ? number_format($crit['rating']) : number_format($crit['rating'], 2)) : '—' }}</td>
                                    @if ($ci === 0)
                                        <td class="opcrf-sheet-wcol" rowspan="{{ count($entry['criteria']) }}">{{ $entry['average'] !== null ? number_format($entry['average'], 2) : '—' }}</td>
                                        <td class="opcrf-sheet-wcol" rowspan="{{ count($entry['criteria']) }}">{{ $entry['weighted_average'] !== null ? number_format($entry['weighted_average'], 2) : '—' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="10" class="opcrf-sheet-empty">No objectives were found in this part.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        @if ($part['has_total_row'])
                            <tr class="opcrf-sheet-totalrow">
                                <td colspan="9">PART {{ $part['key'] }} TOTAL SCORE</td>
                                <td class="opcrf-sheet-rate">{{ $part['total_score'] !== null ? number_format($part['total_score'], 2) : '—' }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td colspan="9">AVERAGE (QET)</td>
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
