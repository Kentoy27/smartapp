<?php

namespace App\Livewire;

use App\Models\MovCategory;
use App\Models\MovPart;
use App\Models\MovRequirement;
use App\Models\UserMov;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Upload MOV" — the staff member's Means of Verification checklist.
 *
 * The page is a rail plus the checklist: the rail carries the progress and
 * lists "All" and every Part → Category (A/B/C), and picking one narrows the
 * checklist to it. Below it the checklist itself is Part → Category → MOV,
 * each level collapsing so a long checklist stays short on screen. It can
 * also be arrived at one MOV deep (`?mov=101`), which is what the sidebar's
 * tree links to: the page then opens that MOV's own Part and category and
 * marks the card, so a line three levels down is one click from anywhere.
 *
 * A MOV accepts pictures and common source documents, individually or in
 * batches, with no cap on the number of files attached. Each file is its own
 * record with its own review state (Uploaded / Under Review / Accepted /
 * Returned for Revision), so a reviewer can accept one and return another.
 *
 * Nothing about the checklist is hardcoded here: the parts, categories and
 * requirements are rows built from config/mov.php by `php artisan mov:sync`,
 * so adding MOV 5 — or a whole new Part — is a config edit and a sync.
 *
 * Everything is scoped to the signed-in user. A requirement id that does not
 * belong to the checklist is a 404, and a picture can only ever be added to
 * or removed from the person doing the uploading.
 */
#[Title('Upload MOV — SmartApp')]
class MovUploader extends Component
{
    use WithFileUploads;

    /**
     * Free-text search over part, category, MOV number, title and
     * description. While it is filled, matches are shown with their parents
     * forced open — searching is for finding one line, not for re-reading
     * the whole checklist.
     */
    public string $search = '';

    /**
     * The category the checklist is narrowed to, or null for the whole
     * checklist ("All").
     *
     * The URL is the only place this lives. Both the page's own rail and
     * the sidebar's dropdown are links to /movs?category=N, so the section
     * on screen survives a refresh, the back button and a shared link — and
     * every entry that names a section reads it from one place.
     *
     * It is not trusted: see scope(), which resolves whatever arrives here
     * into a section of this page or into "All".
     */
    #[Url(as: 'category')]
    public ?int $category = null;

    /**
     * The MOV the page is asked to show, by requirement id — the sidebar's
     * tree links each MOV to `/movs?mov=101`, so a line three levels deep is
     * one click from anywhere, and the URL says which MOV is on screen.
     *
     * An id, never a MOV number: MOV 1 under Part 1 → A and MOV 1 under
     * Part 1 → B are different requirements, and only the id says which one
     * was asked for. Resolved, not trusted (see mount()).
     */
    #[Url(as: 'mov')]
    public ?int $mov = null;

    /**
     * The requirement the URL resolved to, which is the one the page opens
     * its parents for, scrolls to and marks. Null when the URL named none, or
     * named one that is not on the checklist.
     */
    public ?int $focused = null;

    /**
     * Which parts are open, by id.
     *
     * @var array<int, true>
     */
    public array $openParts = [];

    /**
     * Which categories are open, keyed "part:category".
     *
     * @var array<string, true>
     */
    public array $openCategories = [];

    /**
     * The requirement whose upload form is open, if any.
     *
     * Every MOV carries an upload area now, so nothing is "opened" any more;
     * this is the fallback for a call that names no requirement itself.
     */
    public ?int $uploadingFor = null;

    /**
     * The requirement the last upload attempt was made for, so a rejected
     * file's message appears on that MOV's own upload area and not on all
     * of them.
     */
    public ?int $uploadFailedFor = null;

    /**
     * Files chosen for that requirement (temporary until saved).
     */
    public array $documents = [];

    /**
     * Backward-compatible single-file property for existing Livewire callers.
     */
    public $document;

    /**
     * The picture whose removal is waiting on a confirmation, if any.
     */
    public ?int $removingId = null;

    /**
     * The picture selected for explicit replacement, if any.
     */
    public ?int $replacingId = null;

    /**
     * Toast text after an upload or a removal; cleared once shown, so a poll
     * never re-fires it.
     */
    public ?string $successMessage = null;

    /**
     * Start with the first part open: a checklist whose every line is
     * collapsed reads as empty, but opening all of it reads as a wall.
     */
    public function mount(): void
    {
        // Every MOV below belongs to somebody. Without this the render
        // reaches UserMov::scopeForUser(null) and dies with a TypeError —
        // a 500, not a refusal — so the guard is what turns "no user" into
        // a clean 404.
        abort_unless(Auth::check(), 404);

        $first = MovPart::query()->active()->orderBy('part_order')->orderBy('id')->first();

        if ($first !== null) {
            $this->openParts[$first->id] = true;
        }

        $this->focusOn();
    }

    /**
     * Arriving on a MOV (`?mov=101`): open the Part and the category it sits
     * under, so the MOV is on the page at all, and remember it so the view can
     * scroll to it and mark it.
     *
     * An id that is not an active requirement of the checklist is not an
     * error page — a link shared before a MOV was retired still shows the
     * checklist — so it simply focuses nothing.
     */
    private function focusOn(): void
    {
        if ($this->mov === null) {
            return;
        }

        $requirement = MovRequirement::query()
            ->active()
            ->with('category.part')
            ->find($this->mov);

        if ($requirement?->category?->part === null) {
            return;
        }

        $partId = $requirement->category->part->id;

        $this->openParts[$partId] = true;
        $this->openCategories[$partId.':'.$requirement->category->id] = true;
        $this->focused = $requirement->id;
    }

    public function render()
    {
        return view('livewire.mov.uploader');
    }

    /**
     * Clicked a part header: open or close it. Opening a part does not touch
     * its categories — those are a deliberate second click.
     */
    public function togglePart(int $partId): void
    {
        $this->toggle($this->openParts, $partId);
    }

    /**
     * Clicked a category header: open or close it.
     */
    public function toggleCategory(int $partId, int $categoryId): void
    {
        $this->toggle($this->openCategories, $partId.':'.$categoryId);
    }

    /**
     * Open the upload form for one requirement.
     */
    public function openUpload(int $requirementId): void
    {
        $this->assertStaffMember();
        $this->assertRequirement($requirementId);

        $this->uploadingFor = $requirementId;
        $this->documents = [];
        $this->document = null;
        $this->removingId = null;
        $this->resetValidation();
    }

    /**
     * Leave the upload form without saving.
     */
    public function cancelUpload(): void
    {
        $this->uploadingFor = null;
        $this->replacingId = null;
        $this->documents = [];
        $this->document = null;
        $this->resetValidation();
    }

    /**
     * Choose one existing picture to replace. Other pictures for this MOV
     * remain untouched.
     */
    public function beginReplace(int $documentId): void
    {
        $this->assertStaffMember();

        $picture = UserMov::forUser(Auth::user())->findOrFail($documentId);
        $this->uploadingFor = $picture->mov_requirement_id;
        $this->replacingId = $picture->id;
        $this->documents = [];
        $this->document = null;
        $this->removingId = null;
        $this->resetValidation();
    }

    /**
     * Preserve the original Livewire action name for existing callers.
     */
    public function upload(?int $requirementId = null): void
    {
        $this->saveDocuments($requirementId);
    }

    /**
     * Add every chosen file to the requirement. There is no cap on the number
     * of files a MOV can hold; later batches append rather than replace.
     *
     * Every MOV has its own upload area on the page, so the requirement is
     * named by the MOV whose area was used — a file is attached to the MOV it
     * was picked on and to no other. Naming nothing falls back to the one
     * `openUpload()` marked.
     */
    public function saveDocuments(?int $requirementId = null): void
    {
        $this->assertStaffMember();

        $requirement = $this->assertRequirement((int) ($requirementId ?? $this->uploadingFor));

        $files = $this->selectedDocuments();

        if ($files !== [] && $this->replacingId !== null && count($files) !== 1) {
            $this->addError('documents', 'Choose exactly one file when replacing evidence.');
            $this->uploadFailedFor = $requirement->id;

            return;
        }

        // Set before validating so a rejected file's message has a MOV to
        // appear on, and cleared the moment the upload succeeds.
        $this->uploadFailedFor = $requirement->id;
        $this->documents = $files;
        $this->document = null;

        $this->validate(
            [
                'documents' => ['required', 'array', 'min:1'],
                'documents.*' => $this->documentRules(),
            ],
            [
                'documents.required' => 'Choose at least one file to upload.',
                'documents.min' => 'Choose at least one file to upload.',
                'documents.*.mimes' => 'Upload a supported image or document: '.strtoupper(implode(', ', $this->allowedExtensions())).'.',
                'documents.*.extensions' => 'Upload a supported image or document: '.strtoupper(implode(', ', $this->allowedExtensions())).'.',
                'documents.*.max' => 'Each file must be no larger than '.number_format($this->maxKilobytes() / 1024, 1).' MB.',
            ],
        );

        $disk = Storage::disk(config('mov.disk', 'local'));
        $replacingPicture = $this->replacingId !== null
            ? UserMov::forUser(Auth::user())
                ->where('mov_requirement_id', $requirement->id)
                ->findOrFail($this->replacingId)
            : null;
        $isReplacing = $replacingPicture !== null;

        $savedFiles = [];

        foreach ($files as $file) {
            /** @var TemporaryUploadedFile $file */
            $originalName = (string) $file->getClientOriginalName();
            $path = $file->storeAs(
                config('mov.directory', 'mov-uploads').'/'.Auth::id(),
                $this->storedFileName($originalName),
                config('mov.disk', 'local'),
            );

            $attributes = [
                'original_name' => $this->displayName($originalName),
                'stored_path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'status' => UserMov::STATUS_UPLOADED,
                'uploaded_at' => now(),
                'remarks' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ];

            if ($replacingPicture !== null) {
                $oldPath = $replacingPicture->stored_path;
                $picture = $replacingPicture;
                $picture->update($attributes);
                $disk->delete($oldPath);
                $replacingPicture = null;
            } else {
                $picture = UserMov::create([
                    'user_id' => Auth::id(),
                    'mov_requirement_id' => $requirement->id,
                    ...$attributes,
                ]);
            }

            $savedFiles[] = $picture->original_name;
        }

        $this->uploadingFor = null;
        $this->replacingId = null;
        $this->uploadFailedFor = null;
        $this->documents = [];
        $this->document = null;
        $this->removingId = null;
        $this->resetValidation();

        unset($this->tree, $this->sections, $this->scope, $this->progress);

        $action = $isReplacing ? 'replaced' : 'added';
        $this->successMessage = $requirement->label().' — '.count($savedFiles).' '
            .Str::plural('file', count($savedFiles)).' '.$action.' ('
            .UserMov::forUser(Auth::user())->where('mov_requirement_id', $requirement->id)->count()
            .' total).';
    }

    /**
     * Remove one picture, asked for twice: it is the person's evidence, and
     * the other pictures of the same MOV stay untouched.
     */
    public function confirmRemove(int $documentId): void
    {
        $this->assertStaffMember();

        $picture = UserMov::forUser(Auth::user())->find($documentId);

        if ($picture !== null) {
            $requirement = $picture->requirement;
            $picture->delete(); // and its file, with it

            $this->successMessage = $requirement?->label().' — '.$picture->original_name.' removed.';
        }

        $this->removingId = null;
    }

    /**
     * Dismiss the removal confirmation without removing anything.
     */
    public function cancelRemove(): void
    {
        $this->removingId = null;
    }

    /**
     * Clear the toast once it has been shown.
     */
    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    /**
     * The checklist as the page renders it: parts → categories → MOVs, each
     * carrying the signed-in user's pictures for that requirement and the
     * per-level counts for the headers.
     *
     * Pruned by the rail (one category, or all of them) and then by the
     * search box; with neither, this is the whole checklist.
     *
     * A search deliberately reaches past the rail's category — finding one
     * MOV should never depend on having guessed the right letter first — and
     * when it does, the rail says so rather than quietly under-listing.
     *
     * @return array<int, array{id: int, name: string, open: bool, total: int, done: int, categories: array<int, array<string, mixed>>}>
     */
    #[Computed]
    public function tree(): array
    {
        $needle = Str::lower(trim($this->search));
        $searching = $needle !== '';
        $scopedId = ! $searching ? $this->scope()['id'] : null;

        /** @var Collection<int, Collection<int, UserMov>> $pictures */
        $pictures = UserMov::forUser(Auth::user())
            ->with('reviewer')
            ->oldest('id')
            ->get()
            ->groupBy('mov_requirement_id');

        $parts = $this->checklist();

        return $parts
            ->map(function (MovPart $part) use ($pictures, $needle, $searching, $scopedId): ?array {
                $categories = [];

                foreach ($part->categories as $category) {
                    if ($scopedId !== null && $category->id !== $scopedId) {
                        continue;
                    }

                    $movs = [];

                    foreach ($category->requirements as $requirement) {
                        $matches = ! $searching || $this->requirementMatches($requirement, $needle, $category, $part);

                        if ($matches) {
                            $movs[] = $this->movLine($requirement, $pictures->get($requirement->id, collect()));
                        }
                    }

                    // An empty category is shown while browsing (it is part
                    // of the checklist's shape) but not in search results,
                    // where it would be noise.
                    if ($movs === [] && $searching) {
                        continue;
                    }

                    $categories[] = [
                        'id' => $category->id,
                        'part_id' => $part->id,
                        'name' => $category->name,
                        'trail' => $category->trail(),
                        // Scoped or searching, the one category that is left
                        // is the answer, so it arrives open.
                        'open' => $scopedId !== null || $searching || isset($this->openCategories[$part->id.':'.$category->id]),
                        'movs' => $movs,
                        'total' => count($movs),
                        'done' => $this->countDone($movs),
                    ];
                }

                if ($categories === []) {
                    return null;
                }

                $allMovs = array_merge(...array_column($categories, 'movs'));

                return [
                    'id' => $part->id,
                    'name' => $part->name,
                    'open' => $scopedId !== null || $searching || isset($this->openParts[$part->id]),
                    'categories' => $categories,
                    'total' => count($allMovs),
                    'done' => $this->countDone($allMovs),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The one MOV on screen, with its Part and category around it.
     *
     * The page shows a MOV at a time — you choose it from the sidebar and
     * arrive on `?mov=N` — so this is the tree narrowed to the branch that
     * holds it. Without the narrowing the reader would be handed the whole
     * open category, which is the list they just chose to leave.
     *
     * Nothing is returned when no MOV is chosen: there is nothing to show,
     * so the page shows nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function focusedTree(): array
    {
        if ($this->focused === null) {
            return [];
        }

        return collect($this->tree())
            ->map(function (array $part): array {
                $part['categories'] = collect($part['categories'])
                    ->map(function (array $category): array {
                        $category['movs'] = array_values(array_filter(
                            $category['movs'],
                            fn (array $mov): bool => $mov['id'] === $this->focused,
                        ));

                        return $category;
                    })
                    ->filter(fn (array $category): bool => $category['movs'] !== [])
                    ->values()
                    ->all();

                return $part;
            })
            ->filter(fn (array $part): bool => $part['categories'] !== [])
            ->values()
            ->all();
    }

    /**
     * The one MOV the page is showing, as a single line — its label, title,
     * description, trail, pictures and summary. This is what the page renders
     * when a MOV is chosen: one MOV, separated from the rest of the checklist,
     * with its own table and its own upload row.
     *
     * Null when no MOV is chosen, which is how the page is empty until a MOV
     * is picked from the sidebar.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function focusedMov(): ?array
    {
        if ($this->focused === null) {
            return null;
        }

        return collect($this->tree())
            ->flatMap(fn (array $part): array => $part['categories'])
            ->flatMap(fn (array $category): array => $category['movs'])
            ->firstWhere('id', $this->focused);
    }

    /**
     * Completion for the progress card.
     *
     * Counted in MOVs, not in pictures: only requirements flagged
     * `is_required` count towards the bar, and a requirement counts as done
     * once at least one picture is attached to it whatever the review state —
     * the staff member's side of the work is "it is there", the reviewer's
     * verdict is a separate question (and returned pictures are counted
     * separately so they do not read as finished work).
     *
     * @return array{required: int, done: int, percent: int, optional: int, pictures: int, returned: int}
     */
    #[Computed]
    public function progress(): array
    {
        $required = MovRequirement::query()->active()->required()->count();
        $optional = MovRequirement::query()->active()->where('is_required', false)->count();

        // DISTINCT requirements: several files on one MOV still complete
        // one requirement, not several.
        $done = UserMov::forUser(Auth::user())
            ->whereIn('mov_requirement_id', MovRequirement::query()->active()->required()->select('id'))
            ->distinct()
            ->count('mov_requirement_id');

        $pictures = UserMov::forUser(Auth::user())->count();

        $returned = UserMov::forUser(Auth::user())
            ->where('status', UserMov::STATUS_RETURNED)
            ->count();

        return [
            'required' => $required,
            'done' => $done,
            'percent' => $required > 0 ? (int) round(($done / $required) * 100) : 0,
            'optional' => $optional,
            'pictures' => $pictures,
            'returned' => $returned,
        ];
    }

    /**
     * The rail's entries: "All", then every Part as a group with its
     * categories beneath it — Part A, Part B, Part C and the rest, each one
     * selectable.
     *
     * Every entry carries the link that shows it, so a section is reached the
     * same way from the page's rail and from the sidebar's dropdown: as a
     * URL. That is what lets the back button, a refresh and a shared link
     * all mean the same thing.
     *
     * Every entry carries its own done/total over exactly the MOVs the
     * checklist below shows, so the rail answers "how far along is this
     * slice?" without anyone having to open it. Those counts cover every
     * listed MOV; the progress bar above is the stricter view, counting
     * required ones only.
     *
     * @return array{all: array{label: string, trail: string, done: int, total: int}, first: ?string, parts: array<int, array{id: int, name: string, done: int, total: int, categories: array<int, array{id: int, name: string, trail: string, done: int, total: int}>}>}
     */
    #[Computed]
    public function sections(): array
    {
        /** @var array<int, true> $evidenced */
        $evidenced = [];

        foreach (UserMov::forUser(Auth::user())
            ->whereIn('mov_requirement_id', MovRequirement::query()->active()->select('id'))
            ->distinct()
            ->pluck('mov_requirement_id') as $requirementId) {
            // DISTINCT requirements: five pictures on one MOV is one completed
            // MOV, not five.
            $evidenced[(int) $requirementId] = true;
        }

        $first = null;
        $total = 0;
        $done = 0;
        $railParts = [];

        foreach ($this->checklist() as $part) {
            $railCategories = [];
            $partTotal = 0;
            $partDone = 0;

            foreach ($part->categories as $category) {
                $categoryTotal = $category->requirements->count();
                $categoryDone = $category->requirements
                    ->filter(fn (MovRequirement $requirement): bool => isset($evidenced[$requirement->id]))
                    ->count();

                // The pointer the empty checklist is given has to be somewhere
                // with something in it.
                if ($first === null && $categoryTotal > 0) {
                    $first = $part->name.' → '.$category->name;
                }

                $partTotal += $categoryTotal;
                $partDone += $categoryDone;

                $railCategories[] = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'trail' => $category->trail(),
                    'url' => route('mov.index', ['category' => $category->id]),
                    'done' => $categoryDone,
                    'total' => $categoryTotal,
                ];
            }

            // A Part with nothing configured under it is not a place to go.
            if ($railCategories === []) {
                continue;
            }

            $total += $partTotal;
            $done += $partDone;

            $railParts[] = [
                'id' => $part->id,
                'name' => $part->name,
                'done' => $partDone,
                'total' => $partTotal,
                'categories' => $railCategories,
            ];
        }

        return [
            'all' => [
                'label' => 'All MOVs',
                'trail' => 'Every part and category',
                'url' => route('mov.index'),
                'done' => $done,
                'total' => $total,
            ],
            'first' => $first,
            'parts' => $railParts,
        ];
    }

    /**
     * What the rail has narrowed to, for the line above the checklist — and
     * the one place the raw `$category` is resolved.
     *
     * The id arrives from a click and from the query string, so it is
     * resolved here rather than trusted: an id that is not an active
     * category of the checklist is not a section of this page and behaves
     * exactly like "All", so the line can never promise a section the page
     * cannot show.
     *
     * @return array{id: ?int, label: string, trail: string, done: int, total: int}
     */
    #[Computed]
    public function scope(): array
    {
        $sections = $this->sections();

        if ($this->category !== null) {
            foreach ($sections['parts'] as $railPart) {
                foreach ($railPart['categories'] as $railCategory) {
                    if ($railCategory['id'] === $this->category) {
                        return [
                            'id' => $railCategory['id'],
                            'label' => $railCategory['name'],
                            'trail' => $railCategory['trail'],
                            'done' => $railCategory['done'],
                            'total' => $railCategory['total'],
                        ];
                    }
                }
            }
        }

        return [
            'id' => null,
            'label' => $sections['all']['label'],
            'trail' => $sections['all']['trail'],
            'done' => $sections['all']['done'],
            'total' => $sections['all']['total'],
        ];
    }

    /**
     * "MOV" or "MOVs", agreeing with the count. Str::plural would say
     * "MOVS", which reads like a different acronym.
     */
    public function movs(int $count): string
    {
        return $count === 1 ? 'MOV' : 'MOVs';
    }

    /**
     * True when a search is running, so the view can explain an empty result
     * differently from an empty checklist.
     */
    public function isSearching(): bool
    {
        return trim($this->search) !== '';
    }

    /**
     * The catalogue as one ordered walk: active Parts, each with its active
     * categories, each with its active MOVs. The rail and the checklist are
     * both built from this, so a section in one always exists in the other.
     *
     * @return Collection<int, MovPart>
     */
    private function checklist(): Collection
    {
        return MovPart::query()
            ->active()
            ->with(['categories' => fn ($query) => $query->active()->with(['requirements' => fn ($q) => $q->active()])])
            ->orderBy('part_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * One MOV line of the page: the requirement, its pictures and a one-line
     * summary of how the reviewer has treated them.
     *
     * @param  Collection<int, UserMov>  $pictures
     * @return array<string, mixed>
     */
    private function movLine(MovRequirement $requirement, Collection $pictures): array
    {
        $lines = $pictures
            ->map(fn (UserMov $picture): array => [
                'id' => $picture->id,
                'name' => $picture->original_name,
                'size' => $picture->human_size,
                'status' => $picture->status,
                'status_label' => match ($picture->status) {
                    UserMov::STATUS_UPLOADED => 'Pending',
                    UserMov::STATUS_UNDER_REVIEW => 'Under Review',
                    UserMov::STATUS_ACCEPTED => 'Approved',
                    UserMov::STATUS_RETURNED => 'Returned',
                    default => $picture->statusLabel(),
                },
                'status_class' => $picture->statusBadgeClass(),
                'remarks' => $picture->remarks,
                'reviewer' => $picture->reviewer?->username,
                'uploaded_at' => $picture->uploaded_at?->format('M j, Y g:i A'),
                'has_file' => $picture->fileExists(),
                'is_image' => in_array(
                    strtolower(pathinfo($picture->original_name, PATHINFO_EXTENSION)),
                    ['jpg', 'jpeg', 'png', 'webp'],
                    true,
                ),
                'extension' => strtoupper(pathinfo($picture->original_name, PATHINFO_EXTENSION)),
            ])
            ->values()
            ->all();

        $statuses = array_column($lines, 'status');
        $state = match (true) {
            $statuses === [] => ['Not Submitted', 'badge-muted'],
            in_array(UserMov::STATUS_RETURNED, $statuses, true) => ['Returned', 'badge--warn'],
            count(array_filter($statuses, fn (string $status): bool => $status === UserMov::STATUS_ACCEPTED)) === count($statuses) => ['Approved', 'badge--ok'],
            in_array(UserMov::STATUS_UNDER_REVIEW, $statuses, true) => ['Under Review', 'badge--info'],
            default => ['Submitted', 'badge-muted'],
        };

        return [
            'id' => $requirement->id,
            'label' => $requirement->label(),
            'title' => $requirement->title,
            'description' => $requirement->summary(),
            'required' => $requirement->is_required,
            'trail' => $requirement->trail(),
            'pictures' => $lines,
            'count' => count($lines),
            'summary' => $this->picturesSummary($lines),
            'submission_status' => $state[0],
            'submission_status_class' => $state[1],
        ];
    }

    /**
     * How the set reads at a glance: how many files, and what became of
     * them. A MOV with ten pictures and two returned should say so without
     * anyone counting rows.
     *
     * @param  array<int, array<string, mixed>>  $pictures
     */
    private function picturesSummary(array $pictures): string
    {
        if ($pictures === []) {
            return 'No files yet';
        }

        $count = count($pictures);
        $head = $count.' '.Str::plural('file', $count);

        $returned = count(array_filter($pictures, fn (array $p): bool => $p['status'] === UserMov::STATUS_RETURNED));
        $accepted = count(array_filter($pictures, fn (array $p): bool => $p['status'] === UserMov::STATUS_ACCEPTED));
        $reviewing = count(array_filter($pictures, fn (array $p): bool => $p['status'] === UserMov::STATUS_UNDER_REVIEW));

        return match (true) {
            $returned > 0 && $accepted === $count => $head.' · all accepted but '.$returned.' '.Str::plural('was', $returned).' returned',
            $returned > 0 => $head.' · '.$accepted.' accepted, '.$returned.' '.Str::plural('needs', $returned).' revision',
            $accepted === $count => $head.' · all accepted',
            $reviewing > 0 => $head.' · '.$reviewing.' under review',
            $accepted > 0 => $head.' · '.$accepted.' of '.$count.' accepted',
            default => $head.' · not reviewed yet',
        };
    }

    /**
     * How many of a set of MOV lines have at least one picture.
     *
     * @param  array<int, array<string, mixed>>  $movs
     */
    private function countDone(array $movs): int
    {
        return count(array_filter($movs, fn (array $mov): bool => $mov['count'] > 0));
    }

    /**
     * Does this requirement match the search box? Matches on the part, the
     * category, KRA, the MOV number, the title and the description — "Work
     * and Financial Plan", "Learner Formation", "1", "A" and "Part 1" all
     * find the right line.
     */
    private function requirementMatches(
        MovRequirement $requirement,
        string $needle,
        MovCategory $category,
        MovPart $part,
    ): bool {
        $haystack = Str::lower(implode(' ', [
            $part->name,
            $category->name,
            (string) $requirement->kra_label,
            $requirement->label(),
            'mov '.$requirement->mov_number,
            $requirement->title,
            (string) $requirement->description,
        ]));

        return str_contains($haystack, $needle);
    }

    /**
     * @param  array<int|string, mixed>  $set
     */
    private function toggle(array &$set, int|string $key): void
    {
        if (isset($set[$key])) {
            unset($set[$key]);
        } else {
            $set[$key] = true;
        }
    }

    /**
     * The upload is the staff member's own page: the OPCRF route is
     * staff-only too, and the same guard covers a crafted Livewire call.
     */
    private function assertStaffMember(): void
    {
        abort_if(Auth::user()?->hasAdminAccess(), 404);
    }

    /**
     * The requirement must exist and be active — a file can never be
     * attached to something that is not on the checklist.
     */
    private function assertRequirement(?int $requirementId): MovRequirement
    {
        abort_if($requirementId === null, 404);

        $requirement = MovRequirement::query()->active()->find($requirementId);

        abort_if($requirement === null, 404);

        return $requirement;
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        return (array) config('mov.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp']);
    }

    private function maxKilobytes(): int
    {
        return (int) config('mov.max_kb', 25600);
    }

    /**
     * The size limit for the upload form's own wording ("25 MB").
     */
    public function uploadLimitMegabytes(): float
    {
        return round($this->maxKilobytes() / 1024, 1);
    }

    /**
     * The accepted extensions, for the upload form's hint line.
     */
    public function acceptedExtensions(): string
    {
        return strtoupper(implode(', ', $this->allowedExtensions()));
    }

    /**
     * Use the multi-file input, while keeping the existing single-file
     * Livewire property working for older callers and tests.
     *
     * @return array<int, TemporaryUploadedFile>
     */
    private function selectedDocuments(): array
    {
        if ($this->documents !== []) {
            return array_values(array_filter(
                $this->documents,
                fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
            ));
        }

        return $this->document instanceof TemporaryUploadedFile ? [$this->document] : [];
    }

    /**
     * @return array<int, string>
     */
    private function documentRules(): array
    {
        $extensions = $this->allowedExtensions();

        return [
            'file',
            'mimes:'.implode(',', $extensions),
            'extensions:'.implode(',', $extensions),
            'max:'.$this->maxKilobytes(),
        ];
    }

    /**
     * The name the file is stored under: generated on the server, so
     * whatever the browser called the file cannot reach the disk (path
     * separators, a second extension, a 300-character name).
     */
    private function storedFileName(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $stem = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $stem = $stem !== '' ? Str::limit($stem, 40, '') : 'file';

        return uniqid().'-'.$stem.'.'.$extension;
    }

    /**
     * The name shown to the user: their own file name, trimmed to something
     * a row can hold and stripped of the characters Windows rejects.
     */
    private function displayName(string $originalName): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $originalName) ?? $originalName;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = mb_substr(trim($name), 0, 120);

        return $name !== '' ? $name : 'file';
    }
}
