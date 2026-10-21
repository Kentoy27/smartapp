{{-- WFP UPLOADS: the superadmin's dashboard card — every school's
     uploaded Work and Financial Plan, newest first.

     The other half of the WfpUpload flow on /wfp: a School Head uploads
     their completed workbook there, and each school's current file appears
     here with a download that serves the archived copy. Polls like the
     rest of the dashboard's data-driven cards, so an upload made moments
     ago lands here without a reload. --}}
<div class="card wfp-uploads-list" wire:poll.15s>
    <div class="card-head">
        <div class="card-title">{{ auth()->user()?->canReviewAllOpcrfSubmissions() ? 'WFP Review' : 'WFP Uploads' }}</div>
        <span class="wfp-uploads-count">
            {{ $this->schoolCount() }} {{ $this->schoolCount() === 1 ? 'school' : 'schools' }} uploaded
        </span>
    </div>

    @if ($this->submissions()->isEmpty())
        <p class="card-text">
            No WFP uploads yet. Every school's uploaded Work and Financial
            Plan will be listed here.
        </p>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>School</th>
                        <th>Uploaded by</th>
                        <th>File</th>
                        <th>School year</th>
                        <th>Size</th>
                        <th>Uploaded</th>
                        <th class="users-actions-col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->submissions() as $submission)
                        <tr wire:key="wfp-upload-{{ $submission->id }}">
                            <td>
                                <span class="cell-strong">{{ $submission->schoolLabel() }}</span>
                            </td>
                            <td>{{ $submission->user?->username ?? '—' }}</td>
                            <td>
                                {{ $submission->original_file_name }}
                                @if ($submission->status !== null)
                                    <span class="cell-sub">{{ ucfirst($submission->status) }}</span>
                                @endif
                            </td>
                            <td>{{ $submission->school_year ?? '—' }}</td>
                            <td>{{ $submission->human_size }}</td>
                            <td>{{ $submission->uploaded_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="users-actions-col">
                                @if ($submission->hasFile())
                                    <a
                                        href="{{ route('wfp.submission.download', $submission) }}"
                                        class="btn-ghost"
                                        title="Download {{ $submission->schoolLabel() }}'s WFP"
                                    >
                                        <x-icon name="download" :size="14" />
                                        <span>Download</span>
                                    </a>
                                @else
                                    <span class="cell-sub">File missing</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
