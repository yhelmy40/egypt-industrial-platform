<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Validation\Validator;
use Tests\TestCase;

/**
 * اختبارات التحقق من المدخلات | Input validation tests (§17).
 */
final class ValidatorTest extends TestCase
{
    public function test_required_fields_are_enforced(): void
    {
        $validator = Validator::make(['name' => '', 'other' => 'قيمة'])
            ->labels(['name' => 'الاسم'])
            ->required('name');

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('الاسم', (string) $validator->firstError());
    }

    public function test_whitespace_only_values_count_as_empty(): void
    {
        $validator = Validator::make(['name' => "   \t  "])->required('name');

        $this->assertTrue($validator->fails(), 'المسافات وحدها لا تُعد قيمة صالحة.');
    }

    public function test_zero_is_a_valid_value_and_not_treated_as_empty(): void
    {
        $validator = Validator::make(['quantity' => '0'])->required('quantity')->numeric('quantity');

        $this->assertTrue($validator->passes(), 'الصفر قيمة مشروعة ويجب ألّا يُعامل كحقل فارغ.');
    }

    public function test_email_validation(): void
    {
        $this->assertTrue(Validator::make(['email' => 'user@example.com'])->email('email')->passes());
        $this->assertTrue(Validator::make(['email' => 'not-an-email'])->email('email')->fails());
        $this->assertTrue(Validator::make(['email' => 'user@'])->email('email')->fails());
    }

    public function test_egyptian_phone_numbers_are_accepted_in_common_formats(): void
    {
        foreach (['01012345678', '01112345678', '01212345678', '01512345678', '+201012345678', '0221234567'] as $phone) {
            $this->assertTrue(
                Validator::make(['phone' => $phone])->phone('phone')->passes(),
                "الرقم {$phone} يجب أن يُقبل.",
            );
        }

        foreach (['abc', '123', '0101234567890123456'] as $phone) {
            $this->assertTrue(
                Validator::make(['phone' => $phone])->phone('phone')->fails(),
                "الرقم {$phone} يجب أن يُرفض.",
            );
        }
    }

    public function test_url_validation_rejects_dangerous_schemes(): void
    {
        $this->assertTrue(Validator::make(['website' => 'https://example.com'])->url('website')->passes());
        $this->assertTrue(Validator::make(['website' => 'http://example.com/path'])->url('website')->passes());

        // javascript: و data: نواقل XSS شائعة عبر حقول الروابط
        foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'file:///etc/passwd'] as $url) {
            $this->assertTrue(
                Validator::make(['website' => $url])->url('website')->fails(),
                "الرابط {$url} يجب أن يُرفض.",
            );
        }
    }

    public function test_length_rules_count_arabic_characters_correctly(): void
    {
        // "مرحبا" خمسة أحرف عربية، وطولها بالبايت أكبر — يجب استخدام mb_strlen
        $validator = Validator::make(['name' => 'مرحبا'])->minLength('name', 5)->maxLength('name', 5);

        $this->assertTrue($validator->passes(), 'يجب حساب طول النص العربي بالأحرف لا بالبايت.');

        $this->assertTrue(Validator::make(['name' => 'مرحبا'])->minLength('name', 6)->fails());
    }

    public function test_password_rules_enforce_length_and_complexity(): void
    {
        $this->assertTrue(Validator::make(['password' => 'StrongPass1'])->password('password')->passes());

        $this->assertTrue(Validator::make(['password' => 'Short1A'])->password('password')->fails(), 'قصيرة جداً');
        $this->assertTrue(Validator::make(['password' => 'alllowercase1'])->password('password')->fails(), 'بلا حرف كبير');
        $this->assertTrue(Validator::make(['password' => 'ALLUPPERCASE1'])->password('password')->fails(), 'بلا حرف صغير');
        $this->assertTrue(Validator::make(['password' => 'NoDigitsHere'])->password('password')->fails(), 'بلا رقم');
    }

    public function test_common_and_demo_passwords_are_blocked(): void
    {
        // متطلّب §15: كلمات مرور العرض التجريبي يجب ألّا تُقبل في الإنتاج
        foreach (['password', '12345678', 'Admin@123', 'P@ssw0rd', 'nilepreneurs'] as $password) {
            $this->assertTrue(
                Validator::make(['password' => $password])->password('password')->fails(),
                "كلمة المرور {$password} يجب أن تُرفض.",
            );
        }
    }

    public function test_confirmation_must_match(): void
    {
        $matching = Validator::make(['password' => 'StrongPass1', 'password_confirmation' => 'StrongPass1'])
            ->matches('password_confirmation', 'password');
        $this->assertTrue($matching->passes());

        $mismatched = Validator::make(['password' => 'StrongPass1', 'password_confirmation' => 'Different1'])
            ->matches('password_confirmation', 'password');
        $this->assertTrue($mismatched->fails());
    }

    public function test_terms_acceptance_is_enforced(): void
    {
        $this->assertTrue(Validator::make(['accept_terms' => '1'])->accepted('accept_terms')->passes());
        $this->assertTrue(Validator::make(['accept_terms' => 'on'])->accepted('accept_terms')->passes());
        $this->assertTrue(Validator::make([])->accepted('accept_terms')->fails());
        $this->assertTrue(Validator::make(['accept_terms' => '0'])->accepted('accept_terms')->fails());
    }

    public function test_choice_values_are_restricted_to_an_allow_list(): void
    {
        $allowed = ['draft', 'submitted', 'verified'];

        $this->assertTrue(Validator::make(['status' => 'verified'])->in('status', $allowed)->passes());
        $this->assertTrue(Validator::make(['status' => 'approved_by_me'])->in('status', $allowed)->fails());
    }

    public function test_only_the_first_error_per_field_is_reported(): void
    {
        $validator = Validator::make(['email' => ''])
            ->labels(['email' => 'البريد'])
            ->required('email')
            ->email('email')
            ->minLength('email', 5);

        $this->assertCount(1, $validator->errors(), 'يجب عرض خطأ واحد لكل حقل لتجنّب إرباك المستخدم.');
    }

    public function test_validate_throws_a_validation_exception_carrying_the_errors(): void
    {
        $this->expectException(ValidationException::class);

        Validator::make(['name' => ''])->required('name')->validate();
    }

    public function test_validate_returns_only_the_requested_fields(): void
    {
        $data = Validator::make([
            'name'    => '  شركة النور  ',
            'email'   => 'info@example.com',
            'unwanted' => 'قيمة يجب ألّا تمرّ',
        ])->required('name')->validate(['name', 'email']);

        $this->assertSame(['name' => 'شركة النور', 'email' => 'info@example.com'], $data);
        $this->assertArrayNotHasKey('unwanted', $data, 'الحقول غير المطلوبة يجب ألّا تصل إلى طبقة الخدمة.');
    }

    public function test_optional_fields_are_skipped_when_empty(): void
    {
        // القاعدة لا تُطبَّق على حقل فارغ اختياري — فقط عند وجود قيمة
        $this->assertTrue(Validator::make(['website' => ''])->url('website')->passes());
        $this->assertTrue(Validator::make(['phone' => ''])->phone('phone')->passes());
    }
}
