<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MailerInterface;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\Translator;
use App\Repositories\MembershipRepository;
use App\Repositories\UserRepository;
use App\Support\Csrf;

/**
 * خدمة المصادقة | Authentication service (§9).
 *
 * تجمع منطق التسجيل والدخول والتحقق واستعادة كلمة المرور في مكان واحد،
 * بعيداً عن المتحكّمات، حتى تكون قابلة للاختبار مباشرة.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly MembershipRepository $memberships = new MembershipRepository(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?MailerInterface $mailer = null,
    ) {
    }

    // ---------------- التسجيل | Registration ----------------

    /**
     * إنشاء حساب | Create an account.
     *
     * الحساب يبدأ بحالة pending حتى يتم تأكيد البريد.
     *
     * @return array{user_id:int,verification_token:string}
     */
    public function register(string $name, string $email, string $password, ?string $phone, Request $request): array
    {
        return Database::transaction(function () use ($name, $email, $password, $phone, $request): array {
            $userId = $this->users->createUser([
                'name'          => mb_substr(trim($name), 0, 150),
                'email'         => mb_strtolower(trim($email)),
                'phone'         => $phone === null || $phone === '' ? null : trim($phone),
                'password_hash' => $this->hashPassword($password),
                'status'        => 'pending',
                'locale'        => Config::get('app.locale', 'ar'),
                'timezone'      => Config::get('app.timezone', 'Africa/Cairo'),
                'password_changed_at' => date('Y-m-d H:i:s'),
            ]);

            $token = $this->issueVerificationToken($userId, mb_strtolower(trim($email)));

            $this->audit->setRequest($request);
            $this->audit->logRegistration($userId, 'user');

            return ['user_id' => $userId, 'verification_token' => $token];
        });
    }

    /** تسجيل موافقة المستخدم على السياسات | Record a policy consent (§10). */
    public function recordConsent(int $userId, string $type, Request $request): void
    {
        $version = (string) SettingsService::get('general', 'policy_version', '1.0');
        $packed  = @inet_pton($request->ip());

        Database::statement(
            'INSERT INTO user_consents
                (user_id, consent_type, policy_version, granted, ip_address, user_agent)
             VALUES (?, ?, ?, 1, ?, ?)',
            [$userId, $type, $version, $packed === false ? null : $packed, $request->userAgent()],
        );
    }

    // ---------------- الدخول | Login ----------------

    /**
     * محاولة تسجيل الدخول | Attempt a login.
     *
     * @return array{success:bool,user?:array<string,mixed>,message:string,reason?:string}
     */
    public function attemptLogin(string $email, string $password, Request $request): array
    {
        $email     = mb_strtolower(trim($email));
        $ip        = $request->ip();
        $signature = $ip . '|' . $email;

        $this->audit->setRequest($request);

        // 1) حدّ المعدّل قبل أي عمل آخر | Rate limit first
        if ($this->limiter->tooManyAttempts('login', $signature)) {
            $wait = $this->limiter->availableIn('login', $signature);
            $this->recordAttempt($email, $ip, $request, false, null, 'rate_limited');

            return [
                'success' => false,
                'message' => Translator::get('auth.too_many_attempts') . ' ' . $this->limiter->waitMessage($wait),
                'reason'  => 'rate_limited',
            ];
        }

        $this->limiter->hit('login', $signature);

        $user = $this->users->findByEmail($email);

        // 2) التحقق من كلمة المرور | Verify the password
        //    يُنفَّذ التجزئة دائماً حتى مع مستخدم غير موجود، حتى لا يكشف زمن
        //    الاستجابة أي البريدين مسجّل (توقيت ثابت تقريبياً).
        //    A hash is always computed, even for a missing user, so response
        //    time does not reveal which emails exist.
        $hash  = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $valid = password_verify($password, $hash);

        if ($user === null || !$valid) {
            if ($user !== null) {
                $this->users->recordFailedLogin(
                    (int) $user['id'],
                    (int) Config::get('security.rate_limit.rules.login.attempts', 5),
                    (int) Config::get('security.rate_limit.rules.login.minutes', 15),
                );
            }

            $this->recordAttempt($email, $ip, $request, false, $user['id'] ?? null, 'invalid_credentials');
            $this->audit->logFailedLogin($email, 'invalid_credentials');

            // رسالة موحّدة لا تكشف أي الحقلين خاطئ
            return [
                'success' => false,
                'message' => Translator::get('auth.invalid_credentials'),
                'reason'  => 'invalid_credentials',
            ];
        }

        // 3) فحوص حالة الحساب | Account state checks
        $stateError = $this->checkAccountState($user);
        if ($stateError !== null) {
            $this->recordAttempt($email, $ip, $request, false, (int) $user['id'], $stateError['reason']);
            $this->audit->logFailedLogin($email, $stateError['reason']);

            return ['success' => false, 'message' => $stateError['message'], 'reason' => $stateError['reason']];
        }

        // 4) نجاح | Success
        $this->login($user, $request);
        $this->limiter->clear('login', $signature);
        $this->recordAttempt($email, $ip, $request, true, (int) $user['id'], null);

        return [
            'success' => true,
            'user'    => $user,
            'message' => Translator::get('auth.login_success'),
        ];
    }

    /** @return array{reason:string,message:string}|null */
    private function checkAccountState(array $user): ?array
    {
        if ($this->users->isLocked($user)) {
            return [
                'reason'  => 'locked',
                'message' => Translator::get('auth.account_locked'),
            ];
        }

        if ($user['status'] === 'suspended') {
            return [
                'reason'  => 'suspended',
                'message' => Translator::get('auth.account_suspended'),
            ];
        }

        if ($user['status'] === 'deactivated') {
            return [
                'reason'  => 'deactivated',
                'message' => Translator::get('auth.account_deactivated'),
            ];
        }

        if (
            Config::get('security.verification.require_email_verified', true) === true
            && $user['email_verified_at'] === null
        ) {
            return [
                'reason'  => 'unverified',
                'message' => Translator::get('auth.email_not_verified'),
            ];
        }

        return null;
    }

    /**
     * إنشاء الجلسة بعد التحقق | Establish the session after verification.
     *
     * تجديد معرّف الجلسة وتدوير رمز CSRF إلزاميان هنا لمنع تثبيت الجلسة.
     */
    public function login(array $user, Request $request): void
    {
        Session::regenerate(true);
        Csrf::rotate();

        $userId = (int) $user['id'];

        Session::put('user_id', $userId);
        Session::put('user_name', $user['name']);
        Session::put('user_email', $user['email']);
        Session::put('_created_at', time());
        Session::put('_last_activity', time());

        // اختيار المنشأة النشطة من العضويات المُتحقَّق منها فقط (§7)
        $organizationId = $user['last_organization_id'] !== null
            && $this->memberships->findActiveMembership($userId, (int) $user['last_organization_id']) !== null
                ? (int) $user['last_organization_id']
                : $this->memberships->defaultOrganizationId($userId);

        if ($organizationId !== null) {
            Session::put('active_organization_id', $organizationId);
        }

        $this->users->recordSuccessfulLogin($userId, $request->ip());

        $this->audit->setRequest($request);
        $this->audit->logLogin($userId, (string) $user['email']);
    }

    public function logout(Request $request): void
    {
        $userId = Session::get('user_id');

        if (is_int($userId)) {
            $this->audit->setRequest($request);
            $this->audit->logLogout($userId);
        }

        Session::invalidate();
    }

    private function recordAttempt(
        string $email,
        string $ip,
        Request $request,
        bool $successful,
        ?int $userId,
        ?string $reason,
    ): void {
        $packed = @inet_pton($ip);

        Database::statement(
            'INSERT INTO login_attempts
                (identifier, ip_address, user_agent, successful, user_id, failure_reason)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                mb_substr($email, 0, 190),
                $packed === false ? inet_pton('0.0.0.0') : $packed,
                $request->userAgent(),
                $successful ? 1 : 0,
                $userId,
                $reason,
            ],
        );
    }

    // ---------------- التحقق من البريد | Email verification ----------------

    /** @return string الرمز الصريح المُرسل للمستخدم (لا يُخزَّن) */
    public function issueVerificationToken(int $userId, string $destination, string $channel = 'email'): string
    {
        $token    = bin2hex(random_bytes(32));
        $lifetime = (int) Config::get('security.verification.token_lifetime_minutes', 1440);

        Database::statement(
            'INSERT INTO verification_tokens
                (user_id, channel, destination, token_hash, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [$userId, $channel, $destination, hash('sha256', $token), $lifetime],
        );

        return $token;
    }

    /** @return array{success:bool,message:string,user_id?:int} */
    public function verifyEmail(string $token): array
    {
        $row = Database::selectOne(
            "SELECT * FROM verification_tokens
              WHERE token_hash = ? AND channel = 'email' AND verified_at IS NULL
              LIMIT 1",
            [hash('sha256', $token)],
        );

        if ($row === null) {
            return ['success' => false, 'message' => Translator::get('auth.verification_invalid')];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return ['success' => false, 'message' => Translator::get('auth.verification_expired')];
        }

        $userId = (int) $row['user_id'];

        Database::transaction(function () use ($row, $userId): void {
            Database::statement(
                'UPDATE verification_tokens SET verified_at = NOW() WHERE id = ?',
                [(int) $row['id']],
            );

            $this->users->markEmailVerified($userId);
        });

        return [
            'success' => true,
            'message' => Translator::get('auth.verification_success'),
            'user_id' => $userId,
        ];
    }

    // ---------------- استعادة كلمة المرور | Password reset ----------------

    /**
     * طلب استعادة | Request a reset link.
     *
     * تُعاد نفس الرسالة سواء وُجد البريد أم لا، حتى لا يصبح النموذج أداة
     * لاستكشاف الحسابات المسجّلة.
     * The same message is returned whether or not the email exists, so the form
     * cannot be used to enumerate registered accounts.
     */
    public function requestPasswordReset(string $email, Request $request): array
    {
        $email = mb_strtolower(trim($email));
        $user  = $this->users->findByEmail($email);

        if ($user !== null && $user['status'] !== 'suspended') {
            $token    = bin2hex(random_bytes(32));
            $lifetime = (int) Config::get('security.verification.reset_token_lifetime', 60);
            $packed   = @inet_pton($request->ip());

            Database::statement(
                'INSERT INTO password_resets (user_id, token_hash, requested_ip, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
                [
                    (int) $user['id'],
                    hash('sha256', $token),
                    $packed === false ? null : $packed,
                    $lifetime,
                ],
            );

            $this->audit->setRequest($request);
            $this->audit->logPasswordReset((int) $user['id'], 'requested');

            $this->sendResetEmail((string) $user['email'], (string) $user['name'], $token);
        }

        return ['success' => true, 'message' => Translator::get('auth.reset_link_sent')];
    }

    private function sendResetEmail(string $email, string $name, string $token): void
    {
        if ($this->mailer === null) {
            return;
        }

        $link = rtrim((string) Config::get('app.url'), '/')
            . url('/auth/reset-password') . '?token=' . $token;

        $this->mailer->send(
            $email,
            $name,
            'استعادة كلمة المرور — ' . Config::get('app.name'),
            '<p>مرحباً ' . e($name) . '،</p>'
            . '<p>تلقّينا طلباً لاستعادة كلمة مرور حسابك. اضغط الرابط التالي لتعيين كلمة مرور جديدة:</p>'
            . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
            . '<p>الرابط صالح لمدة ساعة واحدة. إذا لم تطلب ذلك، تجاهل هذه الرسالة.</p>',
            "مرحباً {$name}،\n\nلاستعادة كلمة المرور افتح الرابط:\n{$link}\n\nالرابط صالح لمدة ساعة واحدة.",
        );
    }

    /** @return array{success:bool,message:string} */
    public function resetPassword(string $token, string $newPassword, Request $request): array
    {
        $row = Database::selectOne(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL LIMIT 1',
            [hash('sha256', $token)],
        );

        if ($row === null) {
            return ['success' => false, 'message' => Translator::get('auth.reset_invalid')];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return ['success' => false, 'message' => Translator::get('auth.reset_expired')];
        }

        $userId = (int) $row['user_id'];

        Database::transaction(function () use ($row, $userId, $newPassword): void {
            $this->users->updatePassword($userId, $this->hashPassword($newPassword));

            Database::statement(
                'UPDATE password_resets SET used_at = NOW() WHERE id = ?',
                [(int) $row['id']],
            );

            // إبطال أي روابط استعادة أخرى معلّقة لنفس المستخدم
            Database::statement(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [$userId],
            );
        });

        $this->audit->setRequest($request);
        $this->audit->logPasswordReset($userId, 'completed');

        return ['success' => true, 'message' => Translator::get('auth.reset_success')];
    }

    /** تغيير كلمة المرور من داخل الحساب | Change password while logged in. */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, Request $request): array
    {
        $user = $this->users->find($userId);

        if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            return ['success' => false, 'message' => Translator::get('auth.current_password_incorrect')];
        }

        $this->users->updatePassword($userId, $this->hashPassword($newPassword));

        $this->audit->setRequest($request);
        $this->audit->log(
            action: 'auth.password_changed',
            category: AuditLogger::CATEGORY_AUTH,
            entityType: 'user',
            entityId: $userId,
            description: 'تغيير كلمة المرور من داخل الحساب',
            severity: 'notice',
            userId: $userId,
        );

        return ['success' => true, 'message' => Translator::get('auth.password_changed')];
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, (string) Config::get('security.password.algorithm', PASSWORD_DEFAULT));
    }

    public function currentUserId(): ?int
    {
        $id = Session::get('user_id');

        return is_int($id) ? $id : null;
    }

    public function check(): bool
    {
        return $this->currentUserId() !== null;
    }
}
