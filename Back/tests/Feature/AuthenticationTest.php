<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_register_and_password_is_hashed(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationData('student'));

        $response->assertCreated()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', 'student@example.test')->firstOrFail();
        $this->assertSame('student', $user->role);
        $this->assertSame('+216 20 000 001', $user->phone);
        $this->assertTrue(Hash::check('a-secure-password', $user->password));
        $this->assertNotSame('a-secure-password', $user->password);
        $this->getJson('/api/auth/user')->assertOk()->assertJsonMissingPath('user.password');
    }

    public function test_property_owner_can_register(): void
    {
        $this->postJson('/api/auth/register', $this->registrationData('owner'))
            ->assertCreated()
            ->assertJsonPath('user.role', 'owner');
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        User::factory()->create(['email' => 'student@example.test']);

        $this->postJson('/api/auth/register', $this->registrationData('student'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_invalid_registration_data_is_rejected(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'phone' => '',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'password', 'role']);
    }

    public function test_public_registration_cannot_create_a_worker(): void
    {
        $this->postJson('/api/auth/register', $this->registrationData('worker'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_user_role_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Test Account',
            'email' => 'student@example.test',
            'password' => Hash::make('a-secure-password'),
            'role' => 'owner',
        ]);
        $user->refresh();

        $this->assertSame('student', $user->role);
        $this->assertFalse($user->isFillable('role'));
    }

    public function test_student_login_uses_stored_role_for_dashboard_access(): void
    {
        $user = User::factory()->create([
            'email' => 'student@example.test',
            'password' => Hash::make('a-secure-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'a-secure-password',
        ])->assertOk()->assertJsonPath('user.role', 'student');

        $this->getJson('/api/student/dashboard')->assertOk()
            ->assertJsonPath('dashboard', 'student');
        $this->getJson('/api/owner/dashboard')->assertForbidden();
    }

    public function test_owner_login_uses_stored_role_for_dashboard_access(): void
    {
        $user = $this->createUser('owner', 'owner@example.test');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'a-secure-password',
        ])->assertOk()->assertJsonPath('user.role', 'owner');

        $this->getJson('/api/owner/dashboard')->assertOk()
            ->assertJsonPath('dashboard', 'owner');
        $this->getJson('/api/student/dashboard')->assertForbidden();
    }

    public function test_invalid_login_credentials_are_rejected(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('user');
    }

    public function test_authenticated_session_cannot_be_replaced_by_a_direct_login_request(): void
    {
        $signedInUser = $this->createUser('owner', 'owner@example.test');
        $otherUser = $this->createUser('student', 'student@example.test');
        $this->actingAs($signedInUser);

        $this->postJson('/api/auth/login', [
            'email' => $otherUser->email,
            'password' => 'a-secure-password',
        ])->assertConflict();

        $this->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.id', $signedInUser->id)
            ->assertJsonPath('user.role', 'owner');
    }

    public function test_dashboards_require_authentication(): void
    {
        $this->getJson('/api/student/dashboard')->assertUnauthorized();
        $this->getJson('/api/owner/dashboard')->assertUnauthorized();
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    private function registrationData(string $role): array
    {
        return [
            'name' => 'Test Account',
            'email' => 'student@example.test',
            'phone' => '+216 20 000 001',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
            'role' => $role,
        ];
    }

    private function createUser(string $role, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('a-secure-password'),
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
