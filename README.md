# StudentHub — Practical 15: Final Integrated Student Management Portal

StudentHub is a secure, responsive, full-stack campus management portal developed as the capstone integration for Practical 15. The system integrates student self-registration, session-based authentication, role-based authorization, comprehensive student and event CRUD operations, real-time AJAX search and filtering, and deployment documentation.

---

## 🎨 Design Philosophy & Aesthetic Palette

The visual identity of StudentHub breaks away from default blue-and-white templates, featuring a **bespoke, collegiate aesthetic** built around warm beige, roasted espresso, cognac, and amber tones:

| Token | Light Mode (Heritage Beige) | Dark Mode (Roasted Espresso) | Purpose |
|---|---|---|---|
| **Canvas** | `#F6F2EA` (Warm French Linen) | `#140E0B` (Deep Roasted Obsidian) | Primary background |
| **Card / Surface** | `#FCFAF6` (Alabaster Ivory) | `#231B16` (Smoked Cacao Panel) | Form & content cards |
| **Borders** | `#E2D7C8` (Warm Sandstone) | `#3D2F26` (Muted Bronze Line) | Delicate dividers |
| **Typography** | `#271A12` (Deep Espresso) | `#EFE7DE` (Creamy Almond) | High-contrast body text |
| **Primary Accent** | `#8C4721` (Warm Cognac) | `#CF7E44` (Amber Terracotta) | Buttons & active links |
| **Secondary Accent**| `#BA7B2B` (Heritage Gold) | `#E2A650` (Warm Caramel) | Badges & category pills |

- **Typography:** Oxford/Cambridge Ivy League inspired typography pairing Google Fonts **Cinzel** (classic serif headings) and **Plus Jakarta Sans** (clean, accessible interface text).
- **Theme Toggle:** Persistent light/dark mode switcher powered by Vanilla JavaScript and CSS custom properties (persisted in `localStorage`).

---

## 🛠️ Technology Stack

- **Frontend:** HTML5 semantic markup, CSS3 custom properties (Vanilla CSS), Vanilla JavaScript (ES6+ Fetch API, async/await).
- **Backend:** PHP 8.2+ with `declare(strict_types=1)`.
- **Database:** MySQL 8.0 / MariaDB with PDO (PHP Data Objects), prepared statements, foreign key constraints (`ON DELETE CASCADE`), and unique indexes.
- **Server Environment:** XAMPP Apache 2.4 on Windows (`C:\xampp\htdocs\StudentHub_Practical15`).
- **Version Control:** Git repository.

---

## 🚀 Key Functional Modules

1. **Responsive Campus Navigation:**
   - Sticky header with brand emblem, navigation links, role badge indicator, and theme switcher (`☀️` / `🌙`).
   - Collapsible mobile navigation menu.

2. **Authentication & Session Lifecycle:**
   - Student self-registration with duplicate username/email prevention and transaction safety.
   - Secure login with `password_verify()` against bcrypt hashes (`PASSWORD_DEFAULT`).
   - Automatic `session_regenerate_id(true)` upon authentication to protect against session fixation.
   - Session cookies configured with `HttpOnly`, `SameSite=Lax`, and `Secure` (when HTTPS active).

3. **Role-Based Access Control (RBAC):**
   - Enforced strictly on the server in PHP (`require_login()`, `require_admin()`), returning `401 Unauthorized` or `403 Forbidden` rather than relying on hidden UI buttons.
   - Dual roles: `student` (campus calendar browsing, self-registration, event enrollment) and `admin` (student directory CRUD, event scheduling, attendee rosters, CSV export).

4. **Student Management Directory (Admin CRUD):**
   - **Create:** Enroll new student with full validation.
   - **Read:** Paginated directory view with dynamic counters.
   - **Update:** Interactive modal dialog pre-populated with student data.
   - **Delete:** Secure deletion with confirmation and cascading registration cleanup.
   - **Export:** Instant one-click CSV export with UTF-8 BOM encoding for Microsoft Excel.

5. **Asynchronous AJAX Search & Filters:**
   - Multi-field search (`name`, `email`, `department`, `phone`) via `api/search_students.php`.
   - Debounced input (250ms) to eliminate redundant server requests.
   - Live department filter, semester filter, and multi-criteria sorting.
   - Safe DOM updates using `textContent` to eliminate DOM-based XSS vulnerabilities.

6. **Campus Event Management & Registration:**
   - Calendar showcase of campus workshops, symposiums, and festivals.
   - Students can register with one click; duplicate registration prevented via database constraints.
   - Cancellation of event registrations with automatic seat counter updates.
   - Administrators can view live attendee rosters via `api/event_attendees.php`.

7. **Interactive Knowledge Base & FAQ:**
   - Collapsible accordion with smooth animations and keyboard accessibility (`aria-expanded`).

---

## 💻 Local Installation Guide (XAMPP)

### Step 1: Place in XAMPP Web Root
Ensure the project folder resides in your XAMPP Apache root directory:
```
C:\xampp\htdocs\StudentHub_Practical15
```

### Step 2: Start Apache and MySQL
1. Open the **XAMPP Control Panel**.
2. Click **Start** for both **Apache** and **MySQL**.
3. Verify that both show green status indicators (Port 80/443 for Apache, Port 3306 for MySQL).

### Step 3: Import the Database Schema
1. Open your web browser and navigate to `http://localhost/phpmyadmin`.
2. Click **Import** in the top navigation bar.
3. Click **Choose File** and select:
   ```
   C:\xampp\htdocs\StudentHub_Practical15\database\studenthub.sql
   ```
4. Click **Go** at the bottom to execute the import.
   *(Alternatively, run via command line: `mysql -u root < database/studenthub.sql`)*

### Step 4: Verify Database Connection
`config.php` defaults to standard XAMPP settings:
```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'studenthub';
const DB_USER = 'root';
const DB_PASS = '';
```
If your local MySQL root user has a password, update `DB_PASS` accordingly.

### Step 5: Launch the Application
Open your web browser and navigate to:
```
http://localhost/StudentHub_Practical15/index.php
```

---

## 🔑 Pre-Configured Demonstration Accounts

| Role | Username | Password | Email | Linked Student Record |
|---|---|---|---|---|
| **Administrator** | `admin` | `password` | `admin@studenthub.local` | System Admin |
| **Student** | `aarav` | `password` | `aarav@example.edu` | Aarav Shah (CS, Sem 3) |
| **Student** | `diya` | `password` | `diya@example.edu` | Diya Patel (IT, Sem 2) |

> **Note:** The login interface features quick-fill buttons (`Fill Admin`, `Fill Student`) to facilitate immediate grading and evaluation.

---

## 🧪 Testing and Verification Suite

The repository includes both automated test suites and live HTTP verification scripts:

1. **Automated Unit & Database Tests:**
   ```powershell
   & "C:\xampp\php\php.exe" tests/automated_test.php
   ```
   *Executes 19 comprehensive test cases covering PDO connectivity, schema tables, bcrypt hashing, foreign key constraints, CRUD operations, and XSS sanitization.*

2. **Live HTTP & AJAX Session Verification:**
   ```powershell
   powershell -ExecutionPolicy Bypass -File tests/live_http_test.ps1
   ```
   *Verifies live login session flow, CSRF token extraction, admin dashboard recognition, and JSON API payloads.*

3. **Security Authorization & Role Enforcement:**
   ```powershell
   powershell -ExecutionPolicy Bypass -File tests/security_auth_test.ps1
   ```
   *Verifies that unauthenticated access returns HTTP 401 Unauthorized and unauthorized student access returns HTTP 403 Forbidden.*

---

## 📂 Project Structure

```
StudentHub_Practical15/
├── api/
│   ├── event_attendees.php       # JSON endpoint: returns attendees roster for admin
│   └── search_students.php       # JSON endpoint: debounced AJAX student search & filters
├── assets/
│   ├── app.js                    # Vanilla JS: theme toggle, AJAX search, modals, FAQ
│   └── style.css                 # Classy beige & espresso stylesheet (light/dark modes)
├── database/
│   └── studenthub.sql            # Complete database schema, foreign keys, and seed data
├── docs/
│   ├── SCREENSHOT_CHECKLIST.md   # Screenshot capture guide with filenames & instructions
│   ├── TEST_REPORT.md            # Detailed QA test report with results & security matrix
│   └── vhost.conf.example        # Apache VirtualHost configuration for studenthub.local
├── screenshots/                  # Evidence folder for browser verification screenshots
├── tests/
│   ├── automated_test.php        # CLI automated PHP test runner (19 test cases)
│   ├── live_http_test.ps1        # PowerShell live session & AJAX endpoint test runner
│   ├── manual-smoke-test.md      # Manual testing checklist
│   └── security_auth_test.ps1    # Role enforcement & 403/401 verification script
├── config.php                    # PDO database connection configuration
├── includes.php                  # Security helpers, CSRF verification, RBAC guards
├── index.php                     # Central application router, view templates & controllers
└── README.md                     # Comprehensive project documentation
```

---

## 🌐 Optional Apache Virtual Host Setup

To access the portal via `http://studenthub.local/`:
1. Open `C:\xampp\apache\conf\extra\httpd-vhosts.conf` and append:
   ```apache
   <VirtualHost *:80>
       ServerName studenthub.local
       DocumentRoot "C:/xampp/htdocs/StudentHub_Practical15"
       <Directory "C:/xampp/htdocs/StudentHub_Practical15">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
2. Open `C:\Windows\System32\drivers\etc\hosts` as Administrator and add:
   ```
   127.0.0.1 studenthub.local
   ```
3. Restart Apache in XAMPP and visit `http://studenthub.local/`.

---

## 🛡️ Security Implementation Summary

- **SQL Injection Prevention:** 100% of queries use PDO prepared statements with parameter binding.
- **Cross-Site Scripting (XSS) Prevention:** Output escaping via `e()` (`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`) and DOM updates via `textContent`.
- **Cross-Site Request Forgery (CSRF):** Cryptographic token generated per session (`bin2hex(random_bytes(32))`) and verified with `hash_equals()`.
- **Session Protection:** Session ID regeneration upon login, `HttpOnly`, `SameSite=Lax`.
- **Backend Authorization:** `require_admin()` and `require_login()` checked on server-side before execution of sensitive actions.
