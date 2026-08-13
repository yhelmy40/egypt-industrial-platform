<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * طبقة قاعدة البيانات | Database access layer (PDO, prepared statements only).
 *
 * قواعد ثابتة | Invariants:
 *  - لا يُبنى أي استعلام بدمج قيم المستخدم في النص؛ المعاملات دائماً مرتبطة.
 *    No user value is ever concatenated into SQL; parameters are always bound.
 *  - أسماء الأعمدة/الجداول الديناميكية تمرّ عبر قوائم سماح في المستودعات.
 *    Dynamic identifiers pass through repository allow-lists.
 *  - المحاكاة معطّلة (EMULATE_PREPARES=false) لضمان تجهيز حقيقي على الخادم.
 */
final class Database
{
    private static ?PDO $connection = null;

    private static int $transactionDepth = 0;

    /** @var array<int,array{sql:string,time:float}> سجل الاستعلامات للتشخيص */
    private static array $queryLog = [];

    private static bool $logQueries = false;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::connect();
        }

        return self::$connection;
    }

    private static function connect(): PDO
    {
        $socket = (string) Config::get('database.socket', '');

        $dsn = $socket !== ''
            ? sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $socket,
                (string) Config::get('database.database'),
                (string) Config::get('database.charset', 'utf8mb4'),
            )
            : sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                (string) Config::get('database.host'),
                (string) Config::get('database.port', '3306'),
                (string) Config::get('database.database'),
                (string) Config::get('database.charset', 'utf8mb4'),
            );

        try {
            $pdo = new PDO(
                $dsn,
                (string) Config::get('database.username'),
                (string) Config::get('database.password', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ],
            );
        } catch (PDOException $e) {
            // لا نُظهر بيانات الاتصال للمستخدم مطلقاً
            // Connection details are never exposed to the user.
            Logger::critical('Database connection failed', ['error' => $e->getMessage()]);

            throw new RuntimeException('تعذّر الاتصال بقاعدة البيانات.', 0, $e);
        }

        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        /**
         * مزامنة المنطقة الزمنية بين PHP وقاعدة البيانات | Align DB and PHP clocks.
         *
         * الإزاحة تُحسب من التوقيت الفعلي الحالي ولا تُكتب ثابتة (+02:00)، لأن
         * مصر تطبّق التوقيت الصيفي؛ فرق ساعة واحدة بين ساعة القاعدة وساعة PHP
         * يُبطل مقارنات الصلاحية: قفل الحساب ينتهي قبل أوانه، ورموز التحقق
         * والاستعادة تبدو منتهية أو صالحة خطأً.
         * The offset is computed from the current effective time rather than
         * hard-coded, because Egypt observes DST. A one-hour drift between the
         * database clock and PHP's clock breaks every expiry comparison:
         * account locks expire early, and verification/reset tokens appear
         * expired — or valid — when they are not.
         */
        $offset = (new \DateTimeImmutable('now'))->format('P');
        $statement = $pdo->prepare('SET SESSION time_zone = ?');
        $statement->execute([$offset]);

        return $pdo;
    }

    /** حقن اتصال جاهز (للاختبارات) | Inject a connection (tests). */
    public static function setConnection(?PDO $pdo): void
    {
        self::$connection      = $pdo;
        self::$transactionDepth = 0;
    }

    public static function disconnect(): void
    {
        self::$connection       = null;
        self::$transactionDepth = 0;
    }

    // ---------------- تنفيذ الاستعلامات | Query execution ----------------

    /** @return array<int,array<string,mixed>> */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function statement(string $sql, array $bindings = []): bool
    {
        self::run($sql, $bindings);

        return true;
    }

    public static function affectingStatement(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    public static function insert(string $sql, array $bindings = []): int
    {
        self::run($sql, $bindings);

        return (int) self::connection()->lastInsertId();
    }

    private static function run(string $sql, array $bindings): \PDOStatement
    {
        $start = microtime(true);

        try {
            $statement = self::connection()->prepare($sql);
            $statement->execute(self::normalizeBindings($bindings));
        } catch (PDOException $e) {
            Logger::error('Query failed', [
                // النص فقط دون القيم، لأن القيم قد تحتوي بيانات شخصية
                // SQL text only — bindings may contain personal data.
                'sql'   => $sql,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (self::$logQueries) {
            self::$queryLog[] = ['sql' => $sql, 'time' => microtime(true) - $start];
        }

        return $statement;
    }

    /**
     * تطبيع القيم المرتبطة | Normalize bindings.
     * القيم المنطقية تُحوَّل لأعداد لأن MySQL لا يقبل bool في الأعمدة الرقمية بوضع صارم.
     */
    private static function normalizeBindings(array $bindings): array
    {
        $out = [];
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            $out[$key] = $value;
        }

        return $out;
    }

    // ---------------- المعاملات | Transactions ----------------

    /**
     * تنفيذ داخل معاملة مع دعم التداخل | Run inside a transaction (nesting-safe).
     *
     * يُستخدم لكل عملية تمسّ الطلبات والمخزون والفواتير وتغييرات الحالة.
     * Used for every operation touching orders, stock, invoices and statuses.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        self::beginTransaction();

        try {
            $result = $callback();
            self::commit();

            return $result;
        } catch (Throwable $e) {
            self::rollBack();

            throw $e;
        }
    }

    public static function beginTransaction(): void
    {
        if (self::$transactionDepth === 0) {
            self::connection()->beginTransaction();
        } else {
            self::connection()->exec('SAVEPOINT trans' . self::$transactionDepth);
        }

        self::$transactionDepth++;
    }

    public static function commit(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }

        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            self::connection()->commit();
        } else {
            self::connection()->exec('RELEASE SAVEPOINT trans' . self::$transactionDepth);
        }
    }

    public static function rollBack(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }

        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            if (self::connection()->inTransaction()) {
                self::connection()->rollBack();
            }
        } else {
            self::connection()->exec('ROLLBACK TO SAVEPOINT trans' . self::$transactionDepth);
        }
    }

    public static function inTransaction(): bool
    {
        return self::$transactionDepth > 0;
    }

    // ---------------- تشخيص | Diagnostics ----------------

    public static function enableQueryLog(): void
    {
        self::$logQueries = true;
        self::$queryLog   = [];
    }

    public static function queryLog(): array
    {
        return self::$queryLog;
    }

    public static function queryCount(): int
    {
        return count(self::$queryLog);
    }
}
