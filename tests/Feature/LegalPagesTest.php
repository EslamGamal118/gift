<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use Database\Seeders\LegalPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LegalPageSeeder::class);
    }

    public function test_a_page_comes_in_the_request_language(): void
    {
        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/legal/privacy_policy')
            ->assertOk()
            ->assertJsonPath('data.type', 'privacy_policy')
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.title', 'سياسة الخصوصية')
            ->assertJsonCount(4, 'data.sections')
            ->assertJsonPath('data.sections.0.key', 'personal_data_collection')
            ->assertJsonPath('data.sections.0.heading', 'جمع البيانات الشخصية')
            ->assertJsonPath('data.sections.3.key', 'user_rights_control');

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/legal/terms_and_conditions')
            ->assertOk()
            ->assertJsonPath('data.title', 'Terms & Conditions')
            ->assertJsonPath('data.sections.0.heading', 'Introduction & Welcome')
            ->assertJsonPath('data.sections.3.key', 'intellectual_property');
    }

    public function test_locale_all_returns_every_language_with_matching_keys(): void
    {
        $data = $this->getJson('/api/v1/legal/terms_and_conditions?locale=all')->assertOk()->json('data');

        $this->assertSame(['ar' => 'الشروط والأحكام', 'en' => 'Terms & Conditions'], $data['title']);
        $this->assertSame(array_column($data['sections']['ar'], 'key'), array_column($data['sections']['en'], 'key'));
    }

    public function test_menu_lists_published_pages_and_unknown_or_unpublished_pages_are_404(): void
    {
        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/legal')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.type', 'privacy_policy')
            ->assertJsonPath('data.items.1.title', 'الشروط والأحكام');

        $this->getJson('/api/v1/legal/cookies')->assertNotFound();

        LegalPage::query()->where('type', LegalPage::TYPE_PRIVACY_POLICY)->update(['is_published' => false]);
        $this->getJson('/api/v1/legal/privacy_policy')->assertNotFound();
        $this->getJson('/api/v1/legal')->assertJsonCount(1, 'data.items');
    }

    public function test_seeder_can_run_again(): void
    {
        $this->seed(LegalPageSeeder::class);

        $this->assertSame(2, LegalPage::count());
    }
}
