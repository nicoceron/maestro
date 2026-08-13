<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Filament\Resources\LessonNoteTemplates\LessonNoteTemplateResource;
use App\Filament\Resources\LessonNoteTemplates\Pages\ManageLessonNoteTemplates;
use App\Filament\Resources\LessonNoteTemplates\Support\LessonNoteTemplateApi;
use App\Models\LessonNoteTemplate;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class LessonNoteTemplateResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_edits_retires_and_reactivates_with_immutable_revisions(): void
    {
        config(['session.driver' => 'array']);
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        $this->get(LessonNoteTemplateResource::getUrl(tenant: $studio))->assertOk();
        $this->filamentAs($owner, $studio);

        $component = Livewire::test(ManageLessonNoteTemplates::class)
            ->assertSuccessful()
            ->assertActionVisible('createTemplate')
            ->callAction('createTemplate', data: [
                'idempotency_key' => (string) Str::uuid(),
                'name' => '  Weekly   recap ',
                'audience' => 'guardian',
                'body_html' => '<p>Practice slowly.</p><script>alert(1)</script>',
            ])->assertNotified('Template created');

        $template = LessonNoteTemplate::query()->sole();
        $this->assertSame('Weekly recap', $template->name);
        $this->assertStringNotContainsString('<script', $template->body_html);
        $this->assertSame(1, $template->version);
        $this->assertDatabaseHas('lesson_note_template_revisions', [
            'studio_id' => $studio->getKey(),
            'lesson_note_template_id' => $template->getKey(),
            'revision' => 1,
            'reason' => 'Template created.',
        ]);

        $component->callAction(TestAction::make('editTemplate')->table($template), data: [
            'idempotency_key' => (string) Str::uuid(),
            'version' => 1,
            'name' => 'Weekly recap',
            'audience' => 'author_private',
            'body_html' => '<p>Private teacher checklist.</p>',
            'active' => false,
            'reason' => 'Retired while the curriculum is reviewed.',
        ])->assertNotified('Template updated');

        $template->refresh();
        $this->assertSame('author_private', $template->audience->value);
        $this->assertFalse($template->active);
        $this->assertSame(2, $template->version);
        $this->assertDatabaseHas('lesson_note_template_revisions', [
            'lesson_note_template_id' => $template->getKey(),
            'revision' => 2,
            'active' => false,
            'reason' => 'Retired while the curriculum is reviewed.',
        ]);

        $component->callAction(TestAction::make('editTemplate')->table($template), data: [
            'idempotency_key' => (string) Str::uuid(),
            'version' => 2,
            'name' => 'Weekly recap',
            'audience' => 'author_private',
            'body_html' => '<p>Private teacher checklist.</p>',
            'active' => true,
            'reason' => 'Approved for the new term.',
        ])->assertNotified('Template updated');

        $this->assertTrue($template->refresh()->active);
        $this->assertSame(3, $template->version);
        $this->assertDatabaseCount('lesson_note_template_revisions', 3);
    }

    public function test_create_has_exact_idempotent_replay_and_stale_edit_is_rejected(): void
    {
        [$administrator, $studio] = $this->member(MembershipRole::Administrator);
        $this->filamentAs($administrator, $studio);
        $api = app(LessonNoteTemplateApi::class);
        $key = (string) Str::uuid();
        $payload = [
            'name' => 'Practice goals',
            'audience' => 'student',
            'body_html' => '<p>Set three measurable goals.</p>',
        ];
        $first = $api->create($studio, $administrator, $payload, $key);
        $replay = $api->create($studio, $administrator, $payload, $key);

        $this->assertSame($first->getKey(), $replay->getKey());
        $this->assertDatabaseCount('lesson_note_templates', 1);
        $this->assertDatabaseCount('lesson_note_template_revisions', 1);

        $component = Livewire::test(ManageLessonNoteTemplates::class)
            ->mountAction(TestAction::make('editTemplate')->table($first))
            ->assertActionDataSet(['version' => 1]);

        $api->update($studio, $first, $administrator, [
            'version' => 1,
            'name' => 'Practice goals',
            'audience' => 'student',
            'body_html' => '<p>Set four measurable goals.</p>',
            'active' => true,
            'reason' => 'Updated in another browser.',
        ], (string) Str::uuid());

        $component->setActionData([
            'name' => 'Practice goals',
            'audience' => 'guardian',
            'body_html' => '<p>Stale content.</p>',
            'active' => true,
            'reason' => 'This browser is stale.',
        ])->callMountedAction()->assertNotified('The template was not changed');

        $first->refresh();
        $this->assertSame(2, $first->version);
        $this->assertSame('student', $first->audience->value);
        $this->assertSame('<p>Set four measurable goals.</p>', $first->body_html);
        $this->assertDatabaseCount('lesson_note_template_revisions', 2);
    }

    public function test_teacher_sees_only_active_shared_or_private_defaults_read_only_and_billing_is_denied(): void
    {
        config(['session.driver' => 'array']);
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        $api = app(LessonNoteTemplateApi::class);
        $shared = $api->create($studio, $owner, [
            'name' => 'Guardian recap', 'audience' => 'guardian', 'body_html' => '<p>Shared recap.</p>',
        ], (string) Str::uuid());
        $private = $api->create($studio, $owner, [
            'name' => 'Private checklist', 'audience' => 'author_private', 'body_html' => '<p>Private-by-default.</p>',
        ], (string) Str::uuid());
        $inactive = $api->create($studio, $owner, [
            'name' => 'Old recap', 'audience' => 'student', 'body_html' => '<p>Historical.</p>',
        ], (string) Str::uuid());
        $api->update($studio, $inactive, $owner, [
            'version' => 1,
            'active' => false,
            'reason' => 'Retired from teaching use.',
        ], (string) Str::uuid());

        [$teacher, , $teacherMembership] = $this->member(MembershipRole::Teacher, $studio);
        $this->filamentAs($teacher, $studio, $teacherMembership);
        $this->assertTrue(LessonNoteTemplateResource::canViewAny());
        $this->assertFalse(LessonNoteTemplateResource::canCreate());
        Livewire::test(ManageLessonNoteTemplates::class)
            ->assertSuccessful()
            ->assertActionHidden('createTemplate')
            ->assertCanSeeTableRecords([$shared, $private])
            ->assertCanNotSeeTableRecords([$inactive])
            ->assertActionHidden(TestAction::make('editTemplate')->table($shared));

        [$billing, , $billingMembership] = $this->member(MembershipRole::Billing, $studio);
        $this->filamentAs($billing, $studio, $billingMembership);
        $this->assertFalse(LessonNoteTemplateResource::canViewAny());
        $this->get(LessonNoteTemplateResource::getUrl(tenant: $studio))->assertForbidden();
    }

    public function test_resource_query_and_mutations_are_tenant_scoped(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        [$otherOwner, $otherStudio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($otherOwner, $otherStudio);
        $other = app(LessonNoteTemplateApi::class)->create($otherStudio, $otherOwner, [
            'name' => 'Other studio', 'audience' => 'student', 'body_html' => '<p>Never visible here.</p>',
        ], (string) Str::uuid());

        $this->filamentAs($owner, $studio);
        Livewire::test(ManageLessonNoteTemplates::class)
            ->assertCanNotSeeTableRecords([$other]);
        $this->assertFalse(LessonNoteTemplateResource::canEdit($other));
    }

    private function filamentAs(User $user, Studio $studio, ?StudioMembership $membership = null): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        $membership ??= StudioMembership::query()
            ->where('studio_id', $studio->getKey())->where('user_id', $user->getKey())->sole();
        app(TenantContext::class)->activate($studio, $membership);
    }

    /** @return array{User, Studio, StudioMembership} */
    private function member(MembershipRole $role, ?Studio $studio = null): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $studio ??= Studio::factory()->create(['timezone' => 'UTC']);
        $membership = StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);

        return [$user, $studio, $membership];
    }
}
