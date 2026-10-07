<?php

namespace App\Livewire;

use App\Models\MovCategory;
use App\Models\MovPart;
use App\Models\MovRequirement;
use App\Models\OpcrfSubmission;
use App\Models\User;
use App\Models\UserMov;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Live sidebar navigation.
 *
 * Rendered through Livewire so it reacts to app state without a full page
 * reload: the active item is highlighted with wire:current (kept in sync on
 * every navigate), and the component listens for events emitted by the
 * pages it links to — the Users table announces changes via `users-refreshed`.
 *
 * Items are derived and sorted (see items()), so a new destination is added
 * by one entry here and slots itself into reading order. An item may carry
 * `children`: a dropdown of the sections of that page, which may nest. The
 * MOV checklist is the three-level case — Part → Category → MOV, built from
 * the same catalogue rows the checklist page renders, so the navigation can
 * never offer a MOV the page does not have. An item may also carry a
 * `panel`: the control shown above its dropdown (the MOV checklist's search
 * box).
 *
 * Nothing here polls. A poll re-renders the navigation on a timer, and a
 * re-render replaces the row the pointer is on — the click lands on a node
 * that is no longer in the document and is silently dropped, which reads as
 * a dropdown that does not work. The counts this component shows are pushed
 * to it by events instead (see the #[On] handlers below).
 */
class Sidebar extends Component
{
    /**
     * The navigation, and every badge on it, is the signed-in user's.
     *
     * The login page is a bare view that does not use the dashboard
     * layout, so refusing a guest here costs nothing and stops the
     * navigation rendering around nobody's session.
     */
    public function mount(): void
    {
        abort_unless(Auth::check(), 404);

        if (! request()->routeIs('mov.index')) {
            return;
        }

        // The MOV dropdown opens on the page it navigates, and points at the
        // section the URL is on — so arriving anywhere, from any link, typed
        // or pasted, shows where you are.
        $this->openGroups['movs'] = true;

        $requested = request()->query('category');

        // A section that is not on the checklist (a stale link, a category
        // retired from the config) is not a place to point at: the page shows
        // everything, so the dropdown points at "All" too.
        $this->movSection = $requested === null || $requested === ''
            || MovCategory::query()->active()->whereKey($requested)->exists()
                ? (string) ($requested ?? 'all')
                : 'all';

        // The MOV the URL is on (`/movs?mov=101`), which is how the tree
        // marks the line the page is showing. A requirement that is not on
        // the checklist marks nothing rather than marking a wrong MOV.
        $requestedMov = request()->query('mov');

        $this->movFocus = $requestedMov !== null && $requestedMov !== ''
            && MovRequirement::query()->active()->whereKey($requestedMov)->exists()
                ? (string) $requestedMov
                : null;

        if ($this->movFocus !== null) {
            $requirement = MovRequirement::query()
                ->active()
                ->with('category.part')
                ->find((int) $this->movFocus);

            if ($requirement?->category?->part !== null) {
                $partId = $requirement->category->part->id;
                $this->movOpenParts[$partId] = true;
                $this->movOpenCategories[$partId.':'.$requirement->category->id] = true;
            }
        }

        if ($this->movSection !== 'all') {
            $category = MovCategory::query()
                ->active()
                ->with('part')
                ->find((int) $this->movSection);

            if ($category?->part !== null) {
                $this->movOpenParts[$category->part->id] = true;
            }
        }
    }

    /**
     * Which item's dropdown is open, by its path.
     *
     * @var array<string, true>
     */
    public array $openGroups = [];

    /**
     * Which MOV section the checklist page is showing: 'all', a category id
     * as a string, or null when this page is not the MOV checklist at all.
     *
     * A string, because the sections are matched by identity rather than by
     * truthiness — section 0 must not read as "nothing selected".
     *
     * It is read from the URL on arrival and never changed afterwards: the
     * rail on the checklist page and the dropdown's category arrows are
     * links, so moving to another section is a navigation and this component
     * is built again pointing at the new one. 'all' means the whole checklist
     * is on screen, which the dropdown now marks by marking nothing: it
     * carries no entry for the state the page opens in.
     */
    public ?string $movSection = null;

    /**
     * Which Parts of the MOV tree are open in the sidebar, by id.
     *
     * @var array<int, true>
     */
    public array $movOpenParts = [];

    /**
     * Which categories are open, keyed "part:category".
     *
     * @var array<string, true>
     */
    public array $movOpenCategories = [];

    /**
     * The MOV the page is on (`?mov=`), as a string, or null when it is not
     * on one — which also covers a requirement that has since been retired.
     */
    public ?string $movFocus = null;

    /**
     * Number of accounts, shown as a live badge on the Users item.
     */
    public ?int $usersCount = null;

    /**
     * Number of OPCRF submissions still awaiting the superadmin's approval,
     * shown as a live badge on the superadmin OPCRF item.
     */
    public ?int $opcrfCount = null;

    /**
     * The navigation items, in the order they are listed.
     *
     * Role checks decide which items exist. Each item's section and order
     * keep the navigation grouped consistently across roles.
     */
    #[Computed]
    public function items(): array
    {
        $items = [
            [
                'label' => 'Dashboard',
                'route' => 'home',
                'path' => 'home',
                'icon' => 'layout-dashboard',
                'section' => 'Main',
                'order' => 1,
            ],
        ];

        $items[] = [
            'label' => 'AIP',
            'route' => 'aip.index',
            'path' => 'aip',
            'icon' => 'file-spreadsheet',
            'section' => 'Files',
            'order' => 12,
        ];

        if (Auth::user()?->is_superadmin) {
            $items[] = [
                'label' => 'OPCRF',
                'route' => 'opcrf.review',
                'path' => 'opcrf-review',
                'icon' => 'eye',
                'badge' => $this->opcrfCount,
                'section' => 'Files',
                'order' => 10,
            ];

            // Both superadmin OPCRF tools remain available under Files.
            $items[] = [
                'label' => 'OPCRF Schedule',
                'route' => 'opcrf.schedule',
                'path' => 'opcrf-schedule',
                'icon' => 'calendar',
                'section' => 'Files',
                'order' => 11,
            ];

            $items[] = [
                'label' => 'Users',
                'route' => 'users.index',
                'path' => 'users',
                'icon' => 'users-round',
                'badge' => $this->usersCount,
                'section' => 'Admin',
                'order' => 20,
            ];

            $items[] = [
                'label' => 'Districts & Schools',
                'route' => 'districts.index',
                'path' => 'districts',
                'icon' => 'school',
                'section' => 'Admin',
                'order' => 21,
            ];
        } else {
            // Opcrf is a staff-facing page: regular users only.
            $items[] = [
                'label' => 'OPCRF',
                'route' => 'opcrf.index',
                'path' => 'opcrf',
                'icon' => 'clipboard-list',
                'section' => 'Files',
                'order' => 10,
            ];

            // The MOV checklist is its own page, deliberately not folded
            // into the OPCR upload: the checklist is the standing set of
            // evidence documents, and it is worked on independently of any
            // one submission's cycle. It carries the whole checklist as a
            // navigation tree — Part → Category → MOV — plus the progress
            // of the person reading it.
            $items[] = [
                'label' => 'MOV',
                'route' => 'mov.index',
                'path' => 'movs',
                'icon' => 'upload',
                'section' => 'MOV',
                'order' => 20,
                'panel' => 'mov',
                'children' => $this->movTree(),
            ];

            // WFP is for the School Head (SH) role only — hidden from the
            // SDS Viewer, and unreachable by URL regardless (route guard).
            if (Auth::user()?->canAccessWfp()) {
                $items[] = [
                    'label' => 'WFP',
                    'route' => 'wfp.index',
                    'path' => 'wfp',
                    'icon' => 'chart-column',
                    'section' => 'Files',
                    'order' => 11,
                ];
            }
        }

        usort($items, function (array $a, array $b): int {
            $rankA = $a['order'] ?? PHP_INT_MAX;
            $rankB = $b['order'] ?? PHP_INT_MAX;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            // Natural + case-insensitive so items slot in reading order and
            // numbered labels ("Report 2" before "Report 10") sort as a
            // human reads.
            return strnatcasecmp($a['label'], $b['label']);
        });

        return $items;
    }

    /**
     * Open or close one item's dropdown.
     *
     * Only one at a time: these are page sections, and a navigation that
     * can be half-open twice is harder to read than one that collapses.
     */
    public function toggleGroup(string $path): void
    {
        if (isset($this->openGroups[$path])) {
            unset($this->openGroups[$path]);
        } else {
            $this->openGroups = [$path => true];
        }
    }

    /**
     * Open or close one node of the MOV tree.
     *
     * One method for the whole tree, addressed by the node's own id
     * ("part-1", "category-1-2"), so adding a level is a matter of reading
     * one more shape here rather than of a new public action per level.
     * Anything that is not a Part or a category — a MOV leaf, or an id from
     * a stale page — is simply not a node to open.
     */
    public function toggleMovNode(string $node): void
    {
        if (str_starts_with($node, 'part-')) {
            $this->toggleKey($this->movOpenParts, (int) substr($node, 5));

            return;
        }

        if (str_starts_with($node, 'category-')) {
            $segments = explode('-', substr($node, 9));

            $this->toggleKey($this->movOpenCategories, ($segments[0] ?? '').':'.($segments[1] ?? ''));
        }
    }

    /**
     * The MOV checklist as the sidebar walks it: Part → Category → MOV, with
     * each MOV carrying the signed-in user's own upload state.
     *
     * Built from the same catalogue rows the checklist page renders, with the
     * same active-only scoping, so the navigation can never offer a Part,
     * category or MOV that the page does not have. Nothing about the shape is
     * hardcoded: the Parts, their categories and their MOVs — and every MOV
     * number and title — come from the tables, so if Part 1 → A holds four
     * MOVs and Part 1 → B holds seven, that is exactly what is listed.
     *
     * A MOV is identified by its requirement id, never by its number: MOV 1
     * under Part 1 → A is a different requirement from MOV 1 under Part 1 → B,
     * and the link each leaf carries is that requirement's own deep link.
     *
     * The tree is Parts and the MOVs under them, and nothing else: the whole
     * checklist is where the page opens and how it comes back to it, so no
     * "All MOVs" leaf is offered here to point at where you already are.
     *
     * The tree is the whole of what it holds — nothing filters it. Narrowing
     * the checklist is the page's own search box's job, one click away, and a
     * second box here filtering the same rows was a way to be looking at a
     * navigation that did not match the page beside it.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function movTree(): array
    {
        $user = Auth::user();

        // The checklist is the staff member's page; the superadmin does not
        // have one, so there is nothing to walk for them.
        if ($user === null || $user->is_superadmin) {
            return [];
        }

        $parts = MovPart::query()
            ->active()
            ->with(['categories' => fn ($query) => $query->active()->with(['requirements' => fn ($q) => $q->active()])])
            ->orderBy('part_order')
            ->orderBy('id')
            ->get();

        // Only the signed-in user's own rows: the dot beside a MOV is that
        // person's state, and no colleague's upload is ever read here.
        /** @var Collection<int, Collection<int, UserMov>> $pictures */
        $pictures = UserMov::forUser($user)
            ->whereIn('mov_requirement_id', MovRequirement::query()->active()->select('id'))
            ->get(['mov_requirement_id', 'status'])
            ->groupBy('mov_requirement_id');

        $tree = [];

        foreach ($parts as $part) {
            $categories = [];
            $partDone = 0;
            $partTotal = 0;

            foreach ($part->categories as $category) {
                $movs = [];
                $categoryDone = 0;

                // The counts are over the whole category and the whole Part,
                // straight from the catalogue — a tree that renumbered
                // itself would make "2 / 4" mean something different from
                // one keystroke to the next.
                $categoryTotal = $category->requirements->count();

                foreach ($category->requirements as $requirement) {
                    $own = $pictures->get($requirement->id, collect());
                    $status = $this->movStatus($own);

                    $categoryDone += $own->isEmpty() ? 0 : 1;

                    $movs[] = [
                        'key' => 'mov-'.$requirement->id,
                        'section' => (string) $requirement->id,
                        'label' => $requirement->label(),
                        'hint' => $requirement->title,
                        // A MOV number means nothing on its own in a list
                        // this deep, so the tooltip carries the whole trail.
                        'title' => $requirement->trail().' — '.$requirement->title,
                        'url' => route('mov.index', ['mov' => $requirement->id]),
                        'status' => $status['key'],
                        'status_label' => $status['label'],
                        'required' => $requirement->is_required,
                        'pictures' => $own->count(),
                        'children' => [],
                    ];
                }

                // Rolled in before the category is kept, so a Part's count
                // always covers everything it holds.
                $partTotal += $categoryTotal;
                $partDone += $categoryDone;

                $categories[] = [
                    'key' => 'category-'.$part->id.'-'.$category->id,
                    'section' => (string) $category->id,
                    'label' => $category->name,
                    'title' => $category->trail(),
                    // The letter alone is ambiguous this deep; the trail says
                    // where it sits, and the count says how far it is.
                    'meta' => $categoryDone.'/'.$categoryTotal,
                    'url' => route('mov.index', ['category' => $category->id]),
                    'open' => isset($this->movOpenCategories[$part->id.':'.$category->id]),
                    'children' => $movs,
                ];
            }

            // A Part with nothing configured under it is not a place to go.
            if ($categories === []) {
                continue;
            }

            $tree[] = [
                'key' => 'part-'.$part->id,
                'section' => null,
                'label' => $part->name,
                'title' => $part->name,
                'meta' => $partDone.'/'.$partTotal,
                'open' => isset($this->movOpenParts[$part->id]),
                'children' => $categories,
            ];
        }

        return $tree;
    }

    /**
     * What one MOV reads as in the tree, from that person's pictures for it.
     *
     * The states are the application's own — UserMov's four — reduced to the
     * one a single dot can carry: what needs the staff member's attention
     * first wins, so a MOV with two accepted pictures and one returned reads
     * as returned rather than as done.
     *
     * @param  Collection<int, UserMov>  $pictures
     * @return array{key: string, label: string}
     */
    private function movStatus(Collection $pictures): array
    {
        if ($pictures->isEmpty()) {
            return ['key' => 'none', 'label' => 'Not uploaded'];
        }

        $statuses = $pictures->pluck('status');

        return match (true) {
            $statuses->contains(UserMov::STATUS_RETURNED) => ['key' => 'returned', 'label' => 'Returned for revision'],
            $statuses->contains(UserMov::STATUS_UNDER_REVIEW) => ['key' => 'review', 'label' => 'Under review'],
            $statuses->contains(UserMov::STATUS_ACCEPTED) => ['key' => 'ok', 'label' => 'Accepted'],
            default => ['key' => 'pending', 'label' => 'Uploaded — awaiting review'],
        };
    }

    /**
     * @param  array<int|string, true>  $set
     */
    private function toggleKey(array &$set, int|string $key): void
    {
        if (isset($set[$key])) {
            unset($set[$key]);
        } else {
            $set[$key] = true;
        }
    }

    /**
     * Fired by the Users table component whenever its data changes.
     */
    #[On('users-refreshed')]
    public function refreshUsersBadge(): void
    {
        if (Auth::user()?->is_superadmin) {
            $this->usersCount = User::count();
        }
    }

    /**
     * Fired by the staff upload window the moment a submission is
     * confirmed, and by the superadmin's review modal once a submission is
     * approved — the badge updates without a reload.
     */
    #[On('opcrf-submission-created')]
    #[On('opcrf-submission-approved')]
    #[On('opcrf-submission-deleted')]
    #[On('opcrf-submission-forwarded')]
    public function refreshOpcrfBadge(): void
    {
        if (Auth::user()?->is_superadmin) {
            $this->opcrfCount = $this->pendingSubmissions();
        }
    }

    /**
     * Submissions still awaiting the signed-in superadmin's own action —
     * the current holder of the review step counts a row, not the original
     * reviewer it auto-routed onward from (Eve sees a forwarded submission
     * in her list, but the pending work sits with SY now). The rule itself
     * lives on the model, where the dashboard analytics read it too.
     */
    private function pendingSubmissions(): int
    {
        $user = Auth::user();

        return OpcrfSubmission::awaitingReviewFrom($user)->count();
    }

    public function render()
    {
        if (Auth::user()?->is_superadmin) {
            $this->usersCount = User::count();
            $this->opcrfCount = $this->pendingSubmissions();
        } else {
            $this->usersCount = null;
            $this->opcrfCount = null;
        }

        return view('livewire.sidebar');
    }
}
