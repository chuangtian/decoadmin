<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_guest_is_redirected_from_root_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_user_is_redirected_from_root_to_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect('/dashboard');
    }

    public function test_cloudflare_forwarded_https_is_used_for_redirects(): void
    {
        $this->withHeaders([
            'X-Forwarded-Host' => 'example.trycloudflare.com',
            'X-Forwarded-Proto' => 'https',
        ])->get('/')->assertRedirect('https://example.trycloudflare.com/login');
    }
}
