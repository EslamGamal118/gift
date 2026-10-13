<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Filament\Pages\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $type, string $status = 'active'): User
    {
        return User::create([
            'name' => 'Test',
            'phone' => '9665'.random_int(10000000, 99999999),
            'email' => $type.'@gift.test',
            'password' => 'password',
            'user_type' => $type,
            'status' => $status,
        ]);
    }

    public function test_login_page_renders_in_arabic_rtl(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('dir="rtl"', false);
    }

    public function test_active_admin_can_log_in_and_open_dashboard(): void
    {
        $admin = $this->makeUser(User::TYPE_ADMIN);

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk();
    }

    public function test_non_admin_and_blocked_admin_are_denied(): void
    {
        $this->actingAs($this->makeUser(User::TYPE_CUSTOMER))->get('/admin')->assertForbidden();
        $this->actingAs($this->makeUser(User::TYPE_ADMIN, 'blocked'))->get('/admin')->assertForbidden();
    }
}
