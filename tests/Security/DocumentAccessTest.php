<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\DocumentRepository;
use App\Repositories\MediaRepository;
use App\Services\FileStorageService;
use Tests\TestCase;

/**
 * أمان المستندات والملفات | Document and upload security (§17).
 *
 * يغطي متطلّبات §17 الصريحة: «قيود رفع الملفات» و«إثبات أن منشأة لا تستطيع
 * قراءة أو تعديل سجلات منشأة أخرى» مطبَّقة على أخطر البيانات في المنصة —
 * المستندات الرسمية.
 */
final class DocumentAccessTest extends TestCase
{
    private FileStorageService $storage;

    private string $uploadRoot;

    private int $orgA;
    private int $orgB;
    private int $userA;
    private int $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // جذر تخزين مؤقت خاص بالاختبار حتى لا تُلمس ملفات التطوير
        $this->uploadRoot = sys_get_temp_dir() . '/np-test-uploads-' . bin2hex(random_bytes(4));
        @mkdir($this->uploadRoot, 0750, true);
        Config::set('uploads.root', $this->uploadRoot);

        $this->storage = new FileStorageService();

        $this->userA = $this->createUser();
        $this->userB = $this->createUser();
        $this->orgA  = $this->createOrganization(['owner_user_id' => $this->userA]);
        $this->orgB  = $this->createOrganization(['owner_user_id' => $this->userB]);

        $this->addMember($this->orgA, $this->userA, 'sme_owner');
        $this->addMember($this->orgB, $this->userB, 'sme_owner');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }

    /** ملف مرفوع اصطناعي | Build a synthetic uploaded file. */
    private function fakeUpload(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'np-upload');
        file_put_contents($tmp, $contents);

        return [
            'name'     => $name,
            'tmp_name' => $tmp,
            'size'     => strlen($contents),
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'application/octet-stream', // نوع المتصفح — لا يُوثق به
        ];
    }

    private function validPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function validPng(): string
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    // ═════════════════ قيود الرفع | Upload restrictions ═════════════════

    public function test_a_valid_pdf_is_accepted_and_registered(): void
    {
        $mediaId = $this->storage->store(
            $this->fakeUpload('السجل التجاري.pdf', $this->validPdf()),
            'document', 'documents', $this->orgA, $this->userA,
        );

        $media = Database::selectOne('SELECT * FROM media WHERE id = ?', [$mediaId]);

        $this->assertNotNull($media);
        $this->assertSame('application/pdf', $media['mime_type'], 'يجب تسجيل النوع الحقيقي من finfo.');
        $this->assertSame($this->orgA, (int) $media['organization_id']);
        $this->assertNotSame('', $media['checksum']);
    }

    public function test_php_files_are_rejected_even_when_the_browser_claims_pdf(): void
    {
        $file         = $this->fakeUpload('shell.php', '<?php system($_GET["c"]); ?>');
        $file['type'] = 'application/pdf'; // ادّعاء المتصفح

        $this->expectException(ValidationException::class);

        $this->storage->store($file, 'document', 'documents', $this->orgA, $this->userA);
    }

    public function test_double_extension_does_not_bypass_the_extension_check(): void
    {
        // اسم مثل report.pdf.php امتداده الفعلي php ويجب رفضه
        $this->expectException(ValidationException::class);

        $this->storage->store(
            $this->fakeUpload('report.pdf.php', '<?php echo 1; ?>'),
            'document', 'documents', $this->orgA, $this->userA,
        );
    }

    public function test_a_renamed_php_file_is_rejected_by_the_real_mime_check(): void
    {
        // الامتداد pdf لكن المحتوى نص برمجي ⇒ finfo يكشفه
        $this->expectException(ValidationException::class);

        $this->storage->store(
            $this->fakeUpload('innocent.pdf', '<?php system("id"); ?>'),
            'document', 'documents', $this->orgA, $this->userA,
        );
    }

    public function test_svg_is_rejected_as_an_image(): void
    {
        // SVG يسمح بجافاسكربت مضمّن، لذا هو خارج قائمة السماح للصور
        $this->expectException(ValidationException::class);

        $this->storage->store(
            $this->fakeUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'image', 'logos', $this->orgA, $this->userA,
        );
    }

    public function test_oversized_files_are_rejected(): void
    {
        $file         = $this->fakeUpload('big.pdf', $this->validPdf());
        $file['size'] = 999_000_000;

        $this->expectException(ValidationException::class);

        $this->storage->store($file, 'document', 'documents', $this->orgA, $this->userA);
    }

    public function test_empty_files_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->storage->store(
            $this->fakeUpload('empty.pdf', ''),
            'document', 'documents', $this->orgA, $this->userA,
        );
    }

    public function test_stored_filenames_are_randomised_and_unrelated_to_the_original(): void
    {
        $mediaId = $this->storage->store(
            $this->fakeUpload('بطاقة ضريبية سرية.pdf', $this->validPdf()),
            'document', 'documents', $this->orgA, $this->userA,
        );

        $media = Database::selectOne('SELECT * FROM media WHERE id = ?', [$mediaId]);

        $this->assertStringNotContainsString('بطاقة', (string) $media['disk_path']);
        $this->assertMatchesRegularExpression('#/[0-9a-f]{32}\.pdf$#', (string) $media['disk_path']);
        // الاسم الأصلي يبقى للعرض فقط
        $this->assertSame('بطاقة ضريبية سرية.pdf', $media['original_name']);
    }

    public function test_uploads_are_stored_outside_the_public_web_root(): void
    {
        $mediaId = $this->storage->store(
            $this->fakeUpload('doc.pdf', $this->validPdf()),
            'document', 'documents', $this->orgA, $this->userA,
        );

        $media = Database::selectOne('SELECT * FROM media WHERE id = ?', [$mediaId]);
        $path  = $this->storage->absolutePath($media);

        $this->assertFileExists($path);
        $this->assertStringNotContainsString(
            (string) Config::get('app.public_path'),
            $path,
            'الملفات المرفوعة يجب ألّا تقع داخل جذر الويب.',
        );
    }

    public function test_images_are_re_encoded_to_strip_embedded_payloads(): void
    {
        // صورة PNG صالحة مع حمولة ملحقة بنهاية الملف
        $payload  = $this->validPng() . '<?php system($_GET["c"]); ?>';
        $mediaId  = $this->storage->store(
            $this->fakeUpload('logo.png', $payload),
            'image', 'logos', $this->orgA, $this->userA, 'public',
        );

        $media    = Database::selectOne('SELECT * FROM media WHERE id = ?', [$mediaId]);
        $contents = (string) file_get_contents($this->storage->absolutePath($media));

        $this->assertStringNotContainsString(
            '<?php',
            $contents,
            'إعادة الترميز يجب أن تزيل أي حمولة ملحقة بالصورة.',
        );
    }

    public function test_path_traversal_in_a_stored_path_is_refused(): void
    {
        $mediaId = Database::insert(
            'INSERT INTO media
                (organization_id, uploaded_by, disk_path, original_name, extension,
                 mime_type, size_bytes, checksum, visibility, collection)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->orgA, $this->userA, '../../../../etc/passwd', 'passwd', 'txt',
                'text/plain', 10, str_repeat('b', 64), 'private', 'documents',
            ],
        );

        $media = Database::selectOne('SELECT * FROM media WHERE id = ?', [$mediaId]);

        $this->expectException(\RuntimeException::class);
        $this->storage->absolutePath($media);
    }

    // ═════════════════ عزل المستندات | Document isolation ═════════════════

    public function test_one_organization_cannot_read_another_organizations_documents(): void
    {
        $documentB = $this->createDocumentFor($this->orgB);

        $documents = new DocumentRepository();

        $this->assertNotNull($documents->scopedToTenant($this->orgB)->find($documentB));
        $this->assertNull(
            $documents->scopedToTenant($this->orgA)->find($documentB),
            'المنشأة أ يجب ألّا ترى مستند المنشأة ب.',
        );
    }

    public function test_document_listing_never_leaks_across_organizations(): void
    {
        $this->createDocumentFor($this->orgA);
        $this->createDocumentFor($this->orgB);
        $this->createDocumentFor($this->orgB);

        $documents = new DocumentRepository();

        $this->assertCount(1, $documents->forOrganization($this->orgA));
        $this->assertCount(2, $documents->forOrganization($this->orgB));
    }

    public function test_deleting_another_organizations_document_affects_nothing(): void
    {
        $documentB = $this->createDocumentFor($this->orgB);
        $documents = new DocumentRepository();

        $this->assertSame(0, $documents->scopedToTenant($this->orgA)->delete($documentB));
        $this->assertNotNull($documents->scopedToTenant($this->orgB)->find($documentB));
    }

    public function test_reviewing_a_document_is_bound_to_its_organization(): void
    {
        $documentB = $this->createDocumentFor($this->orgB);
        $documents = new DocumentRepository();

        // مراجع يمرّر معرّف مستند صحيحاً لكن منشأة خاطئة ⇒ لا صف يتأثر
        $affected = $documents->review($documentB, $this->orgA, 'accepted', null, $this->userA);

        $this->assertSame(0, $affected);
        $this->assertDatabaseHas('organization_documents', ['id' => $documentB, 'status' => 'pending']);
    }

    public function test_media_records_are_tenant_scoped(): void
    {
        $mediaA = $this->storage->store(
            $this->fakeUpload('a.pdf', $this->validPdf()),
            'document', 'documents', $this->orgA, $this->userA,
        );

        $media = new MediaRepository();

        $this->assertNotNull($media->scopedToTenant($this->orgA)->find($mediaA));
        $this->assertNull(
            $media->scopedToTenant($this->orgB)->find($mediaA),
            'سجل الوسائط يجب أن يكون مقيّداً بالمنشأة المالكة.',
        );
    }

    public function test_private_media_is_not_returned_by_the_public_lookup(): void
    {
        $privateId = $this->storage->store(
            $this->fakeUpload('secret.pdf', $this->validPdf()),
            'document', 'documents', $this->orgA, $this->userA, 'private',
        );

        $publicId = $this->storage->store(
            $this->fakeUpload('logo.png', $this->validPng()),
            'image', 'logos', $this->orgA, $this->userA, 'public',
        );

        $media = new MediaRepository();

        $this->assertNull(
            $media->findPublic($privateId),
            'الملف الخاص يجب ألّا يُقدَّم عبر مسار الملفات العامة إطلاقاً.',
        );
        $this->assertNotNull($media->findPublic($publicId));
    }

    public function test_required_document_status_is_computed_per_organization(): void
    {
        $documents = new DocumentRepository();

        $before = $documents->requiredDocumentStatus($this->orgA, 'sme');
        $this->assertGreaterThan(0, $before['required']);
        $this->assertNotSame([], $before['missing']);

        // رفع مستندات المنشأة ب لا يؤثر على حالة المنشأة أ
        foreach ($this->requiredTypeIds('sme') as $typeId) {
            $this->createDocumentFor($this->orgB, $typeId);
        }

        $after = $documents->requiredDocumentStatus($this->orgA, 'sme');

        $this->assertSame(
            $before['missing'],
            $after['missing'],
            'مستندات منشأة أخرى يجب ألّا تُحتسب ضمن متطلبات هذه المنشأة.',
        );
        $this->assertSame([], $documents->requiredDocumentStatus($this->orgB, 'sme')['missing']);
    }

    // ═════════════════ أدوات | Helpers ═════════════════

    /** @return array<int,int> */
    private function requiredTypeIds(string $organizationTypeCode): array
    {
        $rows = Database::select(
            "SELECT id FROM document_types
              WHERE is_required = 1 AND is_active = 1 AND (applies_to = ? OR applies_to = 'all')",
            [$organizationTypeCode],
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    private function createDocumentFor(int $organizationId, ?int $documentTypeId = null): int
    {
        $documentTypeId ??= (int) Database::scalar('SELECT id FROM document_types ORDER BY id LIMIT 1');

        $mediaId = Database::insert(
            'INSERT INTO media
                (organization_id, uploaded_by, disk_path, original_name, extension,
                 mime_type, size_bytes, checksum, visibility, collection)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId, null, 'documents/test/' . bin2hex(random_bytes(8)) . '.pdf',
                'مستند.pdf', 'pdf', 'application/pdf', 512, str_repeat('c', 64), 'private', 'documents',
            ],
        );

        return Database::insert(
            'INSERT INTO organization_documents
                (organization_id, document_type_id, media_id, status)
             VALUES (?, ?, ?, ?)',
            [$organizationId, $documentTypeId, $mediaId, 'pending'],
        );
    }
}
