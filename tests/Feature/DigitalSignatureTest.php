<?php

namespace Tests\Feature;

use App\Livewire\Profile\DigitalSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The digital signature on the shared profile page.
 *
 * A signature belongs to an ACCOUNT, not to a role: the same page and the same
 * component serve a user and a superadmin, so the access tests below always
 * walk both. The upload is deliberately two-step — picking a file previews it
 * and writes nothing until Save — and the stored signature is only replaced
 * once the new file is safely on disk, which is what the failure tests pin.
 */
class DigitalSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $username = 'sh_user'): User
    {
        return User::factory()->create([
            'name' => 'School Head',
            'username' => $username,
            'role' => 'user',
            'is_superadmin' => false,
        ]);
    }

    private function superadmin(string $username = 'super_admin'): User
    {
        return User::factory()->create([
            'name' => 'Super Admin',
            'username' => $username,
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
    }

    /**
     * A signature image as the browser would send it.
     */
    private function image(string $name = 'my-signature.png', int $kilobytes = 60): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/png');
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('profile.index'))->assertRedirect(route('login'));
        $this->get(route('profile.signature'))->assertRedirect(route('login'));
    }

    public function test_both_a_user_and_a_superadmin_get_the_same_profile_page(): void
    {
        foreach ([$this->staff(), $this->superadmin()] as $account) {
            $response = $this->actingAs($account)->get(route('profile.index'));

            $response->assertOk()
                ->assertSeeLivewire('profile.digital-signature')
                ->assertSee('Digital Signature')
                ->assertSee('Not uploaded')
                ->assertSee('Upload Digital Signature')
                ->assertSee('transparent PNG');

            // The account menu is the way in for every role.
            $response->assertSee(route('profile.index'), false);

            // Nothing to change or remove yet.
            $response->assertDontSee('Change Signature');
            $response->assertDontSee('Remove Signature');
        }
    }

    // ------------------------------------------------- picking a file (step 1)

    public function test_picking_a_file_previews_it_and_stores_nothing(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('head-signature.png'))
            ->assertHasNoErrors()
            ->assertSee('head-signature.png')
            ->assertSee('Not saved yet')
            ->assertSee('Save signature');

        // The choice is only a choice: no file, no columns, no date.
        $account->refresh();

        $this->assertFalse($account->hasSignature());
        $this->assertNull($account->signature_path);
        $this->assertNull($account->signature_uploaded_at);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_a_picked_file_can_be_cancelled_before_it_is_saved(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image())
            ->call('save')
            ->assertHasNoErrors();

        $savedPath = $account->refresh()->signature_path;

        // A second image is picked and then dropped: the saved one survives.
        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('replacement.png'))
            ->call('cancelSelection')
            ->assertSet('signature', null)
            ->assertDontSee('Not saved yet');

        $this->assertSame($savedPath, $account->refresh()->signature_path);
        Storage::disk('local')->assertExists($savedPath);
    }

    // ------------------------------------------------------- saving (step 2)

    public function test_saving_stores_the_signature_for_a_user(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('head-signature.png'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('signature', null)
            ->assertSee('Digital signature saved.')
            ->assertSee('Signature uploaded')
            ->assertSee('Change Signature')
            ->assertSee('Remove Signature');

        $account->refresh();

        $this->assertTrue($account->hasSignature());
        $this->assertSame('head-signature.png', $account->signature_original_name);
        $this->assertSame('image/png', $account->signature_mime_type);
        $this->assertGreaterThan(0, $account->signature_size);
        $this->assertNotNull($account->signature_uploaded_at);
        $this->assertNotNull($account->signature_updated_at);
        $this->assertSame('Signature uploaded', $account->signatureStatusLabel());
        $this->assertSame(route('profile.signature'), $account->signatureUrl());

        // The stored name is generated, and the file lives under the account's
        // own private directory on the local disk.
        $this->assertStringStartsWith('signatures/'.$account->id.'/', $account->signature_path);
        Storage::disk('local')->assertExists($account->signature_path);
    }

    public function test_a_superadmin_manages_their_own_signature(): void
    {
        Storage::fake('local');

        $superadmin = $this->superadmin();

        Livewire::actingAs($superadmin)->test(DigitalSignature::class)
            ->set('signature', $this->image('admin-signature.png'))
            ->call('save')
            ->assertHasNoErrors();

        $superadmin->refresh();

        $this->assertTrue($superadmin->hasSignature());
        Storage::disk('local')->assertExists($superadmin->signature_path);

        // And the profile page previews it through the authorised route.
        $this->actingAs($superadmin)
            ->get(route('profile.index'))
            ->assertOk()
            ->assertSee(route('profile.signature'), false)
            ->assertSee('Signature uploaded');

        // Removing it is the same one-account workflow.
        Livewire::actingAs($superadmin)->test(DigitalSignature::class)
            ->call('openRemoveModal')
            ->assertSet('showRemoveModal', true)
            ->call('removeSignature')
            ->assertSet('showRemoveModal', false)
            ->assertSee('Digital signature removed.');

        $this->assertFalse($superadmin->refresh()->hasSignature());
    }

    public function test_replacing_a_signature_keeps_the_first_upload_date_and_drops_the_old_file(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('first.png'))
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $firstPath = $account->signature_path;
        $firstUploadedAt = $account->signature_uploaded_at;

        // A later replacement: the first upload date must not move.
        $this->travel(2)->minutes();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('second.png'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Digital signature updated.');

        $account->refresh();

        $this->assertNotSame($firstPath, $account->signature_path);
        $this->assertSame('second.png', $account->signature_original_name);
        $this->assertTrue($firstUploadedAt->equalTo($account->signature_uploaded_at));
        $this->assertTrue($account->signature_updated_at->greaterThan($account->signature_uploaded_at));

        // Only the current signature is kept: the replaced file is gone.
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($account->signature_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('signatures/'.$account->id));
    }

    // --------------------------------------------------------- bad uploads

    public function test_a_non_image_is_refused_and_nothing_is_stored(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', UploadedFile::fake()->create('notes.txt', 4, 'text/plain'))
            ->assertHasErrors(['signature' => 'mimes'])
            ->call('save')
            ->assertHasErrors(['signature']);

        $this->assertFalse($account->refresh()->hasSignature());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_a_rejected_file_is_not_left_picked_as_the_pending_selection(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        // A rejected file must not sit in the card as though it were about to
        // be saved: the preview, the filename and the Save button would all be
        // describing a file that can never be stored.
        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', UploadedFile::fake()->create('notes.txt', 4, 'text/plain'))
            ->assertHasErrors(['signature' => 'mimes'])
            ->assertSet('signature', null)
            ->assertDontSee('Not saved yet')
            ->assertDontSee('notes.txt')
            ->assertDontSee('Save signature');
    }

    public function test_a_rejected_oversized_file_is_not_left_picked_either(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('too-big.png', (int) config('profile.signature_max_kb') + 1))
            ->assertHasErrors(['signature' => 'max'])
            ->assertSet('signature', null)
            ->assertDontSee('Not saved yet');
    }

    public function test_an_image_renamed_from_another_file_type_is_refused(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        // The name claims .png, the content does not.
        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', UploadedFile::fake()->create('payload.png', 40, 'application/x-msdownload'))
            ->assertHasErrors(['signature' => 'mimes']);

        $this->assertFalse($account->refresh()->hasSignature());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_an_oversized_signature_is_refused(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        $limitKb = (int) config('profile.signature_max_kb');

        // Just over the configured limit, and one comfortably inside it.
        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('too-big.png', $limitKb + 1))
            ->assertHasErrors(['signature' => 'max']);

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('fine.png', $limitKb - 1))
            ->assertHasNoErrors();

        $this->assertFalse($account->refresh()->hasSignature());
    }

    public function test_a_refused_replacement_leaves_the_saved_signature_untouched(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image('original.png'))
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $savedPath = $account->signature_path;
        $savedUploadedAt = $account->signature_uploaded_at;

        // A bad file is picked, then saved anyway.
        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', UploadedFile::fake()->create('replacement.txt', 4, 'text/plain'))
            ->assertHasErrors('signature')
            ->call('save')
            ->assertHasErrors('signature');

        $account->refresh();

        $this->assertSame($savedPath, $account->signature_path);
        $this->assertSame('original.png', $account->signature_original_name);
        $this->assertTrue($savedUploadedAt->equalTo($account->signature_uploaded_at));
        Storage::disk('local')->assertExists($savedPath);
    }

    // ------------------------------------------------------------- removal

    public function test_removal_asks_for_confirmation_before_deleting(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image())
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $path = $account->signature_path;

        // Opening the dialog alone changes nothing.
        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->call('openRemoveModal')
            ->assertSet('showRemoveModal', true)
            ->assertSee('modal-backdrop is-open', false)
            ->assertSee('Remove Digital Signature')
            ->assertSee('Are you sure you want to remove');

        $this->assertTrue($account->refresh()->hasSignature());
        Storage::disk('local')->assertExists($path);

        // Confirming removes the file and the record together.
        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->call('removeSignature')
            ->assertSet('showRemoveModal', false)
            ->assertSee('Digital signature removed.')
            ->assertSee('Not uploaded')
            ->assertSee('Upload Digital Signature');

        $account->refresh();

        $this->assertFalse($account->hasSignature());
        $this->assertNull($account->signature_path);
        $this->assertNull($account->signature_original_name);
        $this->assertNull($account->signature_mime_type);
        $this->assertNull($account->signature_size);
        $this->assertNull($account->signature_uploaded_at);
        $this->assertNull($account->signature_updated_at);
        $this->assertNull($account->signatureUrl());

        Storage::disk('local')->assertMissing($path);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    // -------------------------------------------------- serving the preview

    public function test_the_preview_route_serves_the_callers_own_signature_inline(): void
    {
        Storage::fake('local');

        $account = $this->staff();

        Livewire::actingAs($account)->test(DigitalSignature::class)
            ->set('signature', $this->image())
            ->call('save')
            ->assertHasNoErrors();

        $response = $this->actingAs($account)->get(route('profile.signature'));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));

        // Personal data: not for shared caches, and not a download to keep.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_the_preview_route_refuses_an_account_with_no_signature(): void
    {
        Storage::fake('local');

        $this->actingAs($this->staff())->get(route('profile.signature'))->assertNotFound();
    }

    public function test_the_preview_route_never_serves_another_accounts_signature(): void
    {
        Storage::fake('local');

        $owner = $this->staff('owner_user');
        $other = $this->staff('other_user');

        Livewire::actingAs($owner)->test(DigitalSignature::class)
            ->set('signature', $this->image())
            ->call('save')
            ->assertHasNoErrors();

        // The route takes no path and no id: it can only ever answer with the
        // caller's own file, so the second account simply has none.
        $this->actingAs($other)->get(route('profile.signature'))->assertNotFound();
        $this->actingAs($owner)->get(route('profile.signature'))->assertOk();
    }
}
