<div
    class="opcrf-taskbar"
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    wire:poll.15s
>
    @php($returnedTaskGroups = $this->returnedTasks)
    @php($revisionCount = count($returnedTaskGroups))
    @php($openTaskCount = collect($returnedTaskGroups)->sum(fn ($group) => count($group['tasks']) - $group['completed']))

    <button
        type="button"
        class="opcrf-taskbar-trigger"
        :class="open ? 'open' : ''"
        @click="open = !open"
        aria-haspopup="true"
        :aria-expanded="String(open)"
        aria-label="{{ $revisionCount > 0 ? $revisionCount.' OPCRF'.($revisionCount === 1 ? '' : 's').' requiring revision' : 'Revision tasks' }}"
        title="Revision tasks"
    >
        <x-icon name="clipboard-list" :size="19" />
        <span class="opcrf-taskbar-trigger-label">Tasks</span>
        @if ($revisionCount > 0)
            <span class="opcrf-taskbar-badge" aria-hidden="true">{{ $revisionCount > 9 ? '9+' : $revisionCount }}</span>
        @endif
    </button>

    <div class="opcrf-taskbar-panel" :class="open ? 'open' : ''" role="dialog" aria-label="Revision tasks">
        <div class="opcrf-taskbar-head">
            <span class="opcrf-taskbar-title">Revision tasks</span>
            @if ($revisionCount > 0)
                <span class="opcrf-taskbar-count">
                    {{ $openTaskCount > 0 ? $openTaskCount.' remarks to address' : 'Resubmission required' }}
                </span>
            @endif
        </div>

        <div class="opcrf-taskbar-list">
            @forelse ($returnedTaskGroups as $group)
                @php($submission = $group['submission'])
                <section class="opcrf-taskbar-submission" wire:key="revision-tasks-{{ $submission->id }}">
                    <div class="opcrf-taskbar-submission-heading">
                        <strong>{{ $submission->partLabel() }}</strong>
                        <span>{{ $submission->review_period !== '' ? $submission->review_period : 'Returned for revision' }}</span>
                    </div>
                    @if ($submission->latestReview?->reviewer)
                        <p class="opcrf-taskbar-reviewer">Remarks from {{ $submission->latestReview->reviewer->username }}</p>
                    @endif

                    @forelse ($group['tasks'] as $task)
                        <div class="opcrf-taskbar-item" wire:key="revision-task-{{ $submission->id }}-{{ $task['key'] }}">
                            <button
                                type="button"
                                class="opcrf-taskbar-check {{ $task['completed'] ? 'is-completed' : '' }}"
                                role="checkbox"
                                aria-checked="{{ $task['completed'] ? 'true' : 'false' }}"
                                aria-label="{{ ($task['completed'] ? 'Mark incomplete: ' : 'Mark complete: ').$task['label'] }}"
                                wire:click="toggleTask({{ $submission->id }}, {{ \Illuminate\Support\Js::from($task['key']) }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleTask"
                            >
                                @if ($task['completed'])
                                    <x-icon name="check" :size="12" />
                                @endif
                            </button>
                            <a
                                class="opcrf-taskbar-item-link"
                                href="{{ route('opcrf.index', ['revision' => $submission->id]) }}"
                                wire:navigate
                                @click="open = false"
                            >
                                <span class="opcrf-taskbar-item-label">{{ $task['label'] }}</span>
                                <span class="opcrf-taskbar-item-remark">{{ $task['remark'] }}</span>
                            </a>
                        </div>
                    @empty
                        <p class="opcrf-taskbar-empty">Open this revision to review the Superadmin's instructions.</p>
                    @endforelse

                    <a
                        class="opcrf-taskbar-revise"
                        href="{{ route('opcrf.index', ['revision' => $submission->id]) }}"
                        wire:navigate
                        @click="open = false"
                    >
                        <x-icon name="pen-line" :size="14" />
                        <span>Revise &amp; Resubmit</span>
                    </a>
                </section>
            @empty
                <p class="opcrf-taskbar-empty">No OPCRF revisions are waiting for you.</p>
            @endforelse
        </div>
    </div>
</div>
