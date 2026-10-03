<?php

namespace Tests\Feature\Support;

use App\Models\Faq;
use Database\Seeders\FaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FaqSeeder::class);
    }

    public function test_active_faqs_in_screen_order_and_request_language(): void
    {
        Faq::query()->create(['question' => ['ar' => 'سؤال مخفي', 'en' => 'Hidden'], 'answer' => ['ar' => 'x', 'en' => 'x'], 'is_active' => false]);
        Faq::query()->create(['question' => ['ar' => 'أول سؤال', 'en' => 'First'], 'answer' => ['ar' => 'جواب', 'en' => 'Answer'], 'sort_order' => 1]);

        $data = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/faqs')->assertOk()->json('data');

        $this->assertCount(7, $data['items']);
        $this->assertSame('أول سؤال', $data['items'][0]['question']);
        $this->assertNull($data['items'][0]['category']);
        $this->assertSame('كيف يمكنني إرسال هدية لشخص آخر؟', $data['items'][1]['question']);
        $this->assertSame(['id', 'question', 'answer', 'category', 'category_label'], array_keys($data['items'][1]));
        $this->assertSame('عام', $data['items'][1]['category_label']);
        $this->assertSame(['general', 'orders', 'payments', 'delivery', 'support'], array_column($data['categories'], 'key'));

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/faqs')
            ->assertJsonPath('data.items.1.question', 'How can I send a gift to someone else?')
            ->assertJsonPath('data.categories.1.label', 'Orders');
    }

    public function test_category_and_search_filters(): void
    {
        $this->getJson('/api/v1/faqs?category=orders')->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.filters.category', 'orders')
            ->assertJsonCount(5, 'data.categories');   // chips stay complete

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/faqs?search=الدفع')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.category', 'payments');

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/faqs?search=WHATSAPP')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.category', 'support');

        $this->getJson('/api/v1/faqs?category=refunds')->assertUnprocessable();
    }

    public function test_seeder_does_not_duplicate_or_overwrite(): void
    {
        Faq::query()->where('category', 'payments')->first()->update(['answer' => ['ar' => 'معدل', 'en' => 'Edited']]);

        $this->seed(FaqSeeder::class);

        $this->assertSame(6, Faq::count());
        $this->assertSame('Edited', Faq::query()->where('category', 'payments')->first()->getTranslation('answer', 'en'));
    }
}
