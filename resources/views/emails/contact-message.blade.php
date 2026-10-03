<x-mail::message>
# رسالة جديدة من صفحة "تواصل معنا"

**رقم الرسالة:** #{{ $contact->id }}

**الاسم:** {{ $contact->name }}

**البريد الإلكتروني:** {{ $contact->email }}

**الحساب:** {{ $contact->user_id ? 'مستخدم مسجل #'.$contact->user_id.($contact->user?->phone ? ' ('.$contact->user->phone.')' : '') : 'زائر' }}

**التاريخ:** {{ $contact->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}

<x-mail::panel>
{{ $contact->message }}
</x-mail::panel>

يمكنك الرد على هذا البريد مباشرة للتواصل مع المرسل.
</x-mail::message>
