<?php
/**
 * Database.php
 * طبقة الاتصال بقاعدة البيانات باستخدام PDO (نمط Singleton)
 * PDO database connection layer (Singleton pattern).
 * يستخدم العبارات المُجهّزة (Prepared Statements) لحماية ضد حقن SQL.
 */
class Database
{
    private static ?PDO $instance = null;

    /** الحصول على اتصال PDO المشترك | Get the shared PDO connection */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                // ضمان ترميز عربي صحيح | Ensure correct Arabic encoding
                self::$instance->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                if (APP_DEBUG) {
                    die('Database connection failed: ' . $e->getMessage());
                }
                die('تعذّر الاتصال بقاعدة البيانات. يرجى مراجعة الإعدادات.');
            }
        }

        return self::$instance;
    }
}
