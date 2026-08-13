<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * مستودع وثائق المنشآت | Organization documents repository.
 *
 * مقيّد بالمنشأة. الوثائق بيانات مراجعة حسّاسة (§10) ولا تظهر في أي سياق عام.
 */
final class DocumentRepository extends BaseRepository
{
    protected string $table = 'organization_documents';

    protected bool $tenantScoped = true;

    protected bool $softDeletes = true;

    protected array $sortable = ['id', 'status', 'created_at', 'expiry_date'];

    /**
     * وثائق منشأة مع أنواعها | An organization's documents with type labels.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forOrganization(int $organizationId): array
    {
        return Database::select(
            'SELECT d.*, t.code AS type_code, t.name_ar AS type_name,
                    t.requires_expiry, m.original_name, m.size_bytes,
                    m.mime_type, m.extension,
                    u.name AS uploaded_by_name, r.name AS reviewed_by_name
               FROM organization_documents d
               JOIN document_types t ON t.id = d.document_type_id
               JOIN media m ON m.id = d.media_id
               LEFT JOIN users u ON u.id = d.uploaded_by
               LEFT JOIN users r ON r.id = d.reviewed_by
              WHERE d.organization_id = ? AND d.deleted_at IS NULL
              ORDER BY t.sort_order ASC, d.created_at DESC',
            [$organizationId],
        );
    }

    /**
     * قائمة المستندات المطلوبة لنوع منشأة مع حالة كل مستند.
     * تُستخدم في قائمة تحقّق صاحب المنشأة وفي شاشة المراجعة الإدارية.
     *
     * @return array<int,array<string,mixed>>
     */
    public function checklistFor(int $organizationId, string $organizationTypeCode): array
    {
        return Database::select(
            "SELECT t.id AS document_type_id, t.code, t.name_ar, t.description_ar,
                    t.is_required, t.requires_expiry,
                    d.id AS document_id, d.status, d.review_note, d.expiry_date,
                    d.created_at AS uploaded_at,
                    m.original_name, m.size_bytes
               FROM document_types t
               LEFT JOIN organization_documents d
                      ON d.document_type_id = t.id
                     AND d.organization_id = ?
                     AND d.deleted_at IS NULL
               LEFT JOIN media m ON m.id = d.media_id
              WHERE t.is_active = 1
                AND (t.applies_to = ? OR t.applies_to = 'all')
              ORDER BY t.is_required DESC, t.sort_order ASC",
            [$organizationId, $organizationTypeCode],
        );
    }

    /**
     * هل رُفعت كل المستندات الإلزامية؟ | Are all required documents present?
     *
     * @return array{required:int,provided:int,missing:array<int,string>}
     */
    public function requiredDocumentStatus(int $organizationId, string $organizationTypeCode): array
    {
        $rows = Database::select(
            "SELECT t.name_ar,
                    (d.id IS NOT NULL AND d.status != 'rejected') AS provided
               FROM document_types t
               LEFT JOIN organization_documents d
                      ON d.document_type_id = t.id
                     AND d.organization_id = ?
                     AND d.deleted_at IS NULL
              WHERE t.is_active = 1
                AND t.is_required = 1
                AND (t.applies_to = ? OR t.applies_to = 'all')",
            [$organizationId, $organizationTypeCode],
        );

        $missing = [];
        foreach ($rows as $row) {
            if ((int) $row['provided'] === 0) {
                $missing[] = (string) $row['name_ar'];
            }
        }

        return [
            'required' => count($rows),
            'provided' => count($rows) - count($missing),
            'missing'  => $missing,
        ];
    }

    /** @return array{accepted:int,pending:int,rejected:int} */
    public function countsByStatus(int $organizationId): array
    {
        $rows = Database::select(
            'SELECT status, COUNT(*) AS total
               FROM organization_documents
              WHERE organization_id = ? AND deleted_at IS NULL
              GROUP BY status',
            [$organizationId],
        );

        $counts = ['accepted' => 0, 'pending' => 0, 'rejected' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * تسجيل قرار المراجعة على وثيقة | Record a document review decision.
     * يُقيَّد بالمنشأة صراحةً حتى لا يُراجَع مستند منشأة أخرى بتخمين المعرّف.
     */
    public function review(int $documentId, int $organizationId, string $status, ?string $note, int $reviewerId): int
    {
        return Database::affectingStatement(
            'UPDATE organization_documents
                SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL',
            [$status, $note === null ? null : mb_substr($note, 0, 1000), $reviewerId, $documentId, $organizationId],
        );
    }

    /** استبدال وثيقة سابقة من نفس النوع | Supersede an earlier document of the same type. */
    public function supersedePrevious(int $organizationId, int $documentTypeId, int $exceptDocumentId): int
    {
        return Database::affectingStatement(
            'UPDATE organization_documents
                SET deleted_at = NOW()
              WHERE organization_id = ? AND document_type_id = ?
                AND id != ? AND deleted_at IS NULL',
            [$organizationId, $documentTypeId, $exceptDocumentId],
        );
    }

    /** الوثائق المنتهية أو التي تنتهي قريباً | Expiring documents. */
    public function expiringSoon(int $organizationId, int $withinDays = 30): array
    {
        return Database::select(
            "SELECT d.*, t.name_ar AS type_name
               FROM organization_documents d
               JOIN document_types t ON t.id = d.document_type_id
              WHERE d.organization_id = ?
                AND d.deleted_at IS NULL
                AND d.expiry_date IS NOT NULL
                AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              ORDER BY d.expiry_date ASC",
            [$organizationId, $withinDays],
        );
    }
}
