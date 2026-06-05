# منصة مصر للبحث والتطوير الصناعي
# Egypt Industrial Research & Development Platform

منصة حكومية عربية (RTL) لربط المصانع بالباحثين والخبراء، وإدارة التحديات الصناعية، ومشاريع البحث والتطوير، وفرص التمويل، ومركز المعرفة — لصالح وزارة الصناعة المصرية.

A government-grade, Arabic-first (RTL) platform connecting factories with researchers/experts, managing industrial challenges, R&D projects, funding opportunities, and a knowledge hub — for Egypt's Ministry of Industry.

- **Stack:** PHP 8 · MySQL 8 (MariaDB compatible) · vanilla JS · Bootstrap 5 RTL · Chart.js
- **Architecture:** lightweight custom MVC (no Laravel/React) · front controller · PDO prepared statements
- **Version:** 1.0.0-MVP

---

## 1. Requirements

- **XAMPP** (or any Apache + PHP 8.1+ + MySQL/MariaDB stack)
- PHP extensions: `pdo_mysql`, `mbstring` — both ship enabled by default in XAMPP
- Apache `mod_rewrite` enabled (for clean URLs)

---

## 2. Installation (XAMPP)

1. **Copy the project** into your web root:
   ```
   C:\xampp\htdocs\egypt-irdp        (Windows)
   /Applications/XAMPP/htdocs/egypt-irdp   (macOS)
   /opt/lampp/htdocs/egypt-irdp      (Linux)
   ```

2. **Start** Apache and MySQL from the XAMPP control panel.

3. **Create the database and import the schema, then the seed data.**
   Open phpMyAdmin (`http://localhost/phpmyadmin`) and either:
   - Run **`database/schema.sql`** first (it creates the `egypt_irdp` database and all tables), then **`database/seed.sql`** (demo data); or
   - From the command line:
     ```bash
     mysql -u root < database/schema.sql
     mysql -u root < database/seed.sql
     ```
   > Import order matters: **schema first, then seed.**

4. **Confirm database settings** in `config/config.php` (defaults match a stock XAMPP install):
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_PORT', '3306');
   define('DB_NAME', 'egypt_irdp');
   define('DB_USER', 'root');
   define('DB_PASS', '');        // XAMPP default root password is empty
   ```

5. **Open the app:**
   ```
   http://localhost/egypt-irdp/public/
   ```
   All requests route through `public/index.php`. The included `.htaccess` files enable clean URLs and protect the app/config/uploads directories — make sure `mod_rewrite` is on (`AllowOverride All` for the vhost).

6. **Uploads:** the `uploads/` folder must be writable by Apache. A bundled `uploads/.htaccess` blocks direct execution of any uploaded files for safety.

---

## 3. Demo accounts

| Role | Email | Password |
|------|-------|----------|
| Admin (Ministry) | `admin@industry.gov.eg` | `Admin123!` |
| Factory | `factory@demo.com` | `Factory123!` |
| Researcher | `researcher@demo.com` | `Research123!` |
| Expert | `expert@demo.com` | `Expert123!` |
| Investor / Funding | `investor@demo.com` | `Investor123!` |

Passwords are stored as bcrypt hashes (`password_hash`). Change them in production.

---

## 4. Modules

1. **Authentication** — login/logout, role-based dashboards, hashed passwords, CSRF protection.
2. **Factory Profile** — sector, governorate, contact, products, challenges, energy usage, production capacity.
3. **Industrial Challenges Bank** — factories submit challenges; admin approves / rejects / assigns. Status workflow: `pending → open → matched → in_progress → solved` (or `rejected`).
4. **Smart Matching (rule-based, no AI)** — scores challenges against researcher/expert profiles by shared sector + overlapping expertise keywords. Admin reviews ranked matches and assigns.
5. **Researcher / Expert Profiles** — organization, specialization, expertise keywords, previous projects, contact.
6. **R&D Projects** — admin converts an approved challenge into a tracked project.
7. **Funding Opportunities** — program, entity, eligible sectors, max amount, deadline, contact.
8. **Knowledge Hub** — articles/resources by category and sector, with optional file upload or external link.
9. **Ministry Dashboard** — KPIs + Chart.js (sector distribution doughnut, priority bar chart).
10. **Notifications** — triggered on challenge approval, matching, project creation, and new funding.

---

## 5. How the matching engine works

The matcher is deterministic and transparent (no machine learning):

1. Tokenize the challenge text (`needed_expertise` + title + description) and each profile (`expertise_keywords` + specialization + previous projects).
2. Remove Arabic and English stop words and normalize.
3. Intersect the keyword sets.
4. **Score = (matched keywords × 10) + (15 sector-match bonus)**, sorted descending.

Admin sees the ranked list on the challenge's *Matches* page and persists chosen matches, which flips the challenge to `matched` and notifies the relevant parties.

---

## 6. Project structure

```
egypt-irdp/
├── config/config.php          # constants: DB, app name, paths, debug
├── public/                    # web root (point the browser here)
│   ├── index.php              # single front controller
│   ├── .htaccess              # routes all requests to index.php
│   └── assets/                # css/style.css, js/app.js
├── app/
│   ├── core/                  # Database (PDO singleton), Model, Controller, Router
│   ├── helpers/               # Auth, Csrf, Validator, functions.php
│   ├── controllers/           # one controller per module
│   ├── models/                # one model per table (+ ChallengeMatch engine)
│   └── views/                 # layouts, partials, and per-module templates (Arabic RTL)
├── uploads/                   # user file uploads (writable; execution blocked)
├── database/
│   ├── schema.sql             # tables, keys, enums (import FIRST)
│   └── seed.sql               # demo data (import SECOND)
└── README.md
```

---

## 7. Production notes

- Set `APP_DEBUG` to `false` in `config/config.php` to hide error details from users.
- Change all demo passwords and the database credentials.
- Serve only the `public/` directory as the web root; keep `app/`, `config/`, and `database/` outside the document root (or rely on the bundled `.htaccess` denials).
- Optionally set `BASE_URL` in `config/config.php` if the app is not at the server root.

---

*Built as a working MVP. UI language is Arabic (RTL); the data model and code comments are bilingual.*
