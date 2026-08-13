# دليل النشر | Deployment Guide — Ubuntu 24.04

## 1. المتطلبات

Ubuntu 24.04 LTS · Apache 2.4 + mod_rewrite + mod_headers · PHP 8.3 (fpm أو mod_php)
· MySQL 8 · شهادة HTTPS.

```bash
sudo apt update
sudo apt install -y apache2 mysql-server \
  php8.3 php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd php8.3-zip \
  libapache2-mod-php8.3 unzip
sudo a2enmod rewrite headers ssl
```

## 2. الملفات والصلاحيات

```bash
sudo mkdir -p /var/www/nilepreneurs
sudo chown -R $USER:www-data /var/www/nilepreneurs
cd /var/www/nilepreneurs
# انسخ الكود هنا (git clone أو أرشيف)

composer install --no-dev --optimize-autoloader

# صلاحيات آمنة | Secure permissions
sudo find . -type f -exec chmod 640 {} \;
sudo find . -type d -exec chmod 750 {} \;
sudo chown -R www-data:www-data storage
sudo chmod -R 770 storage
sudo chmod 600 .env          # الأسرار للمالك فقط
```

## 3. قاعدة البيانات

```sql
CREATE DATABASE nilepreneurs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'np_app'@'localhost' IDENTIFIED BY 'كلمة-مرور-قوية-وفريدة';
-- أقل صلاحية لازمة للتشغيل | Least privilege
GRANT SELECT, INSERT, UPDATE, DELETE ON nilepreneurs.* TO 'np_app'@'localhost';
-- لازمة للترحيلات فقط؛ يمكن سحبها بعد النشر
GRANT CREATE, ALTER, INDEX, DROP, REFERENCES ON nilepreneurs.* TO 'np_app'@'localhost';
FLUSH PRIVILEGES;
```

## 4. الإعداد

```bash
cp .env.example .env
php bin/console key:generate
nano .env
```

قيم إلزامية للإنتاج:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
SESSION_SECURE=true
HSTS_ENABLED=true
DB_USERNAME=np_app
DB_PASSWORD=كلمة-مرور-قوية
```

```bash
php bin/console migrate
php bin/console seed        # البيانات المرجعية فقط — بذور العرض تُتخطّى تلقائياً
php bin/console health      # يجب أن يمرّ بالكامل
```

## 5. إعداد Apache

```apache
<VirtualHost *:443>
    ServerName your-domain.example

    # جذر الويب هو public/ فقط — الكود والإعدادات والمرفوعات خارجه
    DocumentRoot /var/www/nilepreneurs/public

    <Directory /var/www/nilepreneurs/public>
        AllowOverride All
        Require all granted
        Options -Indexes -MultiViews +FollowSymLinks
    </Directory>

    # منع الوصول لأي مسار خارج public صراحةً
    <DirectoryMatch "^/var/www/nilepreneurs/(app|config|database|storage|tests|vendor|bin|resources|docs|legacy)">
        Require all denied
    </DirectoryMatch>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/your-domain.example/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/your-domain.example/privkey.pem

    ErrorLog  ${APACHE_LOG_DIR}/nilepreneurs-error.log
    CustomLog ${APACHE_LOG_DIR}/nilepreneurs-access.log combined
</VirtualHost>

<VirtualHost *:80>
    ServerName your-domain.example
    Redirect permanent / https://your-domain.example/
</VirtualHost>
```

```bash
sudo a2ensite nilepreneurs && sudo systemctl reload apache2
sudo certbot --apache -d your-domain.example
```

## 6. المهام المجدولة | Cron

```cron
# تنظيف سجلات تحديد المعدّل | Prune rate-limit rows
0 * * * * www-data cd /var/www/nilepreneurs && php bin/console health > /dev/null

# نسخة احتياطية يومية 02:00
0 2 * * * root /usr/local/bin/np-backup.sh
```

> المهام الدورية للنشر المجدول وطابور الإشعارات وتنبيهات المخزون تُضاف مع
> المراحل التي تُنشئها (5–7).

## 7. النسخ الاحتياطي والاسترجاع

`/usr/local/bin/np-backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(date +%F-%H%M)
DEST=/var/backups/nilepreneurs
mkdir -p "$DEST"

# قاعدة البيانات | Database
mysqldump --single-transaction --routines --triggers \
  -u np_backup -p"$DB_BACKUP_PASSWORD" nilepreneurs \
  | gzip > "$DEST/db-$STAMP.sql.gz"

# الملفات المرفوعة | Uploaded files
tar -czf "$DEST/uploads-$STAMP.tar.gz" -C /var/www/nilepreneurs storage/uploads

# الاحتفاظ 30 يوماً
find "$DEST" -type f -mtime +30 -delete
```

**اختبار الاسترجاع (إلزامي دورياً — نسخة لم تُختبر ليست نسخة):**

```bash
mysql -e "CREATE DATABASE np_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip < /var/backups/nilepreneurs/db-2026-01-01-0200.sql.gz | mysql np_restore_test
mysql np_restore_test -e "SELECT COUNT(*) FROM organizations; SELECT COUNT(*) FROM users;"
mysql -e "DROP DATABASE np_restore_test;"
```

## 8. تدوير السجلات

`/etc/logrotate.d/nilepreneurs`:

```
/var/www/nilepreneurs/storage/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 640 www-data www-data
}
```

## 9. فحص الجاهزية والمراقبة

```bash
php bin/console health
```

يتحقق من: إصدار PHP · الامتدادات · وجود `APP_KEY` · قابلية الكتابة في `storage`
· وقوع المرفوعات خارج جذر الويب · اتصال قاعدة البيانات · تعطيل `APP_DEBUG`
· تفعيل الكوكيز الآمنة في الإنتاج.

**يُنصح بمراقبة:** توفر الموقع · زمن الاستجابة · مساحة القرص · نمو
`storage/logs` · محاولات الدخول الفاشلة في `login_attempts` · أحداث
`security.authorization_denied` في `audit_logs` · تأخر النسخ الاحتياطي.

## 10. إجراء التراجع | Rollback

```bash
# 1) إيقاف حركة المرور (صفحة صيانة أو إيقاف الموقع)
sudo a2dissite nilepreneurs && sudo systemctl reload apache2

# 2) استرجاع الكود
cd /var/www/nilepreneurs && git checkout <الوسم-السابق>
composer install --no-dev --optimize-autoloader

# 3) التراجع عن ترحيلات الإصدار الفاشل (إن لزم)
php bin/console migrate:rollback

# 4) أو استرجاع قاعدة البيانات كاملة من آخر نسخة سليمة
gunzip < /var/backups/nilepreneurs/db-<الطابع>.sql.gz | mysql nilepreneurs

# 5) التحقق ثم إعادة التشغيل
php bin/console health
sudo a2ensite nilepreneurs && sudo systemctl reload apache2
```

## 11. تحذيرات

- **لا تُرفع الأسرار إلى المستودع.** `.env` مستثنى في `.gitignore`.
- **لا تُشغّل بذور العرض التوضيحي في الإنتاج** — مرفوضة تلقائياً، لكن تحقق.
- **`migrate:fresh` ممنوع في الإنتاج** — يرفض التنفيذ عند `APP_ENV=production`.
- **اختبر الاسترجاع فعلياً** قبل الاعتماد على النسخ الاحتياطي.
