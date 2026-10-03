<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * تخزين الملفات المرفوعة على القرص العام بأسماء عشوائية آمنة،
 * مع حذف الملف القديم بعد نجاح المعاملة فقط (حتى لا نفقده إن فشلت العملية).
 */
class FileUploadService
{
    public function __construct(
        protected string $disk = 'public',
    ) {
    }

    /**
     * تخزين ملف جديد وإرجاع مساره، وجدولة حذف الملف السابق (إن وُجد) بعد الـ commit.
     */
    public function replace(UploadedFile $file, string $directory, ?string $previous = null): string
    {
        // store() يولّد اسمًا عشوائيًا ويستنتج الامتداد من نوع الملف الحقيقي، لا من اسمه الأصلي
        $path = $file->store(trim($directory, '/'), $this->disk);

        if ($path === false) {
            throw new \RuntimeException("Unable to store uploaded file on disk [{$this->disk}].");
        }

        if ($previous && $previous !== $path) {
            $this->deleteAfterCommit($previous);
        }

        return $path;
    }

    public function deleteAfterCommit(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        DB::afterCommit(fn () => $this->delete($path));
    }

    public function delete(?string $path): void
    {
        if (blank($path) || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk($this->disk)->delete($path);
    }
}
