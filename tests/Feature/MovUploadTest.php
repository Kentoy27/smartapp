<?php

namespace Tests\Feature;

use App\Livewire\MovUploader;
use App\Livewire\Sidebar;
use App\Models\MovCategory;
use App\Models\MovPart;
use App\Models\MovRequirement;
use App\Models\User;
use App\Models\UserMov;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Upload MOV checklist.
 *
 * These tests pin the three things that make the module trustworthy: the
 * checklist is DATA (a config change plus one sync adds a MOV, with no view
 * or component change), a document is always attached to an exact requirement
 * and to its own owner, and the page answers the only two questions the staff
 * member has — what is missing, and what is already in.
 */
class MovUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Load the shipped checklist the same way a deployment does.
     */
    private function seedChecklist(): void
    {
        Artisan::call('mov:sync');
    }

    private function staff(string $username = 'staff'): User
    {
        return User::create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    /**
     * The dropdown entries marked as on screen, by href.
     *
     * The class sits after the href on the same tag, so the two are read
     * together: an assertion about "B is active" that only looked at the
     * markup around the class would pass whichever letter happened to be
     * marked.
     *
     * @return array<int, string>
     */
    private function activeSubitemUrls(string $html): array
    {
        return $this->activeLinkUrls($html, 'nav-subitem');
    }

    /**
     * The section links in the tree marked as on screen, by href.
     *
     * @return array<int, string>
     */
    private function activeSectionUrls(string $html): array
    {
        return $this->activeLinkUrls($html, 'nav-tree-open');
    }

    /**
     * The MOV leaves marked as on screen, by href.
     *
     * @return array<int, string>
     */
    private function activeMovUrls(string $html): array
    {
        return $this->activeLinkUrls($html, 'nav-mov');
    }

    /**
     * Every link of one class that is marked active, by href.
     *
     * The href and the class are read off the SAME anchor tag, so an
     * assertion about "B is active" cannot be satisfied by A being active,
     * and one about "this MOV" cannot be satisfied by the page's own link to
     * another MOV.
     *
     * @return array<int, string>
     */
    private function activeLinkUrls(string $html, string $class): array
    {
        preg_match_all('/<a\b[^>]*>/s', $html, $tags);

        // The ACTIVE form of the class, not the class itself: a link that is
        // merely of this kind is not on screen.
        $pattern = '/class="'.preg_quote($class, '/').' is-active"/';

        return collect($tags[0])
            ->filter(fn (string $tag): bool => preg_match($pattern, $tag) === 1)
            ->map(fn (string $tag): string => $this->attributeOf($tag, 'href'))
            ->values()
            ->all();
    }

    /**
     * One attribute of a tag, as written.
     */
    private function attributeOf(string $tag, string $attribute): string
    {
        preg_match('/'.$attribute.'="([^"]*)"/', $tag, $match);

        return $match[1] ?? '';
    }

    /**
     * The sidebar's tree as the given person sees it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function movTreeFor(User $user): array
    {
        $item = collect(Livewire::actingAs($user)->test(Sidebar::class)->instance()->items())
            ->firstWhere('label', 'Upload MOV');

        $this->assertNotNull($item, 'Upload MOV lost its place in the navigation.');

        return $item['children'];
    }

    /**
     * One MOV's leaf in the tree, wherever in the three levels it sits.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, mixed>|null
     */
    private function movLeaf(array $nodes, int $requirementId): ?array
    {
        foreach ($nodes as $node) {
            // A MOV leaf and a category both carry a `section`, so the status
            // is what tells them apart: only a MOV has one.
            if (isset($node['status']) && $node['section'] === (string) $requirementId) {
                return $node;
            }

            $found = $this->movLeaf($node['children'] ?? [], $requirementId);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The requirement behind Part 1 → A → MOV 1 in config/mov.php.
     */
    private function wfpRequirement(): MovRequirement
    {
        return MovRequirement::whereHas('category', fn ($query) => $query->where('name', 'A'))
            ->whereHas('category.part', fn ($query) => $query->where('name', 'PART 1'))
            ->where('mov_number', 1)
            ->firstOrFail();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('mov.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_renders_one_chosen_mov_and_keeps_superadmins_out(): void
    {
        $this->seedChecklist();

        $staff = $this->staff();

        // The page is empty until a MOV is chosen: no header, no checklist,
        // no card. A MOV is chosen from the sidebar.
        $this->actingAs($staff)
            ->get(route('mov.index'))
            ->assertOk()
            ->assertSeeLivewire('mov-uploader')
            ->assertDontSee('class="mov-card"', false)
            ->assertDontSee('Pictures attached to', false)
            ->assertDontSee('Upload your photos here', false);

        // Chosen, it renders alone: one MOV, its own table, its own upload row.
        $this->actingAs($staff)
            ->get(route('mov.index', ['mov' => $this->wfpRequirement()->id]))
            ->assertOk()
            ->assertSee('Pictures attached to PART 1 → A → MOV 1', false)
            ->assertSee('Upload your photos here', false)
            ->assertDontSee('Pictures attached to PART 1 → A → MOV 2', false);

        // Superadmins review MOVs, they do not fill in this checklist — the
        // same boundary the OPCR page keeps.
        $this->actingAs(User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']))
            ->get(route('mov.index'))
            ->assertStatus(404);
    }

    public function test_the_sidebar_offers_upload_mov_to_staff_only(): void
    {
        $staff = $this->staff();

        $staffItems = collect(Livewire::actingAs($staff)->test(Sidebar::class)->instance()->items())
            ->pluck('label');

        $this->assertTrue($staffItems->contains('Upload MOV'));
        // Its own page, not folded into the OPCR upload.
        $this->assertTrue($staffItems->contains('Opcrf'));

        $adminItems = collect(Livewire::actingAs(User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']))
            ->test(Sidebar::class)->instance()->items())
            ->pluck('label');

        $this->assertFalse($adminItems->contains('Upload MOV'));
    }

    public function test_the_sidebar_carries_the_whole_checklist_as_part_category_mov(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $tree = collect($this->movTreeFor($staff));

        // The Parts exactly as the tables hold them — not a hardcoded
        // Part 1 … Part 4. There is no "All MOVs" leaf: the whole checklist
        // is where the page opens, and the tree is Parts and what is under
        // them.
        $this->assertSame(
            MovPart::query()->active()->orderBy('part_order')->orderBy('id')->pluck('name')->all(),
            $tree->pluck('label')->all(),
        );

        // Three levels, each with its own MOVs: the categories under Part 1,
        // and every category's own MOV list — the numbers and titles coming
        // from the rows, not from a list written into the view.
        $part = $tree->firstWhere('label', 'PART 1');
        $categories = collect($part['children']);

        $this->assertSame(['A', 'B', 'C'], $categories->pluck('label')->all());

        $categoryA = $categories->firstWhere('label', 'A');
        $movsA = collect($categoryA['children']);

        $this->assertSame(range(1, 16), $movsA->map(fn (array $mov): int => (int) substr($mov['label'], 4))->all());
        $this->assertSame(
            'Approved Work and Financial Plan (WFP) and Annual Implementation Plan (AIP)',
            $movsA->first()['hint'],
        );

        // Each category carries its OWN list: B has four, C has three, and
        // they are not A's sixteen with the tail cut off.
        $this->assertCount(4, $categories->firstWhere('label', 'B')['children']);
        $this->assertCount(3, $categories->firstWhere('label', 'C')['children']);

        // The counts on the two headers are over exactly what is listed
        // beneath them.
        $this->assertSame('0/16', $categoryA['meta']);
        $this->assertSame('0/23', $part['meta']);
    }

    public function test_a_category_the_config_grows_appears_in_the_tree_with_its_own_movs(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // A Part and a category nobody wrote a line of view code for.
        $part = MovPart::create(['name' => 'PART 9', 'part_order' => 9, 'is_active' => true]);
        $category = MovCategory::create(['mov_part_id' => $part->id, 'name' => 'D', 'category_order' => 1, 'is_active' => true]);

        MovRequirement::create([
            'mov_category_id' => $category->id,
            'mov_number' => 1,
            'title' => 'A MOV in a brand new category',
            'is_required' => true,
            'display_order' => 1,
            'is_active' => true,
        ]);

        $tree = collect($this->movTreeFor($staff));

        $this->assertContains('PART 9', $tree->pluck('label')->all());

        $added = $tree->firstWhere('label', 'PART 9');

        $this->assertSame(['D'], collect($added['children'])->pluck('label')->all());
        $this->assertSame(
            ['MOV 1'],
            collect($added['children'][0]['children'])->pluck('label')->all(),
        );
    }

    public function test_each_mov_is_addressed_by_its_own_requirement_id(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $tree = $this->movTreeFor($staff);

        $mov1InA = $this->wfpRequirement();
        $mov1InB = MovRequirement::whereHas('category', fn ($query) => $query->where('name', 'B'))
            ->where('mov_number', 1)
            ->firstOrFail();

        // Both are "MOV 1" and both are listed — but they are two different
        // requirements, so their links differ. Addressing a MOV by its number
        // alone would send one to the other.
        $leafA = $this->movLeaf($tree, $mov1InA->id);
        $leafB = $this->movLeaf($tree, $mov1InB->id);

        $this->assertNotNull($leafA);
        $this->assertNotNull($leafB);
        $this->assertSame('MOV 1', $leafA['label']);
        $this->assertSame('MOV 1', $leafB['label']);
        $this->assertNotSame($leafA['section'], $leafB['section']);

        $this->assertSame(route('mov.index', ['mov' => $mov1InA->id]), $leafA['url']);
        $this->assertSame(route('mov.index', ['mov' => $mov1InB->id]), $leafB['url']);
    }

    public function test_the_tree_shows_only_the_signed_in_persons_own_state(): void
    {
        $this->seedChecklist();
        $one = $this->staff('one');
        $two = $this->staff('two');

        $requirement = $this->wfpRequirement();

        UserMov::create([
            'user_id' => $one->id,
            'mov_requirement_id' => $requirement->id,
            'original_name' => 'wfp.jpg',
            'stored_path' => 'mov-uploads/one/wfp.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'status' => UserMov::STATUS_ACCEPTED,
            'uploaded_at' => now(),
        ]);

        // The same requirement, two people: the checklist is shared, the state
        // is not. One has a file against it, the other must see nothing.
        $this->assertSame('ok', $this->movLeaf($this->movTreeFor($one), $requirement->id)['status']);
        $this->assertSame('none', $this->movLeaf($this->movTreeFor($two), $requirement->id)['status']);

        // The Part and category counts follow the same person's rows — and
        // the Part's own total is the whole of what it holds, 16 + 4 + 3.
        $this->assertSame('1/23', collect($this->movTreeFor($one))->firstWhere('label', 'PART 1')['meta']);
        $this->assertSame('0/23', collect($this->movTreeFor($two))->firstWhere('label', 'PART 1')['meta']);
    }

    public function test_a_mov_whose_picture_was_asked_back_reads_as_asking_for_attention(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $requirement = $this->wfpRequirement();

        // Two accepted and one returned: what needs the staff member's
        // attention wins, or the MOV would read as done.
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $index => $name) {
            UserMov::create([
                'user_id' => $staff->id,
                'mov_requirement_id' => $requirement->id,
                'original_name' => $name,
                'stored_path' => 'mov-uploads/staff/'.$name,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'status' => $index === 2 ? UserMov::STATUS_RETURNED : UserMov::STATUS_ACCEPTED,
                'uploaded_at' => now(),
            ]);
        }

        $leaf = $this->movLeaf($this->movTreeFor($staff), $requirement->id);

        $this->assertSame('returned', $leaf['status']);
        $this->assertSame('Returned for revision', $leaf['status_label']);
        $this->assertSame(3, $leaf['pictures']);
    }

    public function test_the_sidebar_counts_are_counted_from_the_database(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $partMeta = fn () => collect($this->movTreeFor($staff))->firstWhere('label', 'PART 1')['meta'];

        // 16 + 4 + 3, straight off the tables — not a constant in a view.
        $this->assertSame(23, MovRequirement::query()->active()->required()->count());
        $this->assertSame('0/23', $partMeta());

        // Upload against one required MOV and the count follows it.
        UserMov::create([
            'user_id' => $staff->id,
            'mov_requirement_id' => $this->wfpRequirement()->id,
            'original_name' => 'wfp.jpg',
            'stored_path' => 'mov-uploads/staff/wfp.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 4096,
            'status' => UserMov::STATUS_UPLOADED,
            'uploaded_at' => now(),
        ]);

        $this->assertSame('1/23', $partMeta());
    }

    public function test_the_sidebar_carries_no_progress_panel(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // The progress block is gone from the rail: it repeated the
        // checklist page's own progress card, one indentation shallower,
        // and pushed the tree below the fold.
        $html = Livewire::actingAs($staff)->test(Sidebar::class)
            ->call('toggleGroup', 'movs')
            ->html();

        $this->assertStringNotContainsString('MOV Submission Progress', $html);
        $this->assertStringNotContainsString('nav-mov-panel', $html);
        $this->assertStringNotContainsString('pictures uploaded', $html);

        // What is left above the tree is nothing at all: the panel is gone,
        // and so is the search box that used to sit in it.
        $this->assertStringNotContainsString('Search MOV...', $html);
        $this->assertStringContainsString("toggleMovNode('part-", $html);
    }

    public function test_the_sidebar_carries_no_search_box(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // The rail does not filter the checklist. A second box over the same
        // rows left the navigation showing a tree the page beside it was not
        // on, and the page already carries the search that does it.
        $html = Livewire::actingAs($staff)->test(Sidebar::class)
            ->call('toggleGroup', 'movs')
            ->html();

        $this->assertStringNotContainsString('Search MOV...', $html);
        $this->assertStringNotContainsString('nav-mov-search', $html);
        $this->assertStringNotContainsString('movSearch', $html);

        // The tree it did have is untouched by the removal: still the whole
        // checklist, every MOV under its own Part and category.
        $tree = $this->movTreeFor($staff);

        $movs = collect($tree)
            ->flatMap(fn (array $part) => collect($part['children'] ?? []))
            ->flatMap(fn (array $category) => collect($category['children'] ?? []));

        $this->assertSame(['PART 1'], collect($tree)->pluck('label')->all());
        $this->assertCount(
            MovRequirement::query()->active()->count(),
            $movs,
            'Every active MOV is still listed in the rail.',
        );
    }

    public function test_the_item_with_a_tree_is_itself_the_dropdown(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $part = MovPart::where('name', 'PART 1')->firstOrFail();
        $categoryA = MovCategory::where('mov_part_id', $part->id)->where('name', 'A')->firstOrFail();

        // Open the dropdown, the Part and the category so the MOV leaves are
        // rendered — the tree starts collapsed, and a MOV link only exists
        // once its category is open.
        $html = Livewire::actingAs($staff)
            ->test(Sidebar::class)
            ->call('toggleGroup', 'movs')
            ->call('toggleMovNode', 'part-'.$part->id)
            ->call('toggleMovNode', 'category-'.$part->id.'-'.$categoryA->id)
            ->html();

        // One control, not a link with a dropdown button beside it: the row
        // that names the item is the row that opens the tree.
        $this->assertStringContainsString('nav-item nav-item--toggle', $html);
        $this->assertStringContainsString('wire:click="toggleGroup(\'movs\')"', $html);
        $this->assertStringContainsString('aria-controls="sidebar-group-movs"', $html);

        // The dropdown carries Parts, categories and MOVs — and no "All MOVs"
        // leaf. Every MOV in it is a link to that MOV on its own.
        $this->assertStringNotContainsString('nav-subitem', $html);
        $this->assertStringNotContainsString('No MOVs are configured yet', $html);
        $this->assertStringContainsString(
            'href="'.route('mov.index', ['mov' => $this->wfpRequirement()->id]).'"',
            $html,
        );

        // A plain item is still a link, not a button.
        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('wire:navigate', $html);
    }

    public function test_the_sidebar_does_not_poll(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // A poll re-renders the nav on a timer, and a re-render replaces the
        // row under the pointer: the click lands on a detached node and is
        // dropped, which reads as a Part or category that will not open. The
        // counts on this component are pushed to it by events instead.
        $html = Livewire::actingAs($staff)->test(Sidebar::class)->html();

        $this->assertStringNotContainsString('wire:poll', $html);
    }

    public function test_the_sidebar_tree_lists_every_mov_under_its_own_part_and_category(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // Nothing filters the rail: the tree is the whole checklist, every
        // MOV under its own Part and category, in the catalogue's order.
        $tree = collect($this->movTreeFor($staff));

        $this->assertSame(['PART 1'], $tree->pluck('label')->all());

        $part = $tree->last();

        $categories = collect($part['children']);

        $this->assertSame(['A', 'B', 'C'], $categories->pluck('label')->all());

        $movs = collect($categories->first()['children']);

        $this->assertCount(16, $movs);
        $this->assertSame('MOV 1', $movs->first()['label']);
        $this->assertStringContainsString('Financial', $movs->first()['title']);

        // The counts cover everything the branch holds.
        $this->assertSame('0/16', $categories->first()['meta']);
        $this->assertSame('0/23', $part['meta']);

        // One entry per active Part in the database, and nothing else.
        $this->assertCount(
            MovPart::query()->active()->count(),
            collect($this->movTreeFor($staff)),
        );
        $this->assertStringNotContainsString(
            'All MOVs',
            Livewire::actingAs($staff)->test(Sidebar::class)->call('toggleGroup', 'movs')->html(),
        );
    }

    public function test_the_sidebar_tree_opens_and_closes_one_level_at_a_time(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $part = MovPart::where('name', 'PART 1')->firstOrFail();
        $categoryA = MovCategory::where('mov_part_id', $part->id)->where('name', 'A')->firstOrFail();

        $component = Livewire::actingAs($staff)->test(Sidebar::class);

        $component->call('toggleMovNode', 'part-'.$part->id)
            ->assertSet('movOpenParts.'.$part->id, true);

        $component->call('toggleMovNode', 'category-'.$part->id.'-'.$categoryA->id)
            ->assertSet('movOpenCategories.'.$part->id.':'.$categoryA->id, true);

        $component->call('toggleMovNode', 'part-'.$part->id)
            ->assertSet('movOpenParts.'.$part->id, false);

        // A MOV is a link, not a node to open: nothing happens.
        $component->call('toggleMovNode', 'mov-'.$this->wfpRequirement()->id)
            ->assertSet('movOpenParts.'.$part->id, false);
    }

    public function test_the_dropdown_renders_the_three_levels(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $part = MovPart::where('name', 'PART 1')->firstOrFail();
        $categoryA = MovCategory::where('mov_part_id', $part->id)->where('name', 'A')->firstOrFail();

        $html = $this->actingAs($staff)->get(route('mov.index'))->getContent();

        // The tree starts straight at the first Part: nothing is stacked
        // above it, neither a progress block nor a search box.
        $this->assertStringNotContainsString('Search MOV...', $html);

        // A Part is a disclosure in its own right; its categories are one
        // click further in, not listed on arrival.
        $this->assertStringContainsString("toggleMovNode('part-".$part->id."')", $html);
        $this->assertStringContainsString('nav-tree--1', $html);
        $this->assertStringNotContainsString("toggleMovNode('category-", $html);

        // Opening it reveals the categories — one level deeper, each with
        // its own id, and each a disclosure of its own.
        $html = Livewire::actingAs($staff)->test(Sidebar::class)
            ->call('toggleGroup', 'movs')
            ->call('toggleMovNode', 'part-'.$part->id)
            ->html();

        $this->assertStringContainsString("toggleMovNode('category-".$part->id.'-'.$categoryA->id."')", $html);
        $this->assertStringContainsString('nav-tree--2', $html);

        // Categories are disclosures too: opening one reveals only its own
        // MOVs, each a link to its own requirement and a dot for a checklist
        // nobody has uploaded against.
        $html = Livewire::actingAs($staff)->test(Sidebar::class)
            ->call('toggleGroup', 'movs')
            ->call('toggleMovNode', 'part-'.$part->id)
            ->call('toggleMovNode', 'category-'.$part->id.'-'.$categoryA->id)
            ->html();

        $this->assertStringContainsString(
            'href="'.route('mov.index', ['mov' => $this->wfpRequirement()->id]).'"',
            $html,
        );
        $this->assertStringContainsString('nav-mov-dot--none', $html);
        // B's MOV 1 is a different requirement, so it is not in here.
        $movB = MovRequirement::whereHas('category', fn ($query) => $query->where('name', 'B'))
            ->where('mov_number', 1)
            ->firstOrFail();

        $this->assertStringNotContainsString(route('mov.index', ['mov' => $movB->id]), $html);
    }

    public function test_a_mov_linked_from_the_tree_opens_that_mov_on_the_checklist_page(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // MOV 1 under Part 1 → B, the one that is NOT the MOV 1 under A.
        $movB = MovRequirement::whereHas('category', fn ($query) => $query->where('name', 'B'))
            ->where('mov_number', 1)
            ->firstOrFail();
        $movA = $this->wfpRequirement();

        $html = $this->actingAs($staff)
            ->get(route('mov.index', ['mov' => $movB->id]))
            ->assertOk()
            ->getContent();

        // Its Part and category open on arrival, so the card is on the page
        // at all — and the card is addressable, so the page can scroll to it.
        $this->assertStringContainsString('id="mov-'.$movB->id.'"', $html);
        $this->assertStringContainsString($movB->title, $html);
        $this->assertStringContainsString('data-mov-focus', $html);
        $this->assertStringContainsString("document.getElementById('mov-".$movB->id."')", $html);

        // And it is that MOV, not the other MOV 1.
        $this->assertStringNotContainsString("document.getElementById('mov-".$movA->id."')", $html);

        // The sidebar's tree marks that same MOV — one link, and it is the
        // one just opened, not a MOV 1 from somewhere else.
        $this->assertSame(
            [route('mov.index', ['mov' => $movB->id])],
            $this->activeMovUrls($html),
        );
    }

    public function test_a_mov_that_is_not_on_the_checklist_focuses_nothing(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // A link shared before a MOV was retired is still a working link to
        // the checklist — not an error page, and not a wrong MOV.
        $html = $this->actingAs($staff)
            ->get(route('mov.index', ['mov' => 9999]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-mov-focus', $html);
        $this->assertSame([], $this->activeMovUrls($html));
    }

    public function test_the_dropdown_opens_itself_on_the_checklist_page_and_collapses_again(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // Arriving at /movs — from any link, typed or pasted — shows where
        // you are.
        $this->actingAs($staff)
            ->get(route('mov.index'))
            ->assertOk()
            ->assertSee('class="nav-subnav"', false);

        // On a different page it stays shut; opening it is a deliberate act.
        $this->actingAs($staff)
            ->get(route('opcrf.index'))
            ->assertOk()
            ->assertDontSee('class="nav-subnav"', false);

        Livewire::actingAs($staff)->test(Sidebar::class)
            ->call('toggleGroup', 'movs')
            ->assertSet('openGroups.movs', true)
            ->call('toggleGroup', 'movs')
            ->assertSet('openGroups', []);
    }

    public function test_a_section_link_from_the_sidebar_lands_on_that_section(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $categoryB = MovCategory::whereHas('part', fn ($query) => $query->where('name', 'PART 1'))
            ->where('name', 'B')
            ->firstOrFail();

        // Arriving on a section resolves to that section — the page knows
        // which slice of the checklist the URL is on.
        $onSection = Livewire::actingAs($staff)
            ->withQueryParams(['category' => $categoryB->id])
            ->test(MovUploader::class);

        $this->assertSame($categoryB->id, $onSection->instance()->scope()['id']);
        $this->assertSame('PART 1 → B', $onSection->instance()->scope()['trail']);

        // Arriving on nothing is not a section: the whole checklist, which is
        // the state the page opens in.
        $onNothing = Livewire::actingAs($staff)
            ->withQueryParams([])
            ->test(MovUploader::class);

        // A fresh component has no category on it — the URL asked for none,
        // so the page shows the whole checklist, not any one section.
        $this->assertNull($onNothing->instance()->scope()['id']);
    }

    public function test_the_dropdown_is_a_disclosure_tree_with_no_second_link_per_row(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $part = MovPart::where('name', 'PART 1')->firstOrFail();
        $categoryB = MovCategory::where('mov_part_id', $part->id)
            ->where('name', 'B')
            ->firstOrFail();

        // Open the dropdown and the Part, so the category's MOV leaves are
        // rendered — each one a link to that MOV on its own page.
        $html = Livewire::actingAs($staff)
            ->test(Sidebar::class)
            ->call('toggleGroup', 'movs')
            ->call('toggleMovNode', 'part-'.$part->id)
            ->call('toggleMovNode', 'category-'.$part->id.'-'.$categoryB->id)
            ->html();

        // Every row of the dropdown is one target: the row under the pointer is
        // the row that opens. No arrow carries a second link to the page.
        $this->assertStringNotContainsString('nav-tree-open', $html);
        $this->assertSame([], $this->activeSectionUrls($html));

        // The Part holding the section is expanded once opened, so the reader
        // lands on it.
        $this->assertStringContainsString('nav-tree-chevron is-open', $html);

        // The MOVs under it are links to those MOVs on their own page.
        $this->assertStringContainsString('?mov=', $html);
    }

    public function test_a_section_id_that_is_not_on_the_checklist_shows_everything(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // A stale link (a category retired from the config after it was
        // shared) is not an error page — it is the whole checklist.
        $this->actingAs($staff)
            ->get(route('mov.index', ['category' => 9999]))
            ->assertOk()
            ->assertDontSee('mov-rail-item--nested is-active', false)
            ->assertDontSee('mov-rail-item is-active', false)
            ->assertDontSee('class="mov-scope-line"', false);

        // The dropdown points at no section either, rather than at a stale one.
        $this->assertSame(
            [],
            $this->activeSubitemUrls($this->actingAs($staff)->get(route('mov.index', ['category' => 9999]))->getContent()),
        );

        $this->assertSame(
            [],
            $this->activeSectionUrls($this->actingAs($staff)->get(route('mov.index', ['category' => 9999]))->getContent()),
        );
    }

    public function test_uploading_attaches_the_picture_to_that_exact_requirement(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('WFP_AIP_2026.jpg', 120, 'image/jpeg'))
            ->call('upload')
            ->assertHasNoErrors();

        $upload = UserMov::forUser($staff)->where('mov_requirement_id', $requirement->id)->first();

        $this->assertNotNull($upload, 'The upload never reached the requirement it was made for.');
        $this->assertSame('WFP_AIP_2026.jpg', $upload->original_name);
        $this->assertSame(UserMov::STATUS_UPLOADED, $upload->status);
        Storage::disk('local')->assertExists($upload->stored_path);

        // The stored name is generated server-side: the file the person
        // chose is what is shown and downloaded, not what is on disk.
        $this->assertStringNotContainsString('WFP_AIP_2026.jpg', $upload->stored_path);
        $this->assertStringStartsWith('mov-uploads/'.$staff->id.'/', $upload->stored_path);
    }

    public function test_a_mov_keeps_every_picture_it_is_given(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();
        $reviewer = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);

        // A five-page agreement is five photographs: no cap, and adding one
        // never disturbs the others.
        $component = Livewire::actingAs($staff)->test(MovUploader::class);

        foreach (['page1.jpg', 'page2.jpg', 'page3.jpg', 'page4.jpg', 'page5.jpg'] as $name) {
            $component->call('openUpload', $requirement->id)
                ->set('document', UploadedFile::fake()->create($name, 40, 'image/jpeg'))
                ->call('upload')
                ->assertHasNoErrors();
        }

        $pictures = UserMov::forUser($staff)->where('mov_requirement_id', $requirement->id)->oldest('id')->get();

        $this->assertCount(5, $pictures);
        $this->assertSame(
            ['page1.jpg', 'page2.jpg', 'page3.jpg', 'page4.jpg', 'page5.jpg'],
            $pictures->pluck('original_name')->all(),
        );

        // Each picture carries its own review state: accepting one page does
        // not accept the rest.
        $pictures[0]->accept($reviewer);
        $pictures[1]->returnForRevision($reviewer, 'Page 2 is cut off at the bottom.');

        $component = Livewire::actingAs($staff)->test(MovUploader::class);
        $line = collect($component->instance()->tree())
            ->flatMap(fn (array $part): array => $part['categories'])
            ->flatMap(fn (array $category): array => $category['movs'])
            ->firstWhere('id', $requirement->id);

        $this->assertSame(5, $line['count']);
        $this->assertSame(UserMov::STATUS_ACCEPTED, $line['pictures'][0]['status']);
        $this->assertSame(UserMov::STATUS_RETURNED, $line['pictures'][1]['status']);
        $this->assertSame('5 pictures · 1 accepted, 1 needs revision', $line['summary']);

        // …and five pictures still complete exactly ONE MOV.
        $progress = $component->instance()->progress();
        $this->assertSame(1, $progress['done']);
        $this->assertSame(5, $progress['pictures']);
        $this->assertSame(1, $progress['returned']);
    }

    public function test_a_document_cannot_be_attached_to_something_off_the_checklist(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();

        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', 9999)
            ->assertStatus(404);
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        // A MOV is evidenced with pictures — an executable is out.
        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('payload.exe', 40, 'application/x-msdownload'))
            ->call('upload')
            ->assertHasErrors(['document']);

        // …and so is a PDF, however official it looks: the checklist is read
        // as images, so the rule is the same for every non-picture type.
        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('wfp-and-aip.pdf', 200, 'application/pdf'))
            ->call('upload')
            ->assertHasErrors(['document']);

        $this->assertSame(0, UserMov::forUser($staff)->count(), 'A refused file still created an upload row.');
        $this->assertEmpty(Storage::disk('local')->files('mov-uploads'));
    }

    public function test_the_accepted_types_are_pictures_only(): void
    {
        // The rule lives in config, so it can be widened later without code —
        // but as shipped it is images, nothing else.
        $this->assertSame(['jpg', 'jpeg', 'png', 'webp'], config('mov.allowed_extensions'));
        $this->assertSame(25600, config('mov.max_kb'));
    }

    public function test_a_file_over_the_configured_size_limit_is_refused(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        config(['mov.max_kb' => 50]);
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('big.jpg', 400, 'image/jpeg'))
            ->call('upload')
            ->assertHasErrors(['document']);

        $this->assertSame(0, UserMov::forUser($staff)->count());
    }

    public function test_removing_one_picture_leaves_the_others_alone(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        $component = Livewire::actingAs($staff)->test(MovUploader::class);

        foreach (['one.jpg', 'two.jpg'] as $name) {
            $component->call('openUpload', $requirement->id)
                ->set('document', UploadedFile::fake()->create($name, 20, 'image/jpeg'))
                ->call('upload');
        }

        $pictures = UserMov::forUser($staff)->oldest('id')->get();
        $this->assertCount(2, $pictures);

        $doomed = $pictures->first();
        $kept = $pictures->last();

        $component->call('confirmRemove', $doomed->id);

        $this->assertSame(1, UserMov::forUser($staff)->count());
        $this->assertSame($kept->id, UserMov::forUser($staff)->firstOrFail()->id);
        Storage::disk('local')->assertMissing($doomed->stored_path);
        Storage::disk('local')->assertExists($kept->stored_path);
    }

    public function test_removing_the_last_picture_makes_the_mov_incomplete_again(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        $component = Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('only.jpg', 20, 'image/jpeg'))
            ->call('upload');

        $this->assertSame(1, Livewire::actingAs($staff)->test(MovUploader::class)->instance()->progress()['done']);

        $component->call('confirmRemove', UserMov::forUser($staff)->firstOrFail()->id);

        $this->assertSame(0, Livewire::actingAs($staff)->test(MovUploader::class)->instance()->progress()['done']);
    }

    public function test_one_person_never_sees_or_fetches_another_persons_document(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $owner = $this->staff();
        $other = $this->staff('other');
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($owner)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('mine.jpg', 20, 'image/jpeg'))
            ->call('upload');

        $upload = UserMov::forUser($owner)->firstOrFail();

        // …and the routes refuse it, whoever asks. Signed out first: the
        // upload above left the guard authenticated.
        Auth::logout();
        $this->get(route('mov.download', $upload->id))->assertRedirect(route('login'));
        $this->get(route('mov.view', $upload->id))->assertRedirect(route('login'));

        $this->actingAs($other)->get(route('mov.download', $upload->id))->assertStatus(403);
        $this->actingAs($other)->get(route('mov.view', $upload->id))->assertStatus(403);

        // The other account sees the same empty upload area for that MOV, never the file.
        $otherComponent = Livewire::actingAs($other)
            ->withQueryParams(['mov' => $requirement->id])
            ->test(MovUploader::class);

        $otherComponent->assertDontSee('mine.jpg')
            ->assertSee('Upload your photos here', false);

        // The owner can, and a superadmin (the reviewer) can.
        $this->actingAs($owner)->get(route('mov.download', $upload->id))->assertOk();
        $this->actingAs(User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']))
            ->get(route('mov.view', $upload->id))
            ->assertOk();
    }

    public function test_the_page_shows_what_is_attached_and_where_it_belongs(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($staff)
            ->withQueryParams(['mov' => $requirement->id])
            ->test(MovUploader::class)
            ->assertSee('Approved Work and Financial Plan (WFP)', false)
            ->assertSee('Upload your photos here', false)
            // The upload row says which requirement the picture will answer.
            ->assertSee('PART 1 → A → MOV 1', false)
            ->set('document', UploadedFile::fake()->create('WFP-AIP.jpg', 20, 'image/jpeg'))
            ->call('upload', $requirement->id)
            // The picture is a row in the MOV's own table, with when it landed and
            // where it is in review, and the table still ends in an upload row
            // to add another one rather than a replace.
            ->assertSee('WFP-AIP.jpg')
            ->assertSee('>Uploaded</th>', false)
            ->assertSee('badge-muted', false)
            ->assertSee('1 picture · not reviewed yet')
            ->assertSee('Upload your photos here', false);
    }

    public function test_every_mov_carries_its_own_table_with_an_upload_row(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        $part = MovPart::where('name', 'PART 1')->firstOrFail();

        // A picture attached by naming its MOV — no "open this one first" step.
        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->set('document', UploadedFile::fake()->create('wfp.jpg', 20, 'image/jpeg'))
            ->call('upload', $requirement->id);

        $this->assertSame(1, UserMov::forUser($staff)->count());
        $this->assertSame(
            $requirement->id,
            UserMov::forUser($staff)->firstOrFail()->mov_requirement_id,
            'The picture went to the MOV whose upload area was used, and to no other.',
        );

        // The MOV with a picture, chosen, renders alone: its own table with a row
        // for the picture and the upload row that adds to it.
        $component = Livewire::actingAs($staff)
            ->withQueryParams(['mov' => $requirement->id])
            ->test(MovUploader::class);

        $html = $component->html();

        $this->assertSame(1, substr_count($html, 'class="mov-card"'), 'One MOV is on screen.');
        $this->assertSame(
            1,
            substr_count($html, '<table class="mov-picture-table"'),
            'It carries its own table.',
        );
        $this->assertSame(
            1,
            substr_count($html, '<tr class="mov-upload-row">'),
            'The table ends in an upload row.',
        );
        $this->assertSame(
            1,
            substr_count($html, 'wire:click="upload('),
            'The upload row names the MOV it uploads to.',
        );

        // The MOV with a picture has a row for it and its own trail as the
        // caption; a MOV with nothing says so rather than rendering empty.
        $this->assertSame(1, substr_count($html, 'class="mov-picture '));
        $this->assertSame(0, substr_count($html, 'class="mov-picture-empty"'));
        $this->assertStringContainsString('Pictures attached to PART 1 → A → MOV 1', $html);
        $this->assertStringContainsString('wfp.jpg', $html);

        // The same holds for every other MOV: chosen, it gets its own table,
        // captioned with its own trail and nothing else on the page.
        foreach (MovRequirement::query()->active()->where('id', '!=', $requirement->id)->take(3)->get() as $other) {
            $page = Livewire::actingAs($staff)->withQueryParams(['mov' => $other->id])->test(MovUploader::class)->html();

            $this->assertSame(1, substr_count($page, 'class="mov-card"'), $other->trail());
            $this->assertSame(1, substr_count($page, '<table class="mov-picture-table"'));
            $this->assertSame(1, substr_count($page, '<tr class="mov-upload-row">'));
            $this->assertStringContainsString('Pictures attached to '.$other->trail(), $page);
            $this->assertStringContainsString('>Upload Picture<', $page);
        }
    }
    public function test_search_finds_a_mov_by_its_words_and_hides_the_rest(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $component = Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->set('search', 'Work and Financial Plan');

        $titles = fn ($component): array => collect($component->instance()->tree())
            ->flatMap(fn (array $part): array => $part['categories'])
            ->flatMap(fn (array $category): array => $category['movs'])
            ->pluck('title')
            ->all();

        // Found: MOV 1 of Part 1 → A, and only that MOV.
        $this->assertSame(['Approved Work and Financial Plan (WFP) and Annual Implementation Plan (AIP)'], $titles($component));

        // A MOV number finds every MOV carrying that number — "MOV 4"
        // matches both Part 1 → A → MOV 4 and Part 1 → B → MOV 4.
        $component->set('search', 'MOV 4');

        $this->assertSame(
            ['Report on Promotion Rate (School Form 6)', 'School Innovation Paper'],
            $titles($component),
        );

        // Nothing matching finds nothing.
        $component->set('search', 'nothing matches this');

        $this->assertSame([], $titles($component));
    }

    public function test_progress_counts_required_movs_only(): void
    {
        Storage::fake('local');

        // One MOV of the shipped checklist is configured as optional, which
        // is what the progress bar is supposed to leave out.
        $parts = config('mov.parts');
        $parts[0]['categories'][0]['movs'][3]['is_required'] = false;
        config(['mov.parts' => $parts]);

        $this->seedChecklist();

        $staff = $this->staff();
        $required = MovRequirement::active()->required()->count();
        $optional = MovRequirement::active()->where('is_required', false)->firstOrFail();

        $this->assertGreaterThan(0, $required);
        $this->assertGreaterThan(0, $required + 1, 'The optional MOV should sit outside the required count.');

        $progress = Livewire::actingAs($staff)->test(MovUploader::class)->instance()->progress();

        $this->assertSame(0, $progress['done']);
        $this->assertSame(0, $progress['pictures']);
        $this->assertSame($required, $progress['required']);
        $this->assertSame(1, $progress['optional']);

        // A picture on an optional MOV is still attached and still visible —
        // it just does not move the bar.
        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $optional->id)
            ->set('document', UploadedFile::fake()->create('optional.jpg', 10, 'image/jpeg'))
            ->call('upload');

        $progress = Livewire::actingAs($staff)->test(MovUploader::class)->instance()->progress();
        $this->assertSame(0, $progress['done']);
        $this->assertSame(1, $progress['pictures']);

        $wfp = $this->wfpRequirement();
        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $wfp->id)
            ->set('document', UploadedFile::fake()->create('wfp.jpg', 10, 'image/jpeg'))
            ->call('upload');

        $progress = Livewire::actingAs($staff)->test(MovUploader::class)->instance()->progress();

        $this->assertSame(1, $progress['done']);
        $this->assertSame(2, $progress['pictures']);
        $this->assertSame((int) round((1 / $required) * 100), $progress['percent']);
        $this->assertSame(1, $progress['optional']);
    }

    public function test_a_returned_document_is_reported_as_needing_attention(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $reviewer = User::factory()->create(['is_superadmin' => true, 'role' => 'superadmin']);
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('wfp.jpg', 10, 'image/jpeg'))
            ->call('upload');

        UserMov::forUser($staff)->firstOrFail()->returnForRevision($reviewer, 'Please upload the signed version.');

        // The returned picture is shown on the MOV's own page — chosen, the
        // MOV renders its table with the returned picture flagged and the
        // reviewer's note beside it.
        $component = Livewire::actingAs($staff)
            ->withQueryParams(['mov' => $requirement->id])
            ->test(MovUploader::class);

        $component
            ->assertSee('Returned for Revision')
            ->assertSee('Please upload the signed version.')
            ->assertSee('Reviewed by: '.$reviewer->username);

        // The document still counts as attached — but it is flagged, not
        // quietly counted as finished.
        $progress = Livewire::actingAs($staff)->test(MovUploader::class)->instance()->progress();
        $this->assertSame(1, $progress['done']);
        $this->assertSame(1, $progress['returned']);
    }

    public function test_a_picture_is_served_inline_for_the_thumbnail_and_the_view(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('wfp.jpg', 20, 'image/jpeg'))
            ->call('upload');

        $picture = UserMov::forUser($staff)->firstOrFail();

        // Viewed inline, so the card's thumbnail and the View button both
        // show the actual picture.
        $this->actingAs($staff)
            ->get(route('mov.view', $picture->id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=wfp.jpg');

        // Download always attaches, whatever the type.
        $this->actingAs($staff)
            ->get(route('mov.download', $picture->id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=wfp.jpg');
    }

    public function test_part_one_carries_the_sixteen_movs_of_the_official_form(): void
    {
        $this->seedChecklist();

        $movs = MovRequirement::whereHas('category', fn ($query) => $query->where('name', 'A'))
            ->whereHas('category.part', fn ($query) => $query->where('name', 'PART 1'))
            ->orderBy('mov_number')
            ->get();

        // The official OPCRF template's Part I-A Means of Verification column
        // carries sixteen entries; the checklist mirrors all of them, in the
        // order the form lists them.
        $this->assertCount(16, $movs, 'Part 1 → A must hold the full set of sixteen MOVs.');
        $this->assertSame(range(1, 16), $movs->pluck('mov_number')->all(), 'MOV numbers must run 1–16 with no gaps.');

        // The entries added after the first four, titled as the form words
        // them — these are the ones a staff member reads to know what to
        // photograph.
        $expected = [
            5 => 'Report on Graduation Rate (School Form 6) stamped received and signed by appropriate signatories',
            6 => 'Reports on MPS with Analysis',
            7 => 'Approved Research Proposal and Final Report',
            8 => 'WINs Three-Star Approach Report',
            9 => 'ACR of the School Programs and Activities',
            10 => 'Contingency Plan and WeeLMat Monitoring Tool',
            11 => 'Child-Friendly School System (CFSS) Report',
            12 => 'Approved LAC Plan / Approved INSET Training Design, Attendance Sheet, and Activity Completion Reports (ACR)',
            13 => 'Summary of Ratings eIPCRF Summary',
            14 => 'Report on Absences',
            15 => 'Class and Teachers Program',
            16 => 'SPIRPA - ACRs',
        ];

        foreach ($expected as $number => $title) {
            $this->assertSame(
                $title,
                $movs->firstWhere('mov_number', $number)?->title,
                "MOV {$number} is titled differently from the official form."
            );
        }

        // MOV 7's sub-items belong to that one study, so they live in its
        // description — not as MOVs of their own.
        $research = $movs->firstWhere('mov_number', 7);

        foreach ([
            'Division/School Research Committee Validation Form',
            'Certificate of Presentation/Publication',
            'SIP/AIP Integration or Utilization Report',
            'Mentoring/Coaching Documentation',
        ] as $subItem) {
            $this->assertStringContainsString($subItem, (string) $research->description);
        }
    }

    public function test_the_checklist_is_configured_not_coded(): void
    {
        // A MOV nobody wrote any code for: the config lists it, the command
        // puts it in the database, the page shows it.
        config(['mov.parts' => [[
            'name' => 'PART 9',
            'categories' => [[
                'name' => 'Z',
                'movs' => [
                    ['mov_number' => 7, 'title' => 'Something added later', 'description' => null],
                ],
            ]],
        ]]]);

        Artisan::call('mov:sync');

        $requirement = MovRequirement::where('title', 'Something added later')->first();

        $this->assertNotNull($requirement, 'A MOV named only in the config never reached the database.');
        $this->assertSame('MOV 7', $requirement->label());
        $this->assertSame('PART 9 → Z → MOV 7', $requirement->trail());

        // A MOV added to the config alone appears in the checklist the page
        // renders — the page is data, not a list written into a view.
        $staff = $this->staff();

        $component = Livewire::actingAs($staff)->test(MovUploader::class);

        $found = collect($component->instance()->tree())
            ->flatMap(fn (array $part): array => $part['categories'])
            ->flatMap(fn (array $category): array => $category['movs'])
            ->firstWhere('id', $requirement->id);

        $this->assertNotNull($found, 'A MOV added to the config never reached the page.');
        $this->assertSame('Something added later', $found['title']);

        // Chosen, it renders on its own — its trail and title on screen,
        // and nothing else.
        $page = Livewire::actingAs($staff)
            ->withQueryParams(['mov' => $requirement->id])
            ->test(MovUploader::class)->html();

        $this->assertStringContainsString('Something added later', $page);
        $this->assertStringContainsString('PART 9 → Z → MOV 7', $page);
    }

    public function test_syncing_again_keeps_requirement_ids_and_never_deletes_uploads(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('wfp.jpg', 10, 'image/jpeg'))
            ->call('upload');

        // The same config, run twice: no duplicates.
        Artisan::call('mov:sync');
        Artisan::call('mov:sync');

        $this->assertSame(1, MovPart::where('name', 'PART 1')->count());
        $this->assertSame(1, MovCategory::where('name', 'A')->whereIn('mov_part_id', MovPart::where('name', 'PART 1')->pluck('id'))->count());

        // Retitling a MOV keeps its id — and therefore its uploads.
        config(['mov.parts' => [[
            'name' => 'PART 1',
            'categories' => [[
                'name' => 'A',
                'movs' => [
                    ['mov_number' => 1, 'title' => 'Approved WFP and AIP (reworded)'],
                    ['mov_number' => 2, 'title' => 'Approved ISP / Approved MISAR'],
                    ['mov_number' => 3, 'title' => 'Executed MOA, Deeds of Donation and Partnership Agreements'],
                    ['mov_number' => 4, 'title' => 'Report on Promotion Rate (School Form 6)'],
                ],
            ]],
        ]]]);

        Artisan::call('mov:sync');

        $synced = MovRequirement::find($requirement->id);

        $this->assertSame('Approved WFP and AIP (reworded)', $synced->title);
        $this->assertSame(1, UserMov::forUser($staff)->where('mov_requirement_id', $requirement->id)->count());
    }

    public function test_a_mov_dropped_from_the_config_keeps_its_uploads_and_stays_editable(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();
        $requirement = $this->wfpRequirement();

        Livewire::actingAs($staff)
            ->test(MovUploader::class)
            ->call('openUpload', $requirement->id)
            ->set('document', UploadedFile::fake()->create('wfp.jpg', 10, 'image/jpeg'))
            ->call('upload');

        // A config that no longer mentions it: without --retire-missing the
        // requirement stays exactly where it is.
        config(['mov.parts' => [['name' => 'PART 4', 'categories' => []]]]);
        Artisan::call('mov:sync');

        $this->assertNotNull(MovRequirement::find($requirement->id));
        $this->assertSame(1, UserMov::forUser($staff)->count());

        // With the flag it is hidden from the checklist — still there, still
        // holding its documents.
        Artisan::call('mov:sync', ['--retire-missing' => true]);

        $this->assertFalse((bool) MovRequirement::find($requirement->id)->is_active);
        $this->assertSame(1, UserMov::forUser($staff)->count());
    }

    public function test_a_mov_takes_part_category_and_number_only_from_its_own_row(): void
    {
        $this->seedChecklist();

        $requirement = $this->wfpRequirement();

        $this->assertSame('PART 1', $requirement->category->part->name);
        $this->assertSame('A', $requirement->category->name);
        $this->assertSame('PART 1 → A → MOV 1', $requirement->trail());
        $this->assertSame('Approved Work and Financial Plan (WFP) and Annual Implementation Plan (AIP)', $requirement->title);

        // Groups do not all have the same shape: A carries the full set,
        // B and C carry only their own. The point is that a MOV is identified
        // by the row it belongs to — not by a position in a flat list — so two
        // MOV 1s in different groups are different requirements.
        $part = MovPart::where('name', 'PART 1')->firstOrFail();

        $counts = MovCategory::where('mov_part_id', $part->id)
            ->with('requirements')
            ->get()
            ->mapWithKeys(fn (MovCategory $category): array => [
                $category->name => $category->requirements->count(),
            ]);

        $this->assertGreaterThan(1, $counts->unique()->count(), 'The categories must not all be the same size.');

        $otherGroup = $part->categories()
            ->where('name', '!=', 'A')
            ->whereHas('requirements')
            ->firstOrFail();

        $sameNumberElsewhere = MovRequirement::where('mov_category_id', $otherGroup->id)
            ->where('mov_number', 1)
            ->first();

        $this->assertNotNull($sameNumberElsewhere, 'MOV 1 exists in more than one group.');
        $this->assertNotSame(
            $requirement->mov_category_id,
            $sameNumberElsewhere->mov_category_id,
            'MOV 1 in two groups must be two separate rows, not one.'
        );
        $this->assertNotSame($requirement->id, $sameNumberElsewhere->id);
    }

    public function test_the_rail_lists_every_part_with_its_categories_and_no_all_entry(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $component = Livewire::actingAs($staff)->test(MovUploader::class);

        // Each Part with its categories beneath it — A, B and C, not just the
        // first. The page renders none of it: a MOV is chosen and shown alone.
        $component->assertDontSee('class="mov-card"', false);

        $sections = $component->instance()->sections();

        $this->assertSame('All MOVs', $sections['all']['label']);
        $this->assertSame('PART 1 → A', $sections['first'], 'The empty checklist is pointed at a section that has something in it.');
        $this->assertSame(
            ['A', 'B', 'C'],
            collect($sections['parts'][0]['categories'])->pluck('name')->all(),
        );

        // The rail's counts are the catalogue's, not anything typed here.
        $this->assertSame(MovRequirement::query()->active()->count(), $sections['all']['total']);
        $this->assertSame(0, $sections['all']['done']);
        $this->assertSame(
            collect($sections['parts'][0]['categories'])->pluck('total')->sum(),
            $sections['parts'][0]['total'],
        );
        $this->assertSame($sections['parts'][0]['total'], $sections['all']['total']);
    }

    public function test_the_rail_offers_a_link_to_every_section(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $component = Livewire::actingAs($staff)->test(MovUploader::class);
        $sections = $component->instance()->sections();

        // A section is reached as a URL, which is what makes the back button
        // and a shared link mean the same thing as clicking.
        foreach ($sections['parts'][0]['categories'] as $category) {
            $this->assertSame(route('mov.index', ['category' => $category['id']]), $category['url']);
        }

        // Nothing is on screen until a MOV is chosen, and no section marks
        // itself as the one you are on.
        $component->assertDontSee('mov-card', false);
    }

    public function test_picking_a_category_narrows_the_checklist_and_says_which_one(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $categoryB = MovCategory::whereHas('part', fn ($query) => $query->where('name', 'PART 1'))
            ->where('name', 'B')
            ->firstOrFail();

        $component = Livewire::actingAs($staff)->test(MovUploader::class)
            ->set('category', $categoryB->id)
            ->assertSet('category', $categoryB->id);

        // The page names what is on screen through the scope it resolved, so a
        // narrowed tree never reads as the whole of the work.
        $this->assertSame('PART 1 → B', $component->instance()->scope()['trail']);

        $onScreen = collect($component->instance()->tree())
            ->flatMap(fn (array $part): array => collect($part['categories'])->pluck('id')->all());

        $this->assertSame([$categoryB->id], $onScreen->all());

        // Back to everything.
        $component->set('category', null)
            ->assertSet('category', null);

        $this->assertNull($component->instance()->scope()['id']);

        $this->assertCount(
            3,
            collect($component->instance()->tree())
                ->flatMap(fn (array $part): array => $part['categories']),
        );
    }

    public function test_the_rail_counts_only_what_this_person_has_uploaded(): void
    {
        Storage::fake('local');
        $this->seedChecklist();
        $staff = $this->staff();

        // Part 1 → A → MOV 1.
        Livewire::actingAs($staff)->test(MovUploader::class)
            ->call('openUpload', $this->wfpRequirement()->id)
            ->set('document', UploadedFile::fake()->create('wfp.jpg', 30, 'image/jpeg'))
            ->call('upload')
            ->assertHasNoErrors();

        $sections = Livewire::actingAs($staff)->test(MovUploader::class)->instance()->sections();
        $byName = collect($sections['parts'][0]['categories'])->keyBy('name');

        $this->assertSame(1, $byName['A']['done']);
        $this->assertSame(0, $byName['B']['done']);
        $this->assertSame(1, $sections['all']['done']);
    }

    public function test_a_search_reaches_past_the_category_the_rail_picked(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        $categoryA = MovCategory::whereHas('part', fn ($query) => $query->where('name', 'PART 1'))
            ->where('name', 'A')
            ->firstOrFail();

        // Finding one line should never depend on having guessed the right
        // letter first — so the search reads past the section the URL picked.
        $component = Livewire::actingAs($staff)->test(MovUploader::class)
            ->set('category', $categoryA->id)
            ->set('search', 'Report on Promotion Rate');

        $titles = collect($component->instance()->tree())
            ->flatMap(fn (array $part): array => $part['categories'])
            ->flatMap(fn (array $category): array => $category['movs'])
            ->pluck('title');

        $this->assertTrue(
            $titles->contains('Report on Promotion Rate (School Form 6)'),
            'A search reaches past the section to find a MOV elsewhere.',
        );
    }

    public function test_a_category_that_is_not_on_the_checklist_falls_back_to_everything(): void
    {
        $this->seedChecklist();
        $staff = $this->staff();

        // ?category=9999 arrives from a stale link, not from a click: the
        // page resolves it to the whole checklist rather than rendering
        // nothing, so an old link is still a usable one.
        $this->actingAs($staff)
            ->get(route('mov.index', ['category' => 9999]))
            ->assertOk()
            ->assertDontSee('mov-rail-item is-active', false)
            ->assertDontSee('class="mov-scope-line"', false);
    }
}
