<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the login screen this app actually ships: a "Username" field that
 * resolves against either the name or the email column.
 *
 * (The sibling Breeze scaffold tests target a /login that posts "email" and
 * redirects to a "dashboard" named route -- neither exists here. They were
 * already failing before this screen was built.)
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_user_can_sign_in_with_their_username(): void
    {
        $user = User::factory()->create(['name' => 'admin']);

        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
    }

    public function test_a_user_can_sign_in_with_their_email(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->post('/login', [
            'username' => 'admin@example.com',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['name' => 'admin']);

        $this->post('/login', [
            'username' => 'admin',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_both_fields_are_required(): void
    {
        $this->post('/login', ['username' => '', 'password' => ''])
            ->assertSessionHasErrors(['username', 'password']);

        $this->assertGuest();
    }

    public function test_a_signed_in_user_is_kept_off_the_login_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect('/');
    }

    public function test_a_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_signing_in_returns_the_user_to_the_page_they_wanted(): void
    {
        User::factory()->create(['name' => 'admin']);

        // The auth middleware stashes the blocked URL; redirect()->intended() uses it.
        $this->get('/importation')->assertRedirect('/login');

        $this->post('/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->assertRedirect('/importation');
    }
}
