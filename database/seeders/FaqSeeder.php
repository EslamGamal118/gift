<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Starter FAQ items until the dashboard manages them. Re-running only adds
 * the questions that are missing (matched on the Arabic text); edits made
 * since are kept.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->faqs() as $order => [$category, $question, $answer]) {
            if (Faq::query()->where('question->ar', $question['ar'])->exists()) {
                continue;
            }

            Faq::query()->create([
                'category' => $category,
                'question' => $question,
                'answer' => $answer,
                'sort_order' => ($order + 1) * 10,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return list<array{0: string, 1: array<string, string>, 2: array<string, string>}>
     */
    protected function faqs(): array
    {
        return [
            ['general',
                ['ar' => 'كيف يمكنني إرسال هدية لشخص آخر؟', 'en' => 'How can I send a gift to someone else?'],
                ['ar' => 'اختر الهدية من المتجر، ثم أدخل بيانات المستلم ورسالة الإهداء عند إتمام الطلب، وسنتولى توصيلها إليه في الموعد الذي تحدده.', 'en' => 'Choose the gift from a store, then enter the recipient\'s details and your gift message at checkout, and we will deliver it at the time you choose.']],
            ['orders',
                ['ar' => 'كيف أتابع حالة طلبي؟', 'en' => 'How do I track my order?'],
                ['ar' => 'من قائمة "طلباتي" اختر الطلب لعرض حالته خطوة بخطوة، وستصلك إشعارات عند كل تحديث.', 'en' => 'Open "My orders" and select the order to see its status step by step. You will also get a notification at every update.']],
            ['orders',
                ['ar' => 'هل يمكنني إلغاء الطلب بعد إتمامه؟', 'en' => 'Can I cancel an order after placing it?'],
                ['ar' => 'يمكن إلغاء الطلب قبل أن يبدأ المتجر بتجهيزه. بعد ذلك يرجى التواصل مع فريق الدعم لمساعدتك.', 'en' => 'An order can be cancelled before the store starts preparing it. After that, please contact our support team for help.']],
            ['payments',
                ['ar' => 'ما هي طرق الدفع المتاحة؟', 'en' => 'Which payment methods are available?'],
                ['ar' => 'نقبل الدفع بالبطاقات البنكية عبر بوابة الراجحي، كما يمكنك تقسيط المبلغ عبر تابي وتمارا.', 'en' => 'We accept bank cards through the Al Rajhi gateway, and you can split the amount into instalments with Tabby or Tamara.']],
            ['delivery',
                ['ar' => 'كم يستغرق توصيل الطلب؟', 'en' => 'How long does delivery take?'],
                ['ar' => 'يمكنك اختيار التوصيل الفوري ليصل الطلب خلال أقل من ساعة عند توفره، أو جدولة التوصيل في اليوم والوقت المناسبين لك.', 'en' => 'Choose instant delivery to receive your order in under an hour where available, or schedule delivery for the day and time that suits you.']],
            ['support',
                ['ar' => 'كيف أتواصل مع خدمة العملاء؟', 'en' => 'How do I contact customer service?'],
                ['ar' => 'يمكنك التواصل معنا من صفحة "تواصل معنا" عبر البريد الإلكتروني أو الواتساب، أو بإرسال رسالة مباشرة وسنرد عليك في أقرب وقت.', 'en' => 'Reach us from the "Contact us" page by email or WhatsApp, or send us a message directly and we will reply as soon as possible.']],
        ];
    }
}
