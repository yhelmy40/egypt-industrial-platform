<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\CustomerRepository;

/**
 * خدمة العملاء | Customer service (§4.9, §10).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * دفتر عملاء المشروع. **لا يُجمع رقم قومي ولا بيانات حساب بنكي** — الرقم
 * الضريبي وحده يُجمع لأن الفاتورة تحتاجه، وما عداه بيانات تعريف زائدة تُحمّل
 * المشروع مسؤولية حفظها بلا مقابل (§10).
 *
 * وحدّ الائتمان هنا **تذكير لصاحب المشروع لا قاعدة تمنع**: المنصة لا تحجب
 * بيعاً ولا تصنّف جدارة، وتنبيه صاحب المشروع قرار يتخذه هو.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class CustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers = new CustomerRepository(),
    ) {
    }

    // ═══════════════════ العملاء | Customers ═══════════════════

    /** @param array<string,mixed> $data */
    public function create(int $organizationId, array $data): int
    {
        $payload = $this->validate($organizationId, $data, null);

        return Database::insert(
            'INSERT INTO crm_customers
                (organization_id, code, name_ar, customer_type, phone, email,
                 governorate_id, city_id, address, tax_number, source, status,
                 credit_limit, notes_ar)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $payload['code'],
                $payload['name_ar'],
                $payload['customer_type'],
                $payload['phone'],
                $payload['email'],
                $payload['governorate_id'],
                $payload['city_id'],
                $payload['address'],
                $payload['tax_number'],
                $payload['source'],
                $payload['status'],
                $payload['credit_limit'],
                $payload['notes_ar'],
            ],
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $customerId, int $organizationId, array $data): void
    {
        $this->requireCustomer($customerId, $organizationId);
        $payload = $this->validate($organizationId, $data, $customerId);

        Database::statement(
            'UPDATE crm_customers
                SET code = ?, name_ar = ?, customer_type = ?, phone = ?, email = ?,
                    governorate_id = ?, city_id = ?, address = ?, tax_number = ?,
                    source = ?, status = ?, credit_limit = ?, notes_ar = ?, updated_at = NOW()
              WHERE id = ? AND organization_id = ?',
            [
                $payload['code'],
                $payload['name_ar'],
                $payload['customer_type'],
                $payload['phone'],
                $payload['email'],
                $payload['governorate_id'],
                $payload['city_id'],
                $payload['address'],
                $payload['tax_number'],
                $payload['source'],
                $payload['status'],
                $payload['credit_limit'],
                $payload['notes_ar'],
                $customerId,
                $organizationId,
            ],
        );
    }

    /**
     * أرشفة عميل | Archive a customer.
     *
     * العميل الذي عليه مستحقّات لا يُؤرشَف: إخفاؤه يُخفي دَيناً قائماً من
     * تقارير المشروع دون أن يُحصَّل.
     */
    public function archive(int $customerId, int $organizationId): void
    {
        $this->requireCustomer($customerId, $organizationId);

        $outstanding = (float) Database::scalar(
            "SELECT COALESCE(SUM(total - amount_paid), 0)
               FROM erp_invoices
              WHERE customer_id = ? AND organization_id = ? AND deleted_at IS NULL
                AND status IN ('issued','partially_paid')",
            [$customerId, $organizationId],
        );

        if ($outstanding > 0) {
            throw new HttpException(
                422,
                'على هذا العميل مستحقّات لم تُسدَّد. أرشفته الآن تُخفي الدَّين من تقاريرك دون تحصيله.',
            );
        }

        Database::statement(
            "UPDATE crm_customers SET deleted_at = NOW(), status = 'inactive'
              WHERE id = ? AND organization_id = ?",
            [$customerId, $organizationId],
        );
    }

    // ═══════════════════ جهات الاتصال | Contacts ═══════════════════

    /**
     * إضافة جهة اتصال | Add a contact.
     *
     * @param array<string,mixed> $data
     */
    public function addContact(int $customerId, int $organizationId, array $data): int
    {
        $this->requireCustomer($customerId, $organizationId);

        $name = trim((string) ($data['name_ar'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'اكتب اسم جهة الاتصال.');
        }

        $isPrimary = (int) (bool) ($data['is_primary'] ?? 0);

        return Database::transaction(function () use ($customerId, $organizationId, $data, $name, $isPrimary): int {
            if ($isPrimary === 1) {
                $this->clearPrimary($customerId, $organizationId);
            }

            return Database::insert(
                'INSERT INTO crm_contacts
                    (organization_id, customer_id, name_ar, job_title_ar, phone, email,
                     is_primary, notes_ar)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $organizationId,
                    $customerId,
                    mb_substr($name, 0, 150),
                    $this->text($data['job_title_ar'] ?? null, 120),
                    $this->text($data['phone'] ?? null, 30),
                    $this->text($data['email'] ?? null, 190),
                    $isPrimary,
                    $this->text($data['notes_ar'] ?? null, 1000),
                ],
            );
        });
    }

    /** حذف جهة اتصال | Remove a contact. */
    public function removeContact(int $contactId, int $customerId, int $organizationId): void
    {
        $affected = Database::affectingStatement(
            'UPDATE crm_contacts SET deleted_at = NOW()
              WHERE id = ? AND customer_id = ? AND organization_id = ? AND deleted_at IS NULL',
            [$contactId, $customerId, $organizationId],
        );

        if ($affected === 0) {
            throw new HttpException(404, 'جهة الاتصال غير موجودة.');
        }
    }

    /** جهة اتصال رئيسية واحدة لكل عميل | One primary contact per customer. */
    private function clearPrimary(int $customerId, int $organizationId): void
    {
        Database::statement(
            'UPDATE crm_contacts SET is_primary = 0
              WHERE customer_id = ? AND organization_id = ?',
            [$customerId, $organizationId],
        );
    }

    // ═══════════════════ التحقّق | Validation ═══════════════════

    /**
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function validate(int $organizationId, array $data, ?int $exceptId): array
    {
        $name = trim((string) ($data['name_ar'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'اكتب اسم العميل.');
        }

        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            $code = $this->generateCode($organizationId);
        }

        if ($this->customers->codeExists($organizationId, $code, $exceptId)) {
            throw new HttpException(422, 'كود العميل مستخدم بالفعل. اختر كوداً آخر.');
        }

        $type = (string) ($data['customer_type'] ?? 'individual');

        if (!in_array($type, ['individual', 'company', 'government', 'ngo'], true)) {
            $type = 'individual';
        }

        $source = (string) ($data['source'] ?? 'other');

        if (!in_array($source, ['marketplace', 'referral', 'walk_in', 'social', 'event', 'other'], true)) {
            $source = 'other';
        }

        $status = (string) ($data['status'] ?? 'active');

        if (!in_array($status, ['active', 'inactive', 'blocked'], true)) {
            $status = 'active';
        }

        $email = $this->text($data['email'] ?? null, 190);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'البريد الإلكتروني غير صالح.');
        }

        return [
            'code'           => mb_substr($code, 0, 30),
            'name_ar'        => mb_substr($name, 0, 200),
            'customer_type'  => $type,
            'phone'          => $this->text($data['phone'] ?? null, 30),
            'email'          => $email,
            'governorate_id' => $this->nullableInt($data['governorate_id'] ?? null),
            'city_id'        => $this->nullableInt($data['city_id'] ?? null),
            'address'        => $this->text($data['address'] ?? null, 500),
            'tax_number'     => $this->text($data['tax_number'] ?? null, 30),
            'source'         => $source,
            'status'         => $status,
            'credit_limit'   => $this->decimal($data['credit_limit'] ?? null),
            'notes_ar'       => $this->text($data['notes_ar'] ?? null, 2000),
        ];
    }

    /** كود تلقائي حين لا يكتبه صاحب المشروع | An automatic code when none is given. */
    private function generateCode(int $organizationId): string
    {
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM crm_customers WHERE organization_id = ?',
            [$organizationId],
        );

        do {
            $count++;
            $code = 'CUS-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
        } while ($this->customers->codeExists($organizationId, $code));

        return $code;
    }

    /** @return array<string,mixed> */
    private function requireCustomer(int $customerId, int $organizationId): array
    {
        $customer = $this->customers->findOwned($customerId, $organizationId);

        if ($customer === null) {
            throw new HttpException(404, 'العميل غير موجود.');
        }

        return $customer;
    }

    private function text(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function decimal(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : round((float) $value, 2);
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'individual' => 'فرد',
            'company'    => 'شركة',
            'government' => 'جهة حكومية',
            'ngo'        => 'منظمة أهلية',
            default      => $type,
        };
    }

    public function sourceLabel(string $source): string
    {
        return match ($source) {
            'marketplace' => 'سوق المنصة',
            'referral'    => 'ترشيح',
            'walk_in'     => 'زيارة مباشرة',
            'social'      => 'وسائل التواصل',
            'event'       => 'معرض أو فعالية',
            default       => 'أخرى',
        };
    }
}
