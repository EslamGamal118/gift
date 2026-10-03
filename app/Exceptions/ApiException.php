<?php

namespace App\Exceptions;

use Exception;

/**
 * استثناء يُحوَّل تلقائيًا إلى استجابة ApiResponse موحّدة (انظر Handler).
 * يحمل مفتاح الرسالة المترجم ورمز الحالة وبيانات إضافية اختيارية.
 */
class ApiException extends Exception
{
    public function __construct(
        protected string $messageKey,
        protected int $statusCode = 400,
        protected ?array $data = null,
        protected array $replace = [],
    ) {
        parent::__construct($messageKey, $statusCode);
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * قيم الاستبدال داخل رسالة الترجمة (مثل :status)
     */
    public function getReplace(): array
    {
        return $this->replace;
    }
}
