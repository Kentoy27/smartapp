{{-- Shared OPCRF sheet renderer for staff review and administrator review. --}}
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

    @if (trim((string) ($sheet['purpose']['label'] ?? '')) !== '' || trim((string) ($sheet['purpose']['value'] ?? '')) !== '')
        <div class="opcrf-sheet-purpose">
            <strong>{{ $sheet['purpose']['label'] }}</strong>
            <p>{{ $sheet['purpose']['value'] !== '' ? $sheet['purpose']['value'] : '—' }}</p>
        </div>
    @endif

    @foreach ($sheet['parts'] as $pi => $part)
        @php
            $captions = $part['captions'] ?? [];
            $fields = $part['fields'] ?? [
                'kra', 'attribution', 'objective', 'timeline', 'weight',
                'target_value', 'target_description', 'measure', 'scale',
                'movs', 'accomplishments', 'rating', 'average', 'weighted_average',
            ];
            $columnOrder = [
                'kra', 'attribution', 'objective', 'timeline', 'weight',
                'target_value', 'target_description', 'measure', 'scale',
                'movs', 'accomplishments', 'rating', 'average', 'weighted_average',
            ];
            $columns = array_values(array_filter(
                $columnOrder,
                fn (string $field): bool => in_array($field, $fields, true)
            ));
            $columnCount = count($columns) + 1;
            $bands = $part['bands'] ?? ['planning' => '', 'evaluation' => ''];
            $heading = function (string $field, string $main, string $sub = '') use ($captions): string {
                $caption = trim((string) ($captions[$field] ?? ''));

                return $caption !== '' ? $caption : ($sub === '' ? $main : $main.' '.$sub);
            };
            $columnClasses = [
                'kra' => 'opcrf-sheet-kra',
                'attribution' => 'opcrf-sheet-attribution',
                'objective' => 'opcrf-sheet-obj',
                'timeline' => 'opcrf-sheet-time',
                'weight' => 'opcrf-sheet-weight',
                'target_value' => 'opcrf-sheet-target',
                'target_description' => 'opcrf-sheet-target',
                'measure' => 'opcrf-sheet-crit',
                'scale' => 'opcrf-sheet-scale',
                'movs' => 'opcrf-sheet-movs',
                'accomplishments' => 'opcrf-sheet-acc',
                'rating' => 'opcrf-sheet-rate',
                'average' => 'opcrf-sheet-average',
                'weighted_average' => 'opcrf-sheet-weighted-average',
            ];
            $blockFields = [
                'kra', 'attribution', 'objective', 'timeline', 'weight',
                'target_value', 'target_description', 'movs', 'average', 'weighted_average',
            ];
        @endphp

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

            <div class="opcrf-sheet-tablewrap">
                <table class="opcrf-sheet-table opcrf-sheet-table--objectives">
                    <thead>
                        <tr>
                            <th class="opcrf-sheet-num" scope="col">#</th>
                            @foreach ($columns as $field)
                                <th class="{{ $columnClasses[$field] ?? '' }}" scope="col">
                                    @switch($field)
                                        @case('kra') {{ $heading('kra', 'Key Result Areas (KRA)') }} @break
                                        @case('attribution') {{ $heading('attribution', 'Organizational Outcome Attribution') }} @break
                                        @case('objective') {{ $heading('objective', 'Objectives', '(based on Office Functions)') }} @break
                                        @case('timeline') {{ $heading('timeline', 'Timeline') }} @break
                                        @case('weight') {{ $heading('weight', 'Weight Allocation') }} @break
                                        @case('target_value')
                                            <span>Performance Targets</span>
                                            <span class="opcrf-sheet-th-sub">Value</span>
                                            @break
                                        @case('target_description')
                                            <span>Performance Targets</span>
                                            <span class="opcrf-sheet-th-sub">Description</span>
                                            @break
                                        @case('measure') {{ $heading('measure', 'Performance Measure', '(Quality, Efficiency, Timeliness)') }} @break
                                        @case('scale') {{ $heading('scale', 'Rating Scale', '5 Outstanding → 1 Poor') }} @break
                                        @case('movs') {{ $heading('movs', 'Means of Verification (MOVs)') }} @break
                                        @case('accomplishments') {{ $heading('accomplishments', 'Actual Accomplishments') }} @break
                                        @case('rating') {{ $heading('rating', 'Rating (Q, E, T)') }} @break
                                        @case('average') {{ $heading('average', 'Average (QET)') }} @break
                                        @case('weighted_average') {{ $heading('weighted_average', 'Weighted Average') }} @break
                                    @endswitch
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($part['rows'] as $entry)
                            @forelse ($entry['criteria'] as $ci => $crit)
                                <tr wire:key="objrow-{{ $part['key'] }}-{{ $entry['row'] }}-{{ $ci }}-{{ $full ? 'staff' : 'review' }}">
                                    @if ($ci === 0)
                                        <td class="opcrf-sheet-num" rowspan="{{ max(1, count($entry['criteria'])) }}">{{ $entry['number'] }}</td>
                                    @endif
                                    @foreach ($columns as $field)
                                        @if (! in_array($field, $blockFields, true) || $ci === 0)
                                            <td class="{{ $columnClasses[$field] ?? '' }}" @if (in_array($field, $blockFields, true)) rowspan="{{ max(1, count($entry['criteria'])) }}" @endif>
                                                @switch($field)
                                                    @case('kra') {{ $entry['kra'] !== '' ? $entry['kra'] : '—' }} @break
                                                    @case('attribution') {{ $entry['attribution'] !== '' ? $entry['attribution'] : '—' }} @break
                                                    @case('objective') {{ $entry['objectives'] !== '' ? $entry['objectives'] : '—' }} @break
                                                    @case('timeline') {{ $entry['timeline'] !== '' ? $entry['timeline'] : '—' }} @break
                                                    @case('weight')
                                                        {{ $entry['weight'] !== '' ? (is_numeric($entry['weight']) ? rtrim(rtrim(number_format((float) $entry['weight'], 4, '.', ''), '0'), '.') : $entry['weight']) : '—' }}
                                                        @break
                                                    @case('target_value') {{ $entry['target_value'] !== '' ? $entry['target_value'] : '—' }} @break
                                                    @case('target_description') {{ $entry['target_description'] !== '' ? $entry['target_description'] : '—' }} @break
                                                    @case('measure') {{ $crit['label'] !== '' ? $crit['label'] : '—' }} @break
                                                    @case('scale')
                                                        @forelse ($crit['scale'] ?? [] as $scaleItem)
                                                            <span class="opcrf-sheet-scalelevel"><b>{{ $scaleItem['level'] }}</b> {{ $scaleItem['text'] }}</span>
                                                        @empty
                                                            —
                                                        @endforelse
                                                        @break
                                                    @case('movs') {{ $entry['movs'] !== '' ? $entry['movs'] : '—' }} @break
                                                    @case('accomplishments') {{ $crit['accomplishments'] !== '' ? $crit['accomplishments'] : '—' }} @break
                                                    @case('rating') {{ $crit['rating'] !== null ? (fmod($crit['rating'], 1.0) === 0.0 ? number_format($crit['rating']) : number_format($crit['rating'], 2)) : '—' }} @break
                                                    @case('average') {{ $entry['average'] !== null ? number_format($entry['average'], 2) : '—' }} @break
                                                    @case('weighted_average') {{ $entry['weighted_average'] !== null ? number_format($entry['weighted_average'], 2) : '—' }} @break
                                                @endswitch
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @empty
                                <tr wire:key="objrow-{{ $part['key'] }}-{{ $entry['row'] }}-empty-{{ $full ? 'staff' : 'review' }}">
                                    <td class="opcrf-sheet-num">{{ $entry['number'] }}</td>
                                    @foreach ($columns as $field)
                                        <td class="{{ $columnClasses[$field] ?? '' }}">
                                            @switch($field)
                                                @case('kra') {{ $entry['kra'] !== '' ? $entry['kra'] : '—' }} @break
                                                @case('attribution') {{ $entry['attribution'] !== '' ? $entry['attribution'] : '—' }} @break
                                                @case('objective') {{ $entry['objectives'] !== '' ? $entry['objectives'] : '—' }} @break
                                                @case('timeline') {{ $entry['timeline'] !== '' ? $entry['timeline'] : '—' }} @break
                                                @case('weight') {{ $entry['weight'] !== '' ? $entry['weight'] : '—' }} @break
                                                @case('target_value') {{ $entry['target_value'] !== '' ? $entry['target_value'] : '—' }} @break
                                                @case('target_description') {{ $entry['target_description'] !== '' ? $entry['target_description'] : '—' }} @break
                                                @case('measure') —
                                                    @break
                                                @case('scale') —
                                                    @break
                                                @case('movs') {{ $entry['movs'] !== '' ? $entry['movs'] : '—' }} @break
                                                @case('accomplishments') —
                                                    @break
                                                @case('rating') —
                                                    @break
                                                @case('average') {{ $entry['average'] !== null ? number_format($entry['average'], 2) : '—' }} @break
                                                @case('weighted_average') {{ $entry['weighted_average'] !== null ? number_format($entry['weighted_average'], 2) : '—' }} @break
                                            @endswitch
                                        </td>
                                    @endforeach
                                </tr>
                            @endforelse
                        @empty
                            <tr>
                                <td colspan="{{ $columnCount }}" class="opcrf-sheet-empty">No objectives were found in this part.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($part['has_total_row'])
                        <tfoot>
                            <tr class="opcrf-sheet-totalrow">
                                <td colspan="{{ max(1, $columnCount - 1) }}">PART {{ $part['key'] }} TOTAL SCORE</td>
                                <td class="opcrf-sheet-rate opcrf-sheet-summary-value">{{ $part['total_score'] !== null ? number_format($part['total_score'], 2) : '—' }}</td>
                            </tr>
                        </tfoot>
                    @endif
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
                @include('livewire.opcrf.partials.opcrf-signer', [
                    'signer' => $signer,
                    'keyPrefix' => 'signer-'.($full ? 'staff' : 'review'),
                    'rateeSignature' => $rateeSignature ?? null,
                    'reviewSignatures' => $reviewSignatures ?? [],
                ])
            @endforeach
        </div>
    </div>
@endif
