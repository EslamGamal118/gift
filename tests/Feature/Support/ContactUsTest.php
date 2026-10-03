<?php

namespace Tests\Feature\Support;

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactUsTest extends TestCase
{
    use RefreshDatabase;

    protected const MESSAGE = 'لم تصلني الهدية في الموعد المحدد، أرجو المساعدة.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['checkout.support' => [
            'email' => 'support@tahaadoo.sa', 'phone' => '+966 50 123 4567', 'whatsapp' => '+966 50 123 4567', 'inbox' => 'team@tahaadoo.sa',
        ]]);
        Mail::fake();
    }

    public function test_a_guest_message_is_saved_and_emailed_to_the_support_inbox(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/support/contact', ['name' => 'سارة', 'email' => 'sara@example.com', 'message' => self::MESSAGE])
            ->assertCreated()
            ->assertJsonPath('message', 'تم إرسال رسالتك بنجاح، سنتواصل معك قريباً.')
            ->assertJsonPath('data.name', 'سارة');

        $contact = ContactMessage::query()->sole();
        $this->assertNull($contact->user_id);
        $this->assertSame(self::MESSAGE, $contact->message);

        Mail::assertQueued(ContactMessageReceived::class, fn (ContactMessageReceived $mail) => $mail->hasTo('team@tahaadoo.sa')
            && $mail->hasReplyTo('sara@example.com')
            && $mail->contact->is($contact));
    }

    public function test_a_signed_in_user_is_linked_and_their_name_and_email_fill_the_gaps(): void
    {
        $user = User::factory()->customer()->create(['name' => 'أحمد عبدالله', 'email' => 'ahmed@example.com']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/support/contact', ['message' => self::MESSAGE])->assertCreated();

        $this->assertDatabaseHas('contact_messages', ['user_id' => $user->id, 'name' => 'أحمد عبدالله', 'email' => 'ahmed@example.com']);

        // Sent values win over the account's
        $this->postJson('/api/v1/support/contact', ['name' => 'Ahmed', 'email' => 'other@example.com', 'message' => self::MESSAGE])->assertCreated();
        $this->assertDatabaseHas('contact_messages', ['user_id' => $user->id, 'email' => 'other@example.com']);
    }

    public function test_validation(): void
    {
        $this->postJson('/api/v1/support/contact', ['email' => 'not-an-email', 'message' => 'قصيرة'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'message'], 'data.errors');

        // A signed-in user without an email on file must type one
        Sanctum::actingAs(User::factory()->customer()->create(['email' => null]));
        $this->postJson('/api/v1/support/contact', ['message' => self::MESSAGE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'], 'data.errors');

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_a_mail_failure_never_loses_the_message(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->postJson('/api/v1/support/contact', ['name' => 'سارة', 'email' => 'sara@example.com', 'message' => self::MESSAGE])->assertCreated();

        $this->assertSame(1, ContactMessage::count());
    }

    public function test_contact_info_cards_and_prefill(): void
    {
        $this->getJson('/api/v1/support/contact-info')
            ->assertOk()
            ->assertJsonPath('data.email', ['value' => 'support@tahaadoo.sa', 'url' => 'mailto:support@tahaadoo.sa'])
            ->assertJsonPath('data.phone.url', 'tel:+966501234567')
            ->assertJsonPath('data.whatsapp.url', 'https://wa.me/966501234567')
            ->assertJsonPath('data.prefill', null)
            ->assertJsonMissingPath('data.inbox');

        Sanctum::actingAs(User::factory()->customer()->create(['name' => 'منى', 'email' => 'mona@example.com']));
        $this->getJson('/api/v1/support/contact-info')->assertJsonPath('data.prefill', ['name' => 'منى', 'email' => 'mona@example.com']);
    }

    public function test_the_form_is_rate_limited(): void
    {
        $body = ['name' => 'سارة', 'email' => 'sara@example.com', 'message' => self::MESSAGE];

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/support/contact', $body)->assertCreated();
        }

        $this->postJson('/api/v1/support/contact', $body)->assertStatus(429);
    }
}
