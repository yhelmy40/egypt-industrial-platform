<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Support\DocumentNumber;

/**
 * خدمة المصروفات | Expense service (§4.10, §14).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * **على الأساس النقدي:** يُسجَّل ما خرج فعلاً بتاريخ خروجه. لا استحقاق ولا
 * إهلاك ولا توزيع على فترات — كلها من عمل نظام محاسبي كامل، وهو خارج نطاق
 * النسخة صراحةً (§14).
 *
 * وبند «الأجور» هنا **مصروف يُدوَّن لا نظام أجور**: لا حساب ضرائب ولا تأمينات
 * ولا كشوف مرتّبات. صاحب المشروع يكتب ما دفعه، والمنصة تجمعه في تقرير.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ExpenseService
{
    private const CATEGORIES = [
        'materials', 'rent', 'utilities', 'salaries', 'transport',
        'marketing', 'maintenance', 'fees', 'taxes', 'other',
    ];

    private const METHODS = ['cash', 'bank_transfer', 'cheque', 'wallet', 'other'];

    /**
     * @param  array<string,mixed> $data
     * @return array{id:int,number:string}
     */
    public function create(int $organizationId, array $data, ?int $actorUserId): array
    {
        $payload = $this->validate($organizationId, $data);

        return Database::transaction(fn (): array => DocumentNumber::withNumber(
            'erp_expenses',
            'expense_number',
            $organizationId,
            'EXP',
            fn (string $number): int => Database::insert(
                'INSERT INTO erp_expenses
                    (organization_id, expense_number, category, description_ar, amount,
                     spent_at, method, supplier_id, notes_ar, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $organizationId,
                    $number,
                    $payload['category'],
                    $payload['description_ar'],
                    $payload['amount'],
                    $payload['spent_at'],
                    $payload['method'],
                    $payload['supplier_id'],
                    $payload['notes_ar'],
                    $actorUserId,
                ],
            ),
        ));
    }

    /** @param array<string,mixed> $data */
    public function update(int $expenseId, int $organizationId, array $data): void
    {
        $this->requireExpense($expenseId, $organizationId);
        $payload = $this->validate($organizationId, $data);

        Database::statement(
            'UPDATE erp_expenses
                SET category = ?, description_ar = ?, amount = ?, spent_at = ?,
                    method = ?, supplier_id = ?, notes_ar = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $payload['category'],
                $payload['description_ar'],
                $payload['amount'],
                $payload['spent_at'],
                $payload['method'],
                $payload['supplier_id'],
                $payload['notes_ar'],
                $expenseId,
                $organizationId,
            ],
        );
    }

    /** حذف مصروف | Delete an expense. */
    public function delete(int $expenseId, int $organizationId): void
    {
        $this->requireExpense($expenseId, $organizationId);

        Database::statement(
            'UPDATE erp_expenses SET deleted_at = NOW() WHERE id = ? AND organization_id = ?',
            [$expenseId, $organizationId],
        );
    }

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function validate(int $organizationId, array $data): array
    {
        $description = trim((string) ($data['description_ar'] ?? ''));

        if ($description === '') {
            throw new HttpException(422, 'اكتب وصف المصروف.');
        }

        $amount = $data['amount'] ?? null;

        if ($amount === null || !is_numeric($amount) || (float) $amount <= 0) {
            throw new HttpException(422, 'اكتب مبلغ المصروف.');
        }

        $category = (string) ($data['category'] ?? 'other');

        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'other';
        }

        $method = (string) ($data['method'] ?? 'cash');

        if (!in_array($method, self::METHODS, true)) {
            $method = 'cash';
        }

        $spentAt = trim((string) ($data['spent_at'] ?? ''));
        $spentAt = $spentAt === '' ? date('Y-m-d') : date('Y-m-d', (int) (strtotime($spentAt) ?: time()));

        // مصروف بتاريخ مستقبلي لم يخرج بعد، وتسجيله يُفسد أي تقرير نقدي
        if ($spentAt > date('Y-m-d')) {
            throw new HttpException(
                422,
                'تاريخ الصرف في المستقبل. سجّل المصروف بعد خروجه فعلاً.',
            );
        }

        return [
            'category'       => $category,
            'description_ar' => mb_substr($description, 0, 500),
            'amount'         => round((float) $amount, 2),
            'spent_at'       => $spentAt,
            'method'         => $method,
            'supplier_id'    => $this->resolveSupplier($organizationId, $data['supplier_id'] ?? null),
            'notes_ar'       => $this->text($data['notes_ar'] ?? null, 1000),
        ];
    }

    private function resolveSupplier(int $organizationId, mixed $supplierId): ?int
    {
        $supplierId = $supplierId === null || $supplierId === '' || !is_numeric($supplierId)
            ? null
            : (int) $supplierId;

        if ($supplierId === null) {
            return null;
        }

        $owns = Database::scalar(
            'SELECT 1 FROM erp_suppliers
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$supplierId, $organizationId],
        );

        if ($owns === null) {
            throw new HttpException(404, 'المورّد غير موجود في مشروعك.');
        }

        return $supplierId;
    }

    /** @return array<string,mixed> */
    private function requireExpense(int $expenseId, int $organizationId): array
    {
        $expense = Database::selectOne(
            'SELECT * FROM erp_expenses
              WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$expenseId, $organizationId],
        );

        if ($expense === null) {
            throw new HttpException(404, 'المصروف غير موجود.');
        }

        return $expense;
    }

    private function text(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    public function categoryLabel(string $category): string
    {
        return match ($category) {
            'materials'   => 'خامات ومستلزمات',
            'rent'        => 'إيجار',
            'utilities'   => 'مرافق (كهرباء ومياه واتصالات)',
            'salaries'    => 'أجور',
            'transport'   => 'نقل وشحن',
            'marketing'   => 'تسويق',
            'maintenance' => 'صيانة',
            'fees'        => 'رسوم وخدمات',
            'taxes'       => 'ضرائب',
            default       => 'أخرى',
        };
    }

    public function methodLabel(string $method): string
    {
        return match ($method) {
            'cash'          => 'نقداً',
            'bank_transfer' => 'تحويل بنكي',
            'cheque'        => 'شيك',
            'wallet'        => 'محفظة إلكترونية',
            default         => 'أخرى',
        };
    }

    /** @return array<int,string> */
    public function categories(): array
    {
        return self::CATEGORIES;
    }

    /** @return array<int,string> */
    public function methods(): array
    {
        return self::METHODS;
    }
}
