<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAndPreferencesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Project} */
    private function userWithProject(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);

        return [$user, $project];
    }

    public function test_update_profile_persists_name_and_bio(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $res = $this->patchJson('/api/profile', [
            'name' => 'Maria Silva',
            'bio'  => 'Gerente de operações.',
        ]);

        $res->assertOk();
        $this->assertSame('Maria Silva', $res->json('me.name'));
        $this->assertSame('Gerente de operações.', $res->json('me.bio'));
        $this->assertSame('MS', $res->json('me.initials'));
        $this->assertDatabaseHas('users', [
            'id'   => $user->id,
            'name' => 'Maria Silva',
            'bio'  => 'Gerente de operações.',
        ]);
    }

    public function test_avatar_upload_replaces_previous_file(): void
    {
        Storage::fake('public');
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $first = UploadedFile::fake()->image('foto1.jpg');
        $res = $this->post('/api/profile/avatar', ['avatar' => $first]);
        $res->assertOk();

        $firstPath = $user->fresh()->avatar_path;
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);
        $this->assertStringContainsString($firstPath, $res->json('avatarUrl'));

        $second = UploadedFile::fake()->image('foto2.jpg');
        $res = $this->post('/api/profile/avatar', ['avatar' => $second]);
        $res->assertOk();

        $secondPath = $user->fresh()->avatar_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_avatar_upload_rejects_non_image_file(): void
    {
        Storage::fake('public');
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $file = UploadedFile::fake()->create('arquivo.txt', 10);
        $res = $this->post('/api/profile/avatar', ['avatar' => $file], ['Accept' => 'application/json']);

        $res->assertStatus(422);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_theme_preference_persists_and_appears_in_bootstrap(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        // padrão = claro
        $this->assertSame('claro', $this->getJson('/api/bootstrap')->json('prefs.theme'));

        $res = $this->putJson('/api/preferences', ['theme' => 'escuro']);
        $res->assertOk();
        $this->assertSame('escuro', $res->json('prefs.theme'));

        $this->assertSame('escuro', $this->getJson('/api/bootstrap')->json('prefs.theme'));
    }

    public function test_ai_activity_log_preference_persists(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        // padrão = ligado
        $this->assertTrue($this->getJson('/api/bootstrap')->json('prefs.aiActivityLog'));

        $res = $this->putJson('/api/preferences', ['aiActivityLog' => false]);
        $res->assertOk();
        $this->assertFalse($res->json('prefs.aiActivityLog'));
        $this->assertFalse($this->getJson('/api/bootstrap')->json('prefs.aiActivityLog'));
    }

    public function test_invalid_theme_is_rejected(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $res = $this->putJson('/api/preferences', ['theme' => 'neon'], ['Accept' => 'application/json']);
        $res->assertStatus(422);
    }

    public function test_preferences_update_persists_assistant_customization_and_appears_in_bootstrap(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $res = $this->putJson('/api/preferences', [
            'assistantName'   => 'Nina',
            'assistantAvatar' => 'owl',
        ]);
        $res->assertOk();
        $this->assertSame('Nina', $res->json('prefs.assistantName'));
        $this->assertSame('owl', $res->json('prefs.assistantAvatar'));

        $res = $this->getJson('/api/bootstrap');
        $res->assertOk();
        $this->assertSame('Nina', $res->json('prefs.assistantName'));
        $this->assertSame('owl', $res->json('prefs.assistantAvatar'));
        $this->assertSame($user->name, $res->json('me.name'));
        $this->assertArrayHasKey('bio', $res->json('me'));
        $this->assertArrayHasKey('avatarUrl', $res->json('me'));
    }
}
