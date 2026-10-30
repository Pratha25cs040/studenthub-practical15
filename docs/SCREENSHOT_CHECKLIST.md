# StudentHub Practical 15 — Screenshot Evidence Checklist

This checklist guides the capture of genuine browser screenshots for college laboratory submission and evaluation.

> **Capture Tool:** On Windows, press **`Win + Shift + S`** to open the Snipping Tool, select the browser window, and save the image into the `screenshots/` directory using the filenames listed below.

---

## 📸 Required Screenshot Matrix

| # | Filename | Target Page / Action | Key Elements to Capture |
|---|---|---|---|
| **01** | `01-home.png` | `http://localhost/StudentHub_Practical15/index.php` | Collegiate header with "SH" crest, hero section with stats counters, feature spotlights, and beige/brown palette. |
| **02** | `02-home-faq.png` | `http://localhost/StudentHub_Practical15/index.php#faq` | Expanded interactive FAQ accordion item with chevron rotation and collegiate typography. |
| **03** | `03-events-catalog.png` | `http://localhost/StudentHub_Practical15/index.php?page=events` | Campus events grid with date tags, category badges, venue details, and "Sign in to Register" buttons. |
| **04** | `04-login.png` | `http://localhost/StudentHub_Practical15/index.php?page=login` | Clean sign-in card with inputs and quick-fill helper buttons (`Fill Admin`, `Fill Student`). |
| **05** | `05-registration.png` | `http://localhost/StudentHub_Practical15/index.php?page=register` | Student self-registration form with legal name, institutional email, department dropdown, and semester select. |
| **06** | `06-admin-dashboard.png` | `http://localhost/StudentHub_Practical15/index.php?page=dashboard` | Logged-in admin dashboard showing "Welcome back, admin", admin role pill, metrics cards, and recent registrations. |
| **07** | `07-admin-students.png` | `http://localhost/StudentHub_Practical15/index.php?page=students` | Student management directory with search input, department/semester filters, and table with Edit/Delete action buttons. |
| **08** | `08-ajax-search.png` | `http://localhost/StudentHub_Practical15/index.php?page=students` | Search input populated with query (e.g., `'Aarav'`), demonstrating real-time filtered table rows and dynamic counter. |
| **09** | `09-student-edit-modal.png` | `http://localhost/StudentHub_Practical15/index.php?page=students` | Edit Student modal dialog open with pre-filled student information ready for modification. |
| **10** | `10-event-attendees-modal.png`| `http://localhost/StudentHub_Practical15/index.php?page=events` | Admin clicking "Attendees" button showing live student enrollment roster modal. |
| **11** | `11-student-dashboard.png` | `http://localhost/StudentHub_Practical15/index.php?page=dashboard` | Logged in as student `aarav` showing personal enrolled events schedule with cancel registration options. |
| **12** | `12-dark-mode.png` | Any page with theme toggled | The application running in **Roasted Espresso Dark Mode** (clicked `🌙` toggle), displaying deep espresso background with warm amber accents. |
| **13** | `13-mobile-responsive.png` | Mobile view (F12 DevTools: 375px) | Narrow viewport demonstration showing responsive collapsed navigation menu and stacked feature cards. |
| **14** | `14-database-phpmyadmin.png` | `http://localhost/phpmyadmin` | phpMyAdmin interface showing `studenthub` database with `users`, `students`, `events`, and `registrations` tables. |

---

## 🎯 Verification Guidelines for Grading

1. **Verify No Blue Color:** Check that headers, buttons, cards, and backgrounds strictly use warm beige (`#F6F2EA`), espresso brown (`#271A12`), cognac (`#8C4721`), and amber (`#BA7B2B`).
2. **Verify Role Awareness:** Screenshots 06 and 07 demonstrate administrator capabilities, while screenshot 11 shows student-specific self-service features.
3. **Verify Integrity:** Capture the entire browser window including the address bar showing `localhost/StudentHub_Practical15/...` to substantiate genuine local execution.
