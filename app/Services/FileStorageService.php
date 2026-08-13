<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use RuntimeException;

/**
 * تخزين الملفات المرفوعة | Upload storage service (§9).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * كل رفع يمرّ من هنا. الفحوص متراكبة عمداً — أي فحص وحده قابل للتحايل:
 *  1. خطأ الرفع نفسه من PHP.
 *  2. الحجم مقابل حدّ النوع المُعدّ.
 *  3. الامتداد ضمن قائمة سماح النوع.
 *  4. الامتداد ليس ضمن قائمة المنع المطلقة (php, phtml, svg, html…).
 *  5. نوع MIME الحقيقي من finfo — لا من المتصفح، فهو تحت سيطرة المُرسِل.
 *  6. تطابق MIME الحقيقي مع قائمة سماح النوع.
 *  7. للصور: التحقق من كونها صورة صالحة فعلاً، ثم إعادة الترميز لإزالة أي
 *     حمولة مدسوسة في البيانات الوصفية.
 *  8. اسم تخزين عشوائي لا علاقة له باسم المستخدم الأصلي.
 *  9. التخزين خارج جذر الويب بصلاحيات 0640.
 *
 * Every upload passes through here. The checks are deliberately layered: each
 * one alone is bypassable. Notably, the browser-supplied MIME type is never
 * trusted, images are re-encoded to strip embedded payloads, and stored
 * filenames are random so an attacker cannot predict or guess a path.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class FileStorageService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * رفع ملف وتسجيله في جدول الوسائط | Store an upload and register it.
     *
     * @param array<string,mixed> $file        مدخل من $_FILES
     * @param string              $typeKey     image|document|spreadsheet
     * @param string              $collection  المجلد المنطقي
     *
     * @throws ValidationException عند فشل أي فحص — برسالة عربية للمستخدم
     * @return int معرّف السجل في `media`
     */
    public function store(
        array $file,
        string $typeKey,
        string $collection,
        ?int $organizationId,
        ?int $uploadedBy,
        string $visibility = 'private',
    ): int {
        $rules = $this->rulesFor($typeKey);

        $this->assertUploadSucceeded($file);
        $this->assertSize($file, $rules['max_size']);

        $extension = $this->safeExtension((string) ($file['name'] ?? ''));
        $this->assertExtensionAllowed($extension, $rules['extensions']);

        $mime = $this->detectMime((string) $file['tmp_name']);
        $this->assertMimeAllowed($mime, $rules['mimes'], $extension);

        $directory = $this->prepareDirectory($collection, $organizationId);
        $filename  = $this->randomFilename($extension);
        $fullPath  = $directory['absolute'] . '/' . $filename;

        if ($rules['re_encode'] === true) {
            $this->reEncodeImage((string) $file['tmp_name'], $fullPath, $extension);
        } else {
            if (!@move_uploaded_file((string) $file['tmp_name'], $fullPath)) {
                // احتياطي للاختبارات التي لا تمرّ عبر رفع HTTP حقيقي
                if (!@rename((string) $file['tmp_name'], $fullPath) && !@copy((string) $file['tmp_name'], $fullPath)) {
                    throw new ValidationException(
                        ['file' => __('validation.file_upload_failed')],
                    );
                }
            }
        }

        @chmod($fullPath, 0640);

        $size     = (int) (@filesize($fullPath) ?: 0);
        $checksum = (string) (@hash_file('sha256', $fullPath) ?: '');

        $mediaId = Database::insert(
            'INSERT INTO media
                (organization_id, uploaded_by, disk_path, original_name, extension,
                 mime_type, size_bytes, checksum, visibility, collection)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $uploadedBy,
                $directory['relative'] . '/' . $filename,
                // الاسم الأصلي للعرض فقط — يُشذَّب ويُهرَّب عند الطباعة
                mb_substr($this->sanitiseDisplayName((string) ($file['name'] ?? 'file')), 0, 255),
                $extension,
                $mime,
                $size,
                $checksum,
                $visibility === 'public' ? 'public' : 'private',
                $collection,
            ],
        );

        Logger::info('File stored', [
            'media_id'     => $mediaId,
            'collection'   => $collection,
            'organization' => $organizationId,
            'size'         => $size,
            'mime'         => $mime,
        ]);

        return $mediaId;
    }

    // ---------------- الفحوص | Validation layers ----------------

    private function assertUploadSucceeded(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            if (!isset($file['tmp_name']) || !is_file((string) $file['tmp_name'])) {
                throw new ValidationException(['file' => __('validation.file_upload_failed')]);
            }

            return;
        }

        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('validation.file_too_large', ['max' => $this->formatBytes($this->phpUploadLimit())]),
            UPLOAD_ERR_NO_FILE                        => __('validation.file_required'),
            UPLOAD_ERR_PARTIAL                        => __('validation.file_upload_failed'),
            default                                   => __('validation.file_upload_failed'),
        };

        throw new ValidationException(['file' => $message]);
    }

    private function assertSize(array $file, int $maxSize): void
    {
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new ValidationException(['file' => __('validation.file_corrupt')]);
        }

        if ($size > $maxSize) {
            throw new ValidationException([
                'file' => __('validation.file_too_large', ['max' => $this->formatBytes($maxSize)]),
            ]);
        }
    }

    /**
     * استخراج الامتداد بأمان | Extract the extension safely.
     *
     * يُؤخذ آخر امتداد فقط، فاسم مثل `report.pdf.php` امتداده `php` ويُرفض،
     * ولا يُخدع الفحص بامتداد مزدوج.
     */
    private function safeExtension(string $originalName): string
    {
        $basename  = basename(str_replace('\\', '/', $originalName));
        $extension = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));

        return preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
    }

    private function assertExtensionAllowed(string $extension, array $allowed): void
    {
        $forbidden = (array) Config::get('uploads.forbidden_extensions', []);

        if ($extension === '' || in_array($extension, $forbidden, true)) {
            throw new ValidationException([
                'file' => __('validation.file_type', ['types' => implode('، ', $allowed)]),
            ]);
        }

        if (!in_array($extension, $allowed, true)) {
            throw new ValidationException([
                'file' => __('validation.file_type', ['types' => implode('، ', $allowed)]),
            ]);
        }
    }

    /** نوع MIME الحقيقي من محتوى الملف | Real MIME type from file content. */
    private function detectMime(string $path): string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException('تعذّر فحص نوع الملف.');
        }

        $mime = @finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function assertMimeAllowed(string $mime, array $allowed, string $extension): void
    {
        if (!in_array($mime, $allowed, true)) {
            Logger::warning('Upload rejected: MIME mismatch', [
                'detected_mime' => $mime,
                'extension'     => $extension,
            ]);

            throw new ValidationException([
                'file' => __('validation.file_type', ['types' => implode('، ', $allowed)]),
            ]);
        }
    }

    /**
     * إعادة ترميز الصورة | Re-encode an image.
     *
     * يُعاد بناء الصورة من بياناتها الخام، فتُفقد أي حمولة مدسوسة في البيانات
     * الوصفية (EXIF) أو ملحقة بنهاية الملف.
     * Rebuilding the image from raw pixels discards anything hidden in EXIF or
     * appended after the image data.
     */
    private function reEncodeImage(string $source, string $destination, string $extension): void
    {
        if (!extension_loaded('gd')) {
            // بلا GD لا يمكن إعادة الترميز؛ ننقل الملف بعد اجتياز الفحوص السابقة
            // ونسجّل ذلك بوضوح بدل ادّعاء تعقيم لم يحدث.
            Logger::warning('GD unavailable — image stored without re-encoding');

            if (!@move_uploaded_file($source, $destination) && !@copy($source, $destination)) {
                throw new ValidationException(['file' => __('validation.file_upload_failed')]);
            }

            return;
        }

        $info = @getimagesize($source);

        if ($info === false) {
            throw new ValidationException(['file' => __('validation.file_corrupt')]);
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default        => false,
        };

        if ($image === false) {
            throw new ValidationException(['file' => __('validation.file_corrupt')]);
        }

        // تصغير الصور الكبيرة جداً لتوفير النطاق (§16)
        $image = $this->constrain($image, 2000);

        $saved = match ($extension) {
            'png'          => imagepng($image, $destination, 8),
            'webp'         => imagewebp($image, $destination, 85),
            default        => imagejpeg($image, $destination, 85),
        };

        imagedestroy($image);

        if ($saved === false) {
            throw new ValidationException(['file' => __('validation.file_upload_failed')]);
        }
    }

    private function constrain(\GdImage $image, int $maxDimension): \GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);

        if ($width <= $maxDimension && $height <= $maxDimension) {
            return $image;
        }

        $ratio     = min($maxDimension / $width, $maxDimension / $height);
        $newWidth  = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    // ---------------- المسارات | Paths ----------------

    /**
     * @return array{absolute:string,relative:string}
     */
    private function prepareDirectory(string $collection, ?int $organizationId): array
    {
        $root        = rtrim((string) Config::get('uploads.root'), '/');
        $directories = (array) Config::get('uploads.directories', []);
        $sub         = (string) ($directories[$collection] ?? 'misc');

        // تقسيم حسب المنشأة والشهر: يمنع تضخّم مجلد واحد ويسهّل الأرشفة
        $relative = $sub
            . '/' . ($organizationId === null ? 'platform' : 'org-' . $organizationId)
            . '/' . date('Y-m');

        $absolute = $root . '/' . $relative;

        if (!is_dir($absolute) && !@mkdir($absolute, 0750, true) && !is_dir($absolute)) {
            throw new RuntimeException('تعذّر تجهيز مجلد التخزين.');
        }

        return ['absolute' => $absolute, 'relative' => $relative];
    }

    /** اسم تخزين عشوائي | Unpredictable stored filename. */
    private function randomFilename(string $extension): string
    {
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    private function sanitiseDisplayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'file');
    }

    // ---------------- القراءة والحذف | Reading and deletion ----------------

    /** المسار المطلق لملف | Absolute path for a media row. */
    public function absolutePath(array $media): string
    {
        $root = rtrim((string) Config::get('uploads.root'), '/');
        $path = $root . '/' . ltrim((string) $media['disk_path'], '/');

        // حارس ضد اجتياز المسارات لو تلوّث السجل | Path-traversal guard
        $real     = realpath($path);
        $rootReal = realpath($root);

        if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal)) {
            throw new RuntimeException('مسار الملف غير صالح.');
        }

        return $real;
    }

    public function exists(array $media): bool
    {
        try {
            return is_file($this->absolutePath($media));
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * حذف ناعم للسجل مع إزالة الملف من القرص | Soft-delete the row, remove the file.
     * يُبقى السجل للتدقيق بينما يُحرَّر مساحة القرص.
     */
    public function delete(int $mediaId): bool
    {
        $media = Database::selectOne('SELECT * FROM media WHERE id = ? LIMIT 1', [$mediaId]);

        if ($media === null) {
            return false;
        }

        try {
            $path = $this->absolutePath($media);
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (RuntimeException $e) {
            Logger::warning('Could not remove file from disk', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
        }

        Database::statement('UPDATE media SET deleted_at = NOW() WHERE id = ?', [$mediaId]);

        return true;
    }

    // ---------------- أدوات | Helpers ----------------

    /** @return array{extensions:array<int,string>,mimes:array<int,string>,max_size:int,re_encode:bool} */
    private function rulesFor(string $typeKey): array
    {
        $types = (array) Config::get('uploads.types', []);
        $rules = $types[$typeKey] ?? null;

        if ($rules === null) {
            throw new RuntimeException("نوع رفع غير معرّف: {$typeKey}");
        }

        return [
            'extensions' => (array) ($rules['extensions'] ?? []),
            'mimes'      => (array) ($rules['mimes'] ?? []),
            'max_size'   => (int) ($rules['max_size'] ?? 2 * 1024 * 1024),
            're_encode'  => (bool) ($rules['re_encode'] ?? false),
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' ميجابايت';
        }

        return number_format($bytes / 1024, 0) . ' كيلوبايت';
    }

    private function phpUploadLimit(): int
    {
        $value = (string) ini_get('upload_max_filesize');
        $unit  = strtolower(substr($value, -1));
        $size  = (int) $value;

        return match ($unit) {
            'g'     => $size * 1024 * 1024 * 1024,
            'm'     => $size * 1024 * 1024,
            'k'     => $size * 1024,
            default => $size,
        };
    }
}
