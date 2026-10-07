{{-- THE WHOLE WORKBOOK, EXCEL-WINDOW STYLE: a sheet-tab strip at the
     bottom ("PART I", "PART II", "PART III", "PART IV" — plus one tab per
     extra sheet) filtering the worksheets above it, exactly like Excel's
     own tab bar. It opens on PART I; there is no combined "All" view —
     one tab at a time. Expects $sheet (the main layout array), $rating
     (string), $full (bool, kept for the shared partial's contract),
     $workbook (the workbook() array) and $uid (a per-instance key
     prefix).

     Alpine holds the active tab; [data-sheet] sections show/hide without
     any Livewire round-trip. --}}
@php $wbUid = $uid ?? 'wb'; @endphp

<div
    class="opcrf-excelwin"
    x-data="{ tab: 'part1' }"
    :data-active="tab"
>
    {{-- SHEET AREA: the main PART I sheet is always present; the other
         tabs' sections carry data-sheet attributes from their partial. --}}
    <div class="opcrf-excelwin-sheets">
        <div data-sheet="part1">
            @include('livewire.opcrf.partials.opcrf-sheet', [
                'sheet' => $sheet,
                'rating' => $rating,
                'full' => $full,
            ])
        </div>

        @include('livewire.opcrf.partials.opcrf-workbook', [
            'workbook' => $workbook,
            'uid' => $uid,
        ])
    </div>

    {{-- SHEET-TAB STRIP: Excel's bottom tab bar — one sheet at a time,
         opening on PART I. Tabs only appear for content the file
         actually carries. --}}
    <div class="opcrf-excelwin-tabs" role="tablist" aria-label="Workbook sheets">
        <button
            type="button"
            role="tab"
            class="opcrf-excelwin-tab"
            :class="tab === 'part1' ? 'is-active' : ''"
            @click="tab = 'part1'"
            wire:key="{{ $wbUid }}-tab-part1"
        >
            PART I
        </button>

        @if (! empty($workbook['part_two']['sections']))
            <button
                type="button"
                role="tab"
                class="opcrf-excelwin-tab"
                :class="tab === 'part2' ? 'is-active' : ''"
                @click="tab = 'part2'"
                wire:key="{{ $wbUid }}-tab-part2"
            >
                PART II
            </button>
        @endif

        @if (! empty($workbook['part_three']['components']) || ! empty($workbook['part_three']['agreement']))
            <button
                type="button"
                role="tab"
                class="opcrf-excelwin-tab"
                :class="tab === 'part3' ? 'is-active' : ''"
                @click="tab = 'part3'"
                wire:key="{{ $wbUid }}-tab-part3"
            >
                PART III
            </button>
        @endif

        @if (! empty($workbook['part_four']['office_plan']) || ! empty($workbook['part_four']['development_plan']) || ! empty($workbook['part_four']['signers']))
            <button
                type="button"
                role="tab"
                class="opcrf-excelwin-tab"
                :class="tab === 'part4' ? 'is-active' : ''"
                @click="tab = 'part4'"
                wire:key="{{ $wbUid }}-tab-part4"
            >
                PART IV
            </button>
        @endif

        @foreach ($workbook['extra_sheets'] as $si => $extra)
            <button
                type="button"
                role="tab"
                class="opcrf-excelwin-tab"
                :class="tab === 'extra{{ $si }}' ? 'is-active' : ''"
                @click="tab = 'extra{{ $si }}'"
                wire:key="{{ $wbUid }}-tab-xs-{{ $si }}"
            >
                {{ \Illuminate\Support\Str::limit($extra['name'], 14) }}
            </button>
        @endforeach
    </div>
</div>
