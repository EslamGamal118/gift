<?php

namespace Database\Seeders;

use App\Models\LegalPage;
use Illuminate\Database\Seeder;

/**
 * Legal center pages until the dashboard edits them. Idempotent: re-running
 * refreshes the text of each page type.
 */
class LegalPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $type => $page) {
            LegalPage::query()->updateOrCreate(['type' => $type], $page + ['is_published' => true]);
        }
    }

    /**
     * @return array<string, array{title: array<string, string>, content: array<string, list<array{key: string, heading: string, body: string}>>}>
     */
    protected function pages(): array
    {
        return [
            LegalPage::TYPE_PRIVACY_POLICY => [
                'title' => ['ar' => 'سياسة الخصوصية', 'en' => 'Privacy Policy'],
                'content' => $this->sections([
                    'personal_data_collection' => [
                        'ar' => ['جمع البيانات الشخصية', 'نقوم بجمع البيانات التي تقدمها لنا عند إنشاء حسابك أو إتمام طلبك، مثل الاسم ورقم الجوال والبريد الإلكتروني وعناوين التوصيل، إضافةً إلى بيانات المستلم عند إرسال هدية. كما نجمع بعض البيانات التقنية مثل نوع الجهاز وموقعك التقريبي لتحسين تجربتك وعرض المتاجر القريبة منك.'],
                        'en' => ['Personal Data Collection', 'We collect the data you provide when creating your account or placing an order, such as your name, mobile number, email address and delivery addresses, as well as the recipient\'s details when you send a gift. We also collect some technical data, such as your device type and approximate location, to improve your experience and show stores near you.'],
                    ],
                    'how_we_use_information' => [
                        'ar' => ['كيف نستخدم المعلومات', 'نستخدم بياناتك لتنفيذ طلباتك وتوصيل الهدايا، والتواصل معك بشأن حالة الطلب، ومعالجة المدفوعات بشكل آمن، وتحسين خدماتنا وتخصيص العروض التي تناسبك. لن نستخدم بياناتك لأي غرض آخر دون موافقتك.'],
                        'en' => ['How We Use Information', 'We use your data to fulfil your orders and deliver gifts, contact you about your order status, process payments securely, and improve our services and tailor offers to you. We will not use your data for any other purpose without your consent.'],
                    ],
                    'data_sharing_protection' => [
                        'ar' => ['مشاركة البيانات وحمايتها', 'نشارك البيانات اللازمة فقط مع المتاجر ومندوبي التوصيل وبوابات الدفع لإتمام طلبك. لا نبيع بياناتك لأي طرف ثالث، ونحميها باستخدام التشفير وضوابط وصول صارمة وفق أفضل الممارسات الأمنية.'],
                        'en' => ['Data Sharing & Protection', 'We share only the data needed to complete your order with stores, couriers and payment gateways. We never sell your data to third parties, and we protect it with encryption and strict access controls following security best practices.'],
                    ],
                    'user_rights_control' => [
                        'ar' => ['حقوق المستخدم والتحكم', 'يحق لك الاطلاع على بياناتك وتعديلها أو طلب حذف حسابك في أي وقت من خلال إعدادات الحساب أو بالتواصل مع فريق الدعم، كما يمكنك إيقاف الإشعارات التسويقية متى شئت.'],
                        'en' => ['User Rights & Control', 'You may view and edit your data or request deletion of your account at any time from your account settings or by contacting our support team. You can also turn off marketing notifications whenever you like.'],
                    ],
                ]),
            ],

            LegalPage::TYPE_TERMS_AND_CONDITIONS => [
                'title' => ['ar' => 'الشروط والأحكام', 'en' => 'Terms & Conditions'],
                'content' => $this->sections([
                    'introduction' => [
                        'ar' => ['مقدمة وترحيب', 'مرحباً بك في تطبيقنا لإرسال الهدايا. باستخدامك للتطبيق فإنك توافق على هذه الشروط والأحكام، لذا نرجو قراءتها بعناية قبل البدء في استخدام خدماتنا.'],
                        'en' => ['Introduction & Welcome', 'Welcome to our gifting app. By using the app you agree to these terms and conditions, so please read them carefully before you start using our services.'],
                    ],
                    'terms_of_use' => [
                        'ar' => ['شروط الاستخدام', 'يجب أن تكون البيانات التي تقدمها صحيحة ومحدثة، وأن تستخدم التطبيق لأغراض مشروعة فقط. أنت مسؤول عن الحفاظ على سرية حسابك وعن جميع الطلبات التي تتم من خلاله، ويحق لنا تعليق أي حساب يخالف هذه الشروط.'],
                        'en' => ['Terms of Use', 'The information you provide must be accurate and up to date, and you may use the app for lawful purposes only. You are responsible for keeping your account secure and for all orders placed through it, and we may suspend any account that breaches these terms.'],
                    ],
                    'legal_liability' => [
                        'ar' => ['المسؤولية القانونية', 'يعمل التطبيق وسيطاً بين العملاء والمتاجر، وتتحمل المتاجر مسؤولية جودة منتجاتها ومطابقتها للوصف. لا نتحمل المسؤولية عن أي تأخير أو ضرر ناتج عن ظروف خارجة عن إرادتنا، وذلك في حدود ما تسمح به الأنظمة المعمول بها في المملكة العربية السعودية.'],
                        'en' => ['Legal Liability', 'The app acts as an intermediary between customers and stores, and stores are responsible for the quality of their products and their match with the description. We are not liable for any delay or damage caused by circumstances beyond our control, to the extent permitted by the laws in force in the Kingdom of Saudi Arabia.'],
                    ],
                    'intellectual_property' => [
                        'ar' => ['الملكية الفكرية', 'جميع المحتويات في التطبيق من نصوص وتصاميم وشعارات وصور هي ملك لنا أو لمرخصينا ومحمية بموجب أنظمة الملكية الفكرية، ولا يجوز نسخها أو إعادة استخدامها دون إذن كتابي مسبق.'],
                        'en' => ['Intellectual Property', 'All content in the app, including text, designs, logos and images, belongs to us or our licensors and is protected by intellectual property laws. It may not be copied or reused without prior written permission.'],
                    ],
                ]),
            ],
        ];
    }

    /**
     * {key: {ar: [heading, body], en: [heading, body]}} -> {ar: [{key, heading, body}], en: [...]}
     *
     * @param  array<string, array<string, array{0: string, 1: string}>>  $sections
     * @return array<string, list<array{key: string, heading: string, body: string}>>
     */
    protected function sections(array $sections): array
    {
        $content = [];

        foreach (LegalPage::LOCALES as $locale) {
            foreach ($sections as $key => $translations) {
                [$heading, $body] = $translations[$locale];
                $content[$locale][] = ['key' => $key, 'heading' => $heading, 'body' => $body];
            }
        }

        return $content;
    }
}
