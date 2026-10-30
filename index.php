<?php
declare(strict_types=1);

require_once __DIR__ . '/includes.php';

$page = (string)($_GET['page'] ?? 'home');
$allowedPages = ['home', 'login', 'register', 'dashboard', 'students', 'events', 'profile', 'faq', 'logout'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'home';
}

$user = current_user();
$studentRecord = current_student_record();
$flashNotice = take_flash();

// ---------------------------------------------------------------------
// Handle Logout
// ---------------------------------------------------------------------
if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_start();
    flash('You have been signed out successfully.', 'info');
    header('Location: index.php?page=login');
    exit;
}

// ---------------------------------------------------------------------
// Handle CSV Export (Admin only)
// ---------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'students_csv') {
    require_admin();
    $stmt = db()->query('SELECT student_id, full_name, email, department, semester, phone, created_at FROM students ORDER BY student_id ASC');
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="studenthub_students_' . date('Y-m-d') . '.csv"');
    
    // Output UTF-8 BOM for Microsoft Excel compatibility
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'Full Name', 'Email', 'Department', 'Semester', 'Phone', 'Registered At']);
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['student_id'],
            $r['full_name'],
            $r['email'],
            $r['department'],
            $r['semester'],
            $r['phone'] ?? '',
            $r['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// ---------------------------------------------------------------------
// POST Request Handling (Strict CSRF Protection)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        // --- 1. User Registration ---
        if ($action === 'register') {
            $username    = trim((string)($_POST['username'] ?? ''));
            $email       = trim((string)($_POST['email'] ?? ''));
            $fullName    = trim((string)($_POST['full_name'] ?? ''));
            $department  = trim((string)($_POST['department'] ?? ''));
            $semester    = filter_var($_POST['semester'] ?? null, FILTER_VALIDATE_INT);
            $phone       = trim((string)($_POST['phone'] ?? ''));
            $password    = (string)($_POST['password'] ?? '');
            $passConfirm = (string)($_POST['password_confirm'] ?? '');

            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                throw new RuntimeException('Username must be 3-50 characters with letters, numbers, dots, dashes, or underscores.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please provide a valid institutional email address.');
            }
            if ($fullName === '' || $department === '') {
                throw new RuntimeException('Full name and department are required.');
            }
            if ($semester === false || $semester < 1 || $semester > 12) {
                throw new RuntimeException('Semester must be a valid number between 1 and 12.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('Password must contain at least 8 characters.');
            }
            if ($passConfirm !== '' && $password !== $passConfirm) {
                throw new RuntimeException('Password confirmation does not match.');
            }

            $pdo = db();
            // Check existing user or student email
            $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
            $chk->execute([$username, $email]);
            if ((int)$chk->fetchColumn() > 0) {
                throw new RuntimeException('That username or email address is already registered in the system.');
            }

            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $q1 = $pdo->prepare("INSERT INTO users(username, email, password_hash, role) VALUES (?, ?, ?, 'student')");
            $q1->execute([$username, $email, $hash]);
            $newUserId = (int)$pdo->lastInsertId();

            $q2 = $pdo->prepare('INSERT INTO students(user_id, full_name, email, department, semester, phone) VALUES (?, ?, ?, ?, ?, ?)');
            $q2->execute([$newUserId, $fullName, $email, $department, $semester, $phone ?: null]);
            $pdo->commit();

            flash('Account created successfully! You can now sign in with your credentials.', 'success');
            header('Location: index.php?page=login');
            exit;
        }

        // --- 2. User Authentication ---
        if ($action === 'login') {
            $identity = trim((string)($_POST['identity'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($identity === '' || $password === '') {
                throw new RuntimeException('Please enter your username/email and password.');
            }

            $stmt = db()->prepare('SELECT user_id, username, email, password_hash, role FROM users WHERE email = ? OR username = ? LIMIT 1');
            $stmt->execute([$identity, $identity]);
            $foundUser = $stmt->fetch();

            if (!$foundUser || !password_verify($password, $foundUser['password_hash'])) {
                throw new RuntimeException('Invalid credentials provided. Please double check and try again.');
            }

            // Regenerate session ID on successful login to prevent session fixation
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'       => (int)$foundUser['user_id'],
                'username' => $foundUser['username'],
                'email'    => $foundUser['email'],
                'role'     => $foundUser['role']
            ];
            // Rotate CSRF token
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            flash('Welcome back, ' . e($foundUser['username']) . '! You are logged in.', 'success');
            header('Location: index.php?page=dashboard');
            exit;
        }

        // --- 3. Admin: Add Student ---
        if ($action === 'add_student') {
            require_admin();
            $fullName   = trim((string)($_POST['full_name'] ?? ''));
            $email      = trim((string)($_POST['email'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $semester   = filter_var($_POST['semester'] ?? null, FILTER_VALIDATE_INT);
            $phone      = trim((string)($_POST['phone'] ?? ''));

            if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $department === '' || !$semester || $semester < 1 || $semester > 12) {
                throw new RuntimeException('All required student fields must be correctly filled.');
            }

            $stmt = db()->prepare('INSERT INTO students(full_name, email, department, semester, phone) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$fullName, $email, $department, $semester, $phone ?: null]);

            flash("Student '{$fullName}' added to registry successfully.", 'success');
            header('Location: index.php?page=students');
            exit;
        }

        // --- 4. Admin: Edit Student Record ---
        if ($action === 'edit_student') {
            require_admin();
            $studentId  = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            $fullName   = trim((string)($_POST['full_name'] ?? ''));
            $email      = trim((string)($_POST['email'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $semester   = filter_var($_POST['semester'] ?? null, FILTER_VALIDATE_INT);
            $phone      = trim((string)($_POST['phone'] ?? ''));

            if (!$studentId || $fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $department === '' || !$semester) {
                throw new RuntimeException('Invalid update data supplied for student record.');
            }

            $stmt = db()->prepare('UPDATE students SET full_name = ?, email = ?, department = ?, semester = ?, phone = ? WHERE student_id = ?');
            $stmt->execute([$fullName, $email, $department, $semester, $phone ?: null, $studentId]);

            flash("Student record #{$studentId} updated successfully.", 'success');
            header('Location: index.php?page=students');
            exit;
        }

        // --- 5. Admin: Delete Student Record ---
        if ($action === 'delete_student') {
            require_admin();
            $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$studentId) {
                throw new RuntimeException('Invalid student ID for deletion.');
            }

            $stmt = db()->prepare('DELETE FROM students WHERE student_id = ?');
            $stmt->execute([$studentId]);

            flash('Student record and related registrations deleted successfully.', 'success');
            header('Location: index.php?page=students');
            exit;
        }

        // --- 6. Admin: Add Campus Event ---
        if ($action === 'add_event') {
            require_admin();
            $name     = trim((string)($_POST['event_name'] ?? ''));
            $category = trim((string)($_POST['category'] ?? 'Academic'));
            $date     = trim((string)($_POST['event_date'] ?? ''));
            $venue    = trim((string)($_POST['venue'] ?? ''));
            $seats    = filter_var($_POST['max_seats'] ?? 60, FILTER_VALIDATE_INT);
            $desc     = trim((string)($_POST['description'] ?? ''));

            if ($name === '' || $date === '' || $venue === '') {
                throw new RuntimeException('Event name, date, and venue are mandatory.');
            }

            $stmt = db()->prepare('INSERT INTO events(event_name, category, event_date, venue, max_seats, description) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $category, $date, $venue, $seats ?: 60, $desc]);

            flash('New campus event created successfully.', 'success');
            header('Location: index.php?page=events');
            exit;
        }

        // --- 7. Admin: Edit Event ---
        if ($action === 'edit_event') {
            require_admin();
            $eventId  = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            $name     = trim((string)($_POST['event_name'] ?? ''));
            $category = trim((string)($_POST['category'] ?? 'Academic'));
            $date     = trim((string)($_POST['event_date'] ?? ''));
            $venue    = trim((string)($_POST['venue'] ?? ''));
            $seats    = filter_var($_POST['max_seats'] ?? 60, FILTER_VALIDATE_INT);
            $desc     = trim((string)($_POST['description'] ?? ''));

            if (!$eventId || $name === '' || $date === '' || $venue === '') {
                throw new RuntimeException('Invalid details for event update.');
            }

            $stmt = db()->prepare('UPDATE events SET event_name = ?, category = ?, event_date = ?, venue = ?, max_seats = ?, description = ? WHERE event_id = ?');
            $stmt->execute([$name, $category, $date, $venue, $seats ?: 60, $desc, $eventId]);

            flash("Event #{$eventId} updated successfully.", 'success');
            header('Location: index.php?page=events');
            exit;
        }

        // --- 8. Admin: Delete Event ---
        if ($action === 'delete_event') {
            require_admin();
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$eventId) throw new RuntimeException('Invalid event ID.');

            $stmt = db()->prepare('DELETE FROM events WHERE event_id = ?');
            $stmt->execute([$eventId]);

            flash('Event deleted successfully.', 'success');
            header('Location: index.php?page=events');
            exit;
        }

        // --- 9. Student: Register for Event ---
        if ($action === 'register_event') {
            require_login();
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$eventId) throw new RuntimeException('Invalid event selection.');

            $student = current_student_record();
            if (!$student) {
                throw new RuntimeException('Your user account has no associated student profile. Please contact an administrator.');
            }

            // Check if already registered
            $check = db()->prepare('SELECT COUNT(*) FROM registrations WHERE student_id = ? AND event_id = ?');
            $check->execute([$student['student_id'], $eventId]);
            if ((int)$check->fetchColumn() > 0) {
                throw new RuntimeException('You are already registered for this event.');
            }

            $stmt = db()->prepare("INSERT INTO registrations(student_id, event_id, status) VALUES (?, ?, 'confirmed')");
            $stmt->execute([$student['student_id'], $eventId]);

            flash('Congratulations! You have been registered for this event.', 'success');
            header('Location: index.php?page=events');
            exit;
        }

        // --- 10. Student or Admin: Cancel Event Registration ---
        if ($action === 'cancel_registration') {
            require_login();
            $regId = filter_var($_POST['registration_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$regId) throw new RuntimeException('Invalid registration record.');

            if (($user['role'] ?? '') === 'admin') {
                $stmt = db()->prepare('DELETE FROM registrations WHERE registration_id = ?');
                $stmt->execute([$regId]);
            } else {
                $student = current_student_record();
                if (!$student) throw new RuntimeException('Unauthorized action.');
                $stmt = db()->prepare('DELETE FROM registrations WHERE registration_id = ? AND student_id = ?');
                $stmt->execute([$regId, $student['student_id']]);
            }

            flash('Event registration cancelled.', 'info');
            $redirect = ($_POST['redirect'] ?? '') === 'dashboard' ? 'dashboard' : 'events';
            header("Location: index.php?page={$redirect}");
            exit;
        }

        // --- 11. Profile: Update Personal Information ---
        if ($action === 'update_profile') {
            require_login();
            $fullName   = trim((string)($_POST['full_name'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $semester   = filter_var($_POST['semester'] ?? null, FILTER_VALIDATE_INT);
            $phone      = trim((string)($_POST['phone'] ?? ''));

            if ($fullName === '' || $department === '' || !$semester || $semester < 1 || $semester > 12) {
                throw new RuntimeException('Please provide valid academic details.');
            }

            $student = current_student_record();
            if ($student) {
                $stmt = db()->prepare('UPDATE students SET full_name = ?, department = ?, semester = ?, phone = ? WHERE student_id = ?');
                $stmt->execute([$fullName, $department, $semester, $phone ?: null, $student['student_id']]);
            } else {
                // If admin without student row, create or link
                $stmt = db()->prepare('INSERT INTO students(user_id, full_name, email, department, semester, phone) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$user['id'], $fullName, $user['email'], $department, $semester, $phone ?: null]);
            }

            flash('Profile details updated successfully.', 'success');
            header('Location: index.php?page=profile');
            exit;
        }

        // --- 12. Profile: Change Password ---
        if ($action === 'change_password') {
            require_login();
            $currentPass = (string)($_POST['current_password'] ?? '');
            $newPass     = (string)($_POST['new_password'] ?? '');
            $confirmPass = (string)($_POST['confirm_password'] ?? '');

            if (strlen($newPass) < 8) {
                throw new RuntimeException('New password must be at least 8 characters long.');
            }
            if ($newPass !== $confirmPass) {
                throw new RuntimeException('New password and confirmation do not match.');
            }

            $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $hash = (string)$stmt->fetchColumn();

            if (!password_verify($currentPass, $hash)) {
                throw new RuntimeException('Current password does not match.');
            }

            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $update = db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
            $update->execute([$newHash, $user['id']]);

            flash('Password updated successfully. Please use your new password next time.', 'success');
            header('Location: index.php?page=profile');
            exit;
        }

    } catch (Throwable $ex) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_error($ex);
        $userMsg = $ex instanceof PDOException 
            ? 'A database error occurred. Ensure no duplicate records exist and check database connection.' 
            : $ex->getMessage();
        flash($userMsg, 'error');
        header('Location: index.php?page=' . $page);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?=e($_SESSION['csrf'] ?? '')?>">
  <meta name="description" content="StudentHub Practical 15 — Integrated Student Management Portal for campus academics, student directory and event registration.">
  <title><?=ucfirst($page)?> | StudentHub Academic Portal</title>
  
  <!-- Collegiate Academic Fonts: Cinzel & Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Bespoke Heritage Beige and Roasted Espresso Stylesheet -->
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <!-- Top Navigation Header -->
  <header class="top-header" role="banner">
    <div class="nav-container">
      <a class="brand-link" href="index.php" aria-label="StudentHub Home">
        <div class="brand-emblem" aria-hidden="true">SH</div>
        <div class="brand-text">
          <span class="brand-title">StudentHub</span>
          <span class="brand-subtitle">Campus Management Portal</span>
        </div>
      </a>

      <nav role="navigation" aria-label="Primary Navigation">
        <ul class="nav-menu" id="navMenu">
          <li><a class="nav-link <?=$page==='home'?'active':''?>" href="index.php">Home</a></li>
          <li><a class="nav-link <?=$page==='events'?'active':''?>" href="index.php?page=events">Campus Events</a></li>
          <li><a class="nav-link <?=$page==='faq'?'active':''?>" href="index.php?page=faq">FAQ</a></li>

          <?php if ($user): ?>
            <li><a class="nav-link <?=$page==='dashboard'?'active':''?>" href="index.php?page=dashboard">Dashboard</a></li>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
              <li><a class="nav-link <?=$page==='students'?'active':''?>" href="index.php?page=students">Student Records</a></li>
            <?php endif; ?>
            <li><a class="nav-link <?=$page==='profile'?'active':''?>" href="index.php?page=profile">Profile</a></li>
          <?php endif; ?>
        </ul>
      </nav>

      <div class="nav-actions">
        <!-- Theme Toggle (Beige Light / Espresso Dark) -->
        <button id="themeToggle" class="theme-btn" type="button" title="Switch appearance mode" aria-label="Toggle theme">🌙</button>

        <?php if ($user): ?>
          <span class="user-pill">
            <span><?=e($user['username'])?></span>
            <span class="role-badge <?=e($user['role'])?>"><?=e($user['role'])?></span>
          </span>
          <a class="btn btn-secondary btn-sm" href="index.php?page=logout">Sign Out</a>
        <?php else: ?>
          <a class="nav-link <?=$page==='login'?'active':''?>" href="index.php?page=login">Sign In</a>
          <a class="btn btn-primary btn-sm" href="index.php?page=register">Register</a>
        <?php endif; ?>

        <button class="mobile-toggle" id="mobileToggle" type="button" aria-label="Toggle navigation menu">☰</button>
      </div>
    </div>
  </header>

  <!-- Main Content Canvas -->
  <main class="main-wrapper" id="mainContent" role="main">
    
    <!-- Flash Status Notifications -->
    <?php if ($flashNotice): ?>
      <div class="alert-banner <?=e($flashNotice[1])?>" role="alert">
        <span><?=e($flashNotice[0])?></span>
        <button type="button" style="background:none;border:none;cursor:pointer;font-weight:700;color:inherit;" onclick="this.parentElement.remove()" aria-label="Close notification">&times;</button>
      </div>
    <?php endif; ?>

    <?php
    // =================================================================
    // PAGE: HOME
    // =================================================================
    if ($page === 'home'):
      $totStudents = count_rows('students');
      $totEvents   = count_rows('events');
      $totRegs     = count_rows('registrations');
    ?>
      <!-- Hero Showcase -->
      <section class="hero-grid" aria-labelledby="heroHeading">
        <div class="hero-content">
          <span class="eyebrow">Practical 15 · Final Integrated Portal</span>
          <h1 id="heroHeading">An academic sanctuary for <span>campus excellence.</span></h1>
          <p class="hero-lead">
            Welcome to StudentHub — a unified, secure campus management ecosystem combining searchable student records, academic event registration, and role-based administrative intelligence.
          </p>
          <div class="hero-cta-group">
            <a class="btn btn-primary" href="index.php?page=events">Browse Campus Events</a>
            <?php if (!$user): ?>
              <a class="btn btn-secondary" href="index.php?page=register">Create Student Account</a>
            <?php else: ?>
              <a class="btn btn-secondary" href="index.php?page=dashboard">Open Your Dashboard</a>
            <?php endif; ?>
          </div>
        </div>

        <div class="hero-showcase">
          <div class="showcase-header">
            <div class="showcase-icon" aria-hidden="true">🏛️</div>
            <div>
              <h2>Collegiate Registry</h2>
              <p>Verified Institutional Data & Services</p>
            </div>
          </div>
          <p style="color:var(--text-muted); font-size:14px;">
            Designed for high performance and integrity with prepared PDO transactions, CSRF tokens, and modern asynchronous AJAX search.
          </p>
          <div class="metrics-row">
            <div class="metric-item">
              <span class="metric-num"><?=$totStudents?></span>
              <span class="metric-label">Enrolled Students</span>
            </div>
            <div class="metric-item">
              <span class="metric-num"><?=$totEvents?></span>
              <span class="metric-label">Active Events</span>
            </div>
            <div class="metric-item">
              <span class="metric-num"><?=$totRegs?></span>
              <span class="metric-label">Registrations</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Integrated Features Overview -->
      <section style="margin: 48px 0;" aria-labelledby="featuresHeading">
        <div style="margin-bottom: 24px;">
          <span class="eyebrow">Capabilities & Modules</span>
          <h2 id="featuresHeading" style="font-size: 30px;">Fully Integrated Campus Architecture</h2>
        </div>
        <div class="features-grid">
          <article class="feature-box">
            <span class="feature-num">01 / DIRECTORY</span>
            <h3>Student CRUD</h3>
            <p>Administer student profiles with instant search, multi-department filters, semester indexing, and CSV export capabilities.</p>
          </article>
          <article class="feature-box">
            <span class="feature-num">02 / CALENDAR</span>
            <h3>Event Management</h3>
            <p>Publish workshops, symposiums, and tech fests. Students register with one click; administrators monitor live rosters.</p>
          </article>
          <article class="feature-box">
            <span class="feature-num">03 / SECURITY</span>
            <h3>Protected Access</h3>
            <p>Role-aware PHP enforcement, password bcrypt hashing, parameterized statements, and automatic session regeneration.</p>
          </article>
          <article class="feature-box">
            <span class="feature-num">04 / ASYNC UX</span>
            <h3>AJAX Integration</h3>
            <p>Real-time Fetch API querying with debounce, JSON responses, accessible modals, and light/dark theme persistence.</p>
          </article>
        </div>
      </section>

      <!-- Interactive FAQ Section (Integrated Practical 4/5) -->
      <section style="margin: 48px 0;" aria-labelledby="faqHeading">
        <div style="margin-bottom: 20px;">
          <span class="eyebrow">Questions & Answers</span>
          <h2 id="faqHeading" style="font-size: 28px;">Frequently Asked Questions</h2>
          <p style="color:var(--text-muted);">Common inquiries regarding StudentHub registration, authentication, and campus services.</p>
        </div>

        <div class="faq-list">
          <div class="faq-item">
            <button class="faq-question" type="button" aria-expanded="false">
              <span>How do I register as a student for campus events?</span>
              <span class="faq-chevron" aria-hidden="true">▼</span>
            </button>
            <div class="faq-answer">
              Create an account using the <strong>Register</strong> page. Once logged in, navigate to the <strong>Campus Events</strong> catalog, select any upcoming event, and click <strong>Register Now</strong>. Your registration is instantly confirmed and tracked in your personal dashboard.
            </div>
          </div>

          <div class="faq-item">
            <button class="faq-question" type="button" aria-expanded="false">
              <span>What are the administrator privileges?</span>
              <span class="faq-chevron" aria-hidden="true">▼</span>
            </button>
            <div class="faq-answer">
              Administrators have full CRUD access over student records (Create, Read, Update, Delete), can export data to CSV, publish new campus events, edit event venues/dates, and inspect live student attendee rosters. Administrative privileges are strictly enforced on the PHP server.
            </div>
          </div>

          <div class="faq-item">
            <button class="faq-question" type="button" aria-expanded="false">
              <span>How are credentials and sensitive data protected?</span>
              <span class="faq-chevron" aria-hidden="true">▼</span>
            </button>
            <div class="faq-answer">
              Passwords are encrypted using PHP's industry-standard <code>password_hash()</code> with strong bcrypt hashing. All database queries utilize PDO prepared statements with strict parameter binding to eliminate SQL injection risks. CSRF tokens guard all state-modifying requests.
            </div>
          </div>

          <div class="faq-item">
            <button class="faq-question" type="button" aria-expanded="false">
              <span>Can I customize the visual appearance?</span>
              <span class="faq-chevron" aria-hidden="true">▼</span>
            </button>
            <div class="faq-answer">
              Yes! Click the theme toggle icon (🌙 / ☀️) in the top navigation bar to switch between the <strong>Classy Heritage Beige</strong> daylight mode and the <strong>Roasted Espresso</strong> dark mode. Your preference is automatically stored in your browser's local storage.
            </div>
          </div>
        </div>
      </section>

    <?php
    // =================================================================
    // PAGE: LOGIN
    // =================================================================
    elseif ($page === 'login'):
    ?>
      <div class="auth-wrapper">
        <div class="auth-card">
          <span class="eyebrow">Institutional Access</span>
          <h1 style="font-size: 32px; margin-bottom: 8px;">Sign In</h1>
          <p style="color:var(--text-muted); margin-bottom: 24px;">Enter your credentials to access your StudentHub workspace.</p>

          <form method="post" action="index.php?page=login" novalidate>
            <?=csrf_field()?>
            <input type="hidden" name="action" value="login">

            <div class="form-group">
              <label class="form-label" for="loginIdentity">Username or Email Address</label>
              <input class="form-control" type="text" id="loginIdentity" name="identity" required autocomplete="username" autofocus placeholder="e.g. admin or aarav">
            </div>

            <div class="form-group">
              <label class="form-label" for="loginPassword">Password</label>
              <input class="form-control" type="password" id="loginPassword" name="password" required autocomplete="current-password" placeholder="Enter your password">
            </div>

            <button class="btn btn-primary btn-block" type="submit" style="margin-top: 10px;">Sign In to Portal</button>
          </form>

          <!-- Quick Fill Buttons for Examination Testing -->
          <div class="demo-credentials-box">
            <strong>Demonstration Accounts:</strong><br>
            <div style="margin-top:6px;">
              Admin: <code>admin</code> / <code>password</code>
              <button type="button" class="demo-fill-btn" onclick="fillDemoCredentials('admin', 'password')">Fill Admin</button>
            </div>
            <div style="margin-top:6px;">
              Student: <code>aarav</code> / <code>password</code>
              <button type="button" class="demo-fill-btn" onclick="fillDemoCredentials('aarav', 'password')">Fill Student</button>
            </div>
          </div>

          <p style="text-align: center; margin-top: 24px; font-size: 14px; color: var(--text-muted);">
            Don't have an account yet? <a href="index.php?page=register">Register here</a>
          </p>
        </div>
      </div>

    <?php
    // =================================================================
    // PAGE: REGISTER
    // =================================================================
    elseif ($page === 'register'):
    ?>
      <div class="auth-wrapper">
        <div class="auth-card" style="max-width: 620px;">
          <span class="eyebrow">Student Enrollment</span>
          <h1 style="font-size: 32px; margin-bottom: 8px;">Create Account</h1>
          <p style="color:var(--text-muted); margin-bottom: 24px;">Register your student profile for verified access to campus portals and events.</p>

          <form method="post" action="index.php?page=register" id="registerForm">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="register">

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regFullName">Full Legal Name *</label>
                <input class="form-control" type="text" id="regFullName" name="full_name" required maxlength="120" placeholder="e.g. Diya Sharma">
              </div>

              <div class="form-group">
                <label class="form-label" for="regUsername">Username *</label>
                <input class="form-control" type="text" id="regUsername" name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]{3,50}" placeholder="e.g. diya_s">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regEmail">Institutional Email *</label>
                <input class="form-control" type="email" id="regEmail" name="email" required placeholder="diya@example.edu">
              </div>

              <div class="form-group">
                <label class="form-label" for="regPhone">Contact Phone (Optional)</label>
                <input class="form-control" type="tel" id="regPhone" name="phone" placeholder="+91 98765 00000">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regDept">Department *</label>
                <select class="form-control" id="regDept" name="department" required>
                  <option value="">Select Academic Department</option>
                  <option value="Computer Science">Computer Science</option>
                  <option value="Information Technology">Information Technology</option>
                  <option value="Data Science">Data Science</option>
                  <option value="Electronics & Comm.">Electronics & Comm.</option>
                  <option value="Mechanical Engineering">Mechanical Engineering</option>
                  <option value="Civil Engineering">Civil Engineering</option>
                  <option value="Business Administration">Business Administration</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label" for="regSem">Current Semester *</label>
                <select class="form-control" id="regSem" name="semester" required>
                  <?php for ($i=1; $i<=8; $i++): ?>
                    <option value="<?=$i?>">Semester <?=$i?></option>
                  <?php endfor; ?>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regPassword">Password (Min 8 chars) *</label>
                <input class="form-control" type="password" id="regPassword" name="password" required minlength="8" placeholder="••••••••">
              </div>

              <div class="form-group">
                <label class="form-label" for="regConfirm">Confirm Password *</label>
                <input class="form-control" type="password" id="regConfirm" name="password_confirm" required minlength="8" placeholder="••••••••">
              </div>
            </div>

            <button class="btn btn-primary btn-block" type="submit" style="margin-top: 14px;">Complete Student Registration</button>
          </form>

          <p style="text-align: center; margin-top: 24px; font-size: 14px; color: var(--text-muted);">
            Already have an account? <a href="index.php?page=login">Sign in</a>
          </p>
        </div>
      </div>

    <?php
    // =================================================================
    // PAGE: DASHBOARD
    // =================================================================
    elseif ($page === 'dashboard'):
      require_login();
      $totStudents = count_rows('students');
      $totEvents   = count_rows('events');
      $totRegs     = count_rows('registrations');
      
      // Fetch user's registered events if student
      $myEvents = [];
      if ($studentRecord) {
          $stmt = db()->prepare('SELECT r.registration_id, r.registration_date, r.status, e.event_id, e.event_name, e.event_date, e.venue, e.category 
                                FROM registrations r 
                                JOIN events e ON r.event_id = e.event_id 
                                WHERE r.student_id = ? 
                                ORDER BY e.event_date ASC');
          $stmt->execute([(int)$studentRecord['student_id']]);
          $myEvents = $stmt->fetchAll();
      }

      // If admin, fetch recent system registrations
      $recentRegistrations = [];
      if (($user['role'] ?? '') === 'admin') {
          $recentRegistrations = db()->query('SELECT r.registration_id, r.registration_date, r.status, s.full_name, s.email, e.event_name 
                                             FROM registrations r 
                                             JOIN students s ON r.student_id = s.student_id 
                                             JOIN events e ON r.event_id = e.event_id 
                                             ORDER BY r.registration_date DESC LIMIT 5')->fetchAll();
      }
    ?>
      <div style="margin-bottom: 28px;">
        <span class="eyebrow">Academic Workspace</span>
        <h1 style="font-size: 36px; margin-bottom: 6px;">Welcome back, <?=e($user['username'])?>.</h1>
        <p style="color:var(--text-muted);">
          You are authenticated with the <strong><?=e($user['role'])?></strong> role.
          <?php if ($studentRecord): ?>
            Enrolled in <strong><?=e($studentRecord['department'])?></strong> (Semester <?=e((string)$studentRecord['semester'])?>).
          <?php endif; ?>
        </p>
      </div>

      <!-- Quick Metrics Grid -->
      <div class="features-grid" style="margin-top: 0;">
        <div class="feature-box">
          <span class="feature-num">REGISTERED STUDENTS</span>
          <h3 style="font-size: 32px; color: var(--brand-cognac);"><?=$totStudents?></h3>
          <p>Total active academic profiles recorded in database.</p>
        </div>

        <div class="feature-box">
          <span class="feature-num">CAMPUS EVENTS</span>
          <h3 style="font-size: 32px; color: var(--brand-cognac);"><?=$totEvents?></h3>
          <p>Published workshops, festivals, and academic symposiums.</p>
        </div>

        <div class="feature-box">
          <span class="feature-num">TOTAL ENROLLMENTS</span>
          <h3 style="font-size: 32px; color: var(--brand-cognac);"><?=$totRegs?></h3>
          <p>All-time cumulative event enrollments across campus.</p>
        </div>

        <?php if ($studentRecord): ?>
          <div class="feature-box">
            <span class="feature-num">MY EVENT REGISTRATIONS</span>
            <h3 style="font-size: 32px; color: var(--brand-cognac);"><?=count($myEvents)?></h3>
            <p>Events you are currently confirmed to attend.</p>
          </div>
        <?php endif; ?>
      </div>

      <!-- Admin Specific Section -->
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <section class="card-panel" style="margin-top: 28px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
            <div>
              <span class="eyebrow">Administrative Controls</span>
              <h2 style="font-size: 24px; margin:0;">Quick Management Actions</h2>
            </div>
            <div style="display:flex; gap:10px;">
              <a class="btn btn-primary btn-sm" href="index.php?page=students">Manage Student Records</a>
              <a class="btn btn-secondary btn-sm" href="index.php?page=events">Create New Event</a>
              <a class="btn btn-outline btn-sm" href="index.php?export=students_csv">Export Students CSV</a>
            </div>
          </div>

          <h3 style="font-size: 18px; margin: 24px 0 12px;">Recent Campus Event Registrations</h3>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Reg ID</th>
                  <th>Student Name</th>
                  <th>Email</th>
                  <th>Event Name</th>
                  <th>Date Recorded</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recentRegistrations)): ?>
                  <tr><td colspan="6" style="text-align:center; padding: 20px; color:var(--text-muted);">No recent registrations.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentRegistrations as $rr): ?>
                    <tr>
                      <td>#<?=$rr['registration_id']?></td>
                      <td><strong><?=e($rr['full_name'])?></strong></td>
                      <td><?=e($rr['email'])?></td>
                      <td><?=e($rr['event_name'])?></td>
                      <td><?=date('d M Y, h:i A', strtotime($rr['registration_date']))?></td>
                      <td><span class="pill pill-success"><?=e($rr['status'])?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <!-- Student Enrolled Events Section -->
      <?php if ($studentRecord): ?>
        <section class="card-panel" style="margin-top: 28px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:20px;">
            <div>
              <span class="eyebrow">Personal Schedule</span>
              <h2 style="font-size: 24px; margin:0;">My Registered Campus Events</h2>
            </div>
            <a class="btn btn-secondary btn-sm" href="index.php?page=events">Explore More Events</a>
          </div>

          <?php if (empty($myEvents)): ?>
            <div style="text-align:center; padding:36px; border:1px dashed var(--border-strong); border-radius:var(--radius-md);">
              <p style="font-size:16px; color:var(--text-muted); margin-bottom:14px;">You have not registered for any upcoming events yet.</p>
              <a class="btn btn-primary btn-sm" href="index.php?page=events">Browse Event Calendar</a>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Event Title</th>
                    <th>Category</th>
                    <th>Scheduled Date</th>
                    <th>Venue</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($myEvents as $me): ?>
                    <tr>
                      <td><strong><?=e($me['event_name'])?></strong></td>
                      <td><span class="pill pill-amber"><?=e($me['category'])?></span></td>
                      <td><?=date('d M Y', strtotime($me['event_date']))?></td>
                      <td><?=e($me['venue'])?></td>
                      <td><span class="pill pill-success"><?=e($me['status'])?></span></td>
                      <td>
                        <form method="post" action="index.php?page=dashboard" onsubmit="return confirm('Cancel registration for this event?');" style="display:inline;">
                          <?=csrf_field()?>
                          <input type="hidden" name="action" value="cancel_registration">
                          <input type="hidden" name="registration_id" value="<?=$me['registration_id']?>">
                          <input type="hidden" name="redirect" value="dashboard">
                          <button class="btn btn-danger btn-sm" type="submit">Cancel</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

    <?php
    // =================================================================
    // PAGE: STUDENTS (Admin CRUD + Asynchronous AJAX Search & Filters)
    // =================================================================
    elseif ($page === 'students'):
      require_admin();
      $stmt = db()->query('SELECT student_id, user_id, full_name, email, department, semester, phone, created_at FROM students ORDER BY student_id DESC');
      $initialStudents = $stmt->fetchAll();
      $allDepts = array_values(array_unique(array_filter(array_column($initialStudents, 'department'))));
    ?>
      <div style="margin-bottom: 24px;">
        <span class="eyebrow">Institutional Records</span>
        <h1 style="font-size: 34px; margin-bottom: 6px;">Student Management Directory</h1>
        <p style="color:var(--text-muted);">
          Search, filter, update, or remove student profiles. Dynamic search updates asynchronously via the Fetch API.
        </p>
      </div>

      <!-- Action Toolbar (Search, Filter, Export, Add) -->
      <div class="toolbar-bar">
        <div class="search-input-group">
          <input class="form-control" type="search" id="studentSearch" placeholder="Search name, email, department or phone..." aria-label="Search students">
        </div>

        <div class="filter-group">
          <select class="form-control" id="deptFilter" style="width: auto;" aria-label="Filter by Department">
            <option value="">All Departments</option>
            <?php foreach ($allDepts as $d): ?>
              <option value="<?=e($d)?>"><?=e($d)?></option>
            <?php endforeach; ?>
          </select>

          <select class="form-control" id="semFilter" style="width: auto;" aria-label="Filter by Semester">
            <option value="">All Semesters</option>
            <?php for ($s=1; $s<=8; $s++): ?>
              <option value="<?=$s?>">Semester <?=$s?></option>
            <?php endfor; ?>
          </select>

          <select class="form-control" id="sortSelect" style="width: auto;" aria-label="Sort Order">
            <option value="id_desc">Sort: Newest First</option>
            <option value="id_asc">Sort: Oldest First</option>
            <option value="name_asc">Sort: Name (A-Z)</option>
            <option value="name_desc">Sort: Name (Z-A)</option>
          </select>

          <a class="btn btn-outline btn-sm" href="index.php?export=students_csv" title="Download spreadsheet of student records">📥 Export CSV</a>
        </div>
      </div>

      <!-- Active Counter Display -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; font-size:13px; color:var(--text-muted);">
        <span id="studentCount">Showing <?=count($initialStudents)?> students</span>
        <span>AJAX Live Filter Enabled</span>
      </div>

      <!-- Data Table -->
      <div class="table-responsive">
        <table class="data-table" id="studentsTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Department</th>
              <th>Semester</th>
              <th>Phone</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="studentTableBody">
            <?php if (empty($initialStudents)): ?>
              <tr><td colspan="7" style="text-align:center; padding: 24px; color:var(--text-muted);">No student records found.</td></tr>
            <?php else: ?>
              <?php foreach ($initialStudents as $st): ?>
                <tr>
                  <td style="font-weight:700; color:var(--brand-cognac);">#<?=$st['student_id']?></td>
                  <td style="font-weight:600;"><?=e($st['full_name'])?></td>
                  <td><?=e($st['email'])?></td>
                  <td><span class="pill pill-amber"><?=e($st['department'])?></span></td>
                  <td>Sem <?=$st['semester']?></td>
                  <td><?=e($st['phone'] ?? '—')?></td>
                  <td style="white-space:nowrap;">
                    <button class="btn btn-secondary btn-sm" type="button" onclick="openEditStudentModal(<?=e(json_encode($st))?>)">Edit</button>
                    <form method="post" action="index.php?page=students" style="display:inline;" onsubmit="return confirm('Permanently delete student <?=e($st['full_name'])?>?');">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="delete_student">
                      <input type="hidden" name="student_id" value="<?=$st['student_id']?>">
                      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Create New Student Section -->
      <section class="card-panel" style="margin-top: 36px;">
        <span class="eyebrow">Registry Entry</span>
        <h2 style="font-size: 22px; margin-bottom: 16px;">Enroll New Student</h2>

        <form method="post" action="index.php?page=students">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="add_student">

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="addFullName">Full Name *</label>
              <input class="form-control" type="text" id="addFullName" name="full_name" required placeholder="Student's official name">
            </div>

            <div class="form-group">
              <label class="form-label" for="addEmail">Institutional Email *</label>
              <input class="form-control" type="email" id="addEmail" name="email" required placeholder="student@example.edu">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="addDept">Department *</label>
              <input class="form-control" type="text" id="addDept" name="department" required placeholder="e.g. Computer Science">
            </div>

            <div class="form-group">
              <label class="form-label" for="addSem">Semester *</label>
              <input class="form-control" type="number" id="addSem" name="semester" min="1" max="12" value="1" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="addPhone">Contact Phone Number</label>
            <input class="form-control" type="tel" id="addPhone" name="phone" placeholder="+91 98765 00000">
          </div>

          <button class="btn btn-primary" type="submit">Add Student to Database</button>
        </form>
      </section>

      <!-- Edit Student Modal Dialog -->
      <div class="modal-overlay" id="editStudentModal" role="dialog" aria-modal="true" aria-labelledby="editStudentModalTitle">
        <div class="modal-box">
          <button class="modal-close" type="button" aria-label="Close dialog">&times;</button>
          <span class="eyebrow">Record Modification</span>
          <h2 id="editStudentModalTitle" style="font-size: 24px; margin-bottom: 20px;">Update Student Profile</h2>

          <form method="post" action="index.php?page=students" id="editStudentForm">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="edit_student">
            <input type="hidden" name="student_id" id="edit_student_id">

            <div class="form-group">
              <label class="form-label" for="edit_full_name">Full Name *</label>
              <input class="form-control" type="text" id="edit_full_name" name="full_name" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="edit_email">Email Address *</label>
              <input class="form-control" type="email" id="edit_email" name="email" required>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="edit_department">Department *</label>
                <input class="form-control" type="text" id="edit_department" name="department" required>
              </div>

              <div class="form-group">
                <label class="form-label" for="edit_semester">Semester *</label>
                <input class="form-control" type="number" id="edit_semester" name="semester" min="1" max="12" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="edit_phone">Contact Phone</label>
              <input class="form-control" type="tel" id="edit_phone" name="phone">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px;">
              <button class="btn btn-secondary modal-cancel" type="button">Cancel</button>
              <button class="btn btn-primary" type="submit">Save Changes</button>
            </div>
          </form>
        </div>
      </div>

    <?php
    // =================================================================
    // PAGE: EVENTS (Campus Calendar & Event Registration)
    // =================================================================
    elseif ($page === 'events'):
      $allEvents = db()->query('SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.event_id) as enrolled_count 
                               FROM events e 
                               ORDER BY e.event_date ASC')->fetchAll();

      $categories = array_values(array_unique(array_filter(array_column($allEvents, 'category'))));

      // Check registered events for logged-in student
      $registeredEventIds = [];
      if ($studentRecord) {
          $regStmt = db()->prepare('SELECT event_id, registration_id FROM registrations WHERE student_id = ?');
          $regStmt->execute([$studentRecord['student_id']]);
          $registeredEventIds = $regStmt->fetchAll(PDO::FETCH_KEY_PAIR); // event_id => registration_id
      }
    ?>
      <div style="margin-bottom: 24px;">
        <span class="eyebrow">Academic & Cultural Calendar</span>
        <h1 style="font-size: 34px; margin-bottom: 6px;">Campus Events & Workshops</h1>
        <p style="color:var(--text-muted);">
          Discover forthcoming hackathons, workshops, and symposiums. Enrolled students can register instantly.
        </p>
      </div>

      <!-- Events Filter Bar -->
      <div class="toolbar-bar">
        <div class="search-input-group">
          <input class="form-control" type="search" id="eventSearchInput" placeholder="Search event title or venue..." aria-label="Search events">
        </div>

        <div class="filter-group">
          <select class="form-control" id="eventCategoryFilter" style="width: auto;" aria-label="Filter Category">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?=e($cat)?>"><?=e($cat)?></option>
            <?php endforeach; ?>
          </select>

          <select class="form-control" id="eventDateSort" style="width: auto;" aria-label="Sort by Date">
            <option value="asc">Date: Earliest First</option>
            <option value="desc">Date: Latest First</option>
          </select>
        </div>
      </div>

      <!-- Event Cards Grid -->
      <div class="events-catalog" id="eventsContainer">
        <?php foreach ($allEvents as $ev): 
          $isRegistered = isset($registeredEventIds[$ev['event_id']]);
          $regId = $registeredEventIds[$ev['event_id']] ?? null;
          $seatsLeft = max(0, (int)$ev['max_seats'] - (int)$ev['enrolled_count']);
        ?>
          <article class="event-card" 
                   data-title="<?=e($ev['event_name'])?>" 
                   data-venue="<?=e($ev['venue'])?>" 
                   data-category="<?=e($ev['category'])?>" 
                   data-date="<?=e($ev['event_date'])?>">
            
            <div class="event-header">
              <span class="event-date-tag">📅 <?=date('d M Y', strtotime($ev['event_date']))?></span>
              <span class="pill pill-amber"><?=e($ev['category'])?></span>
            </div>

            <h2 class="event-title"><?=e($ev['event_name'])?></h2>
            <p class="event-desc"><?=e($ev['description'] ?: 'No detailed syllabus specified for this campus event.')?></p>

            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
              <div>📍 <strong>Venue:</strong> <?=e($ev['venue'])?></div>
              <div style="margin-top: 4px;">👥 <strong>Enrolled:</strong> <?=$ev['enrolled_count']?> / <?=$ev['max_seats']?> seats (<?=$seatsLeft?> available)</div>
            </div>

            <div class="event-meta">
              <?php if ($user && ($user['role'] ?? '') === 'student'): ?>
                <?php if ($isRegistered): ?>
                  <span class="pill pill-success" style="font-size: 13px;">✓ Confirmed</span>
                  <form method="post" action="index.php?page=events" style="display:inline;" onsubmit="return confirm('Cancel registration for this event?');">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="cancel_registration">
                    <input type="hidden" name="registration_id" value="<?=$regId?>">
                    <button class="btn btn-danger btn-sm" type="submit">Cancel</button>
                  </form>
                <?php else: ?>
                  <span>Open for Registration</span>
                  <form method="post" action="index.php?page=events">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="register_event">
                    <input type="hidden" name="event_id" value="<?=$ev['event_id']?>">
                    <button class="btn btn-primary btn-sm" type="submit">Register Now</button>
                  </form>
                <?php endif; ?>

              <?php elseif ($user && ($user['role'] ?? '') === 'admin'): ?>
                <div style="display:flex; gap:6px; flex-wrap:wrap; width:100%; justify-content:space-between; align-items:center;">
                  <button class="btn btn-secondary btn-sm" type="button" onclick="viewEventAttendees(<?=$ev['event_id']?>, '<?=e(addslashes($ev['event_name']))?>')">
                    Attendees (<?=$ev['enrolled_count']?>)
                  </button>
                  <div style="display:flex; gap:6px;">
                    <button class="btn btn-outline btn-sm" type="button" onclick="openEditEventModal({
                      id: <?=$ev['event_id']?>,
                      name: '<?=e(addslashes($ev['event_name']))?>',
                      category: '<?=e(addslashes($ev['category']))?>',
                      date: '<?=e($ev['event_date'])?>',
                      venue: '<?=e(addslashes($ev['venue']))?>',
                      seats: <?=$ev['max_seats']?>,
                      description: '<?=e(addslashes($ev['description'] ?? ''))?>'
                    })">Edit</button>

                    <form method="post" action="index.php?page=events" style="display:inline;" onsubmit="return confirm('Delete this event? All student registrations will be cleared.');">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="delete_event">
                      <input type="hidden" name="event_id" value="<?=$ev['event_id']?>">
                      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                    </form>
                  </div>
                </div>

              <?php else: ?>
                <span>Student login required</span>
                <a class="btn btn-secondary btn-sm" href="index.php?page=login">Sign In to Register</a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div id="eventsEmptyMsg" style="display:none; text-align:center; padding:48px; color:var(--text-muted);">
        <p style="font-size:18px;">No campus events match your selected filters.</p>
      </div>

      <!-- Admin: Create New Event Form -->
      <?php if ($user && ($user['role'] ?? '') === 'admin'): ?>
        <section class="card-panel" style="margin-top: 40px;">
          <span class="eyebrow">Academic Administration</span>
          <h2 style="font-size: 22px; margin-bottom: 18px;">Schedule New Campus Event</h2>

          <form method="post" action="index.php?page=events">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="add_event">

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="evName">Event Title *</label>
                <input class="form-control" type="text" id="evName" name="event_name" required placeholder="e.g. Distributed Cloud Computing Workshop">
              </div>

              <div class="form-group">
                <label class="form-label" for="evCat">Category *</label>
                <input class="form-control" type="text" id="evCat" name="category" required value="Technical Workshop">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="evDate">Scheduled Date *</label>
                <input class="form-control" type="date" id="evDate" name="event_date" required min="<?=date('Y-m-d')?>">
              </div>

              <div class="form-group">
                <label class="form-label" for="evVenue">Campus Venue *</label>
                <input class="form-control" type="text" id="evVenue" name="venue" required placeholder="e.g. Computing Lab 3">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="evSeats">Maximum Participant Capacity *</label>
                <input class="form-control" type="number" id="evSeats" name="max_seats" min="10" max="1000" value="60" required>
              </div>

              <div class="form-group">
                <label class="form-label" for="evDesc">Description / Abstract</label>
                <input class="form-control" type="text" id="evDesc" name="description" placeholder="Brief outline of event agenda">
              </div>
            </div>

            <button class="btn btn-primary" type="submit">Publish Campus Event</button>
          </form>
        </section>

        <!-- Admin: View Event Attendees Roster Modal -->
        <div class="modal-overlay" id="attendeeRosterModal" role="dialog" aria-modal="true" aria-labelledby="rosterTitleHeading">
          <div class="modal-box" style="max-width: 680px;">
            <button class="modal-close" type="button" aria-label="Close dialog">&times;</button>
            <span class="eyebrow">Roster Inspection</span>
            <h2 id="rosterTitleHeading" style="font-size: 22px; margin-bottom: 6px;">Event Attendee Roster</h2>
            <p style="color:var(--text-muted); margin-bottom: 16px;">
              Event: <strong id="rosterEventTitle">—</strong> <span id="rosterCount"></span>
            </p>

            <div class="table-responsive">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Student Details</th>
                    <th>Department</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="rosterTableBody">
                  <tr><td colspan="4" style="text-align:center; padding:20px;">Select an event to view attendees.</td></tr>
                </tbody>
              </table>
            </div>

            <div style="display:flex; justify-content:flex-end; margin-top:20px;">
              <button class="btn btn-secondary modal-cancel" type="button">Close Roster</button>
            </div>
          </div>
        </div>

        <!-- Admin: Edit Event Modal -->
        <div class="modal-overlay" id="editEventModal" role="dialog" aria-modal="true" aria-labelledby="editEvTitleHeading">
          <div class="modal-box">
            <button class="modal-close" type="button" aria-label="Close dialog">&times;</button>
            <span class="eyebrow">Calendar Update</span>
            <h2 id="editEvTitleHeading" style="font-size: 22px; margin-bottom: 18px;">Edit Event Details</h2>

            <form method="post" action="index.php?page=events" id="editEventForm">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="edit_event">
              <input type="hidden" name="event_id" id="edit_event_id">

              <div class="form-group">
                <label class="form-label" for="edit_event_name">Event Name *</label>
                <input class="form-control" type="text" id="edit_event_name" name="event_name" required>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="edit_event_category">Category *</label>
                  <input class="form-control" type="text" id="edit_event_category" name="category" required>
                </div>

                <div class="form-group">
                  <label class="form-label" for="edit_event_date">Date *</label>
                  <input class="form-control" type="date" id="edit_event_date" name="event_date" required>
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="edit_event_venue">Venue *</label>
                  <input class="form-control" type="text" id="edit_event_venue" name="venue" required>
                </div>

                <div class="form-group">
                  <label class="form-label" for="edit_event_seats">Capacity Seats *</label>
                  <input class="form-control" type="number" id="edit_event_seats" name="max_seats" required min="10">
                </div>
              </div>

              <div class="form-group">
                <label class="form-label" for="edit_event_desc">Description</label>
                <textarea class="form-control" id="edit_event_desc" name="description" rows="3"></textarea>
              </div>

              <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button class="btn btn-secondary modal-cancel" type="button">Cancel</button>
                <button class="btn btn-primary" type="submit">Update Event</button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

    <?php
    // =================================================================
    // PAGE: PROFILE (Student & Account Details)
    // =================================================================
    elseif ($page === 'profile'):
      require_login();
      $userStmt = db()->prepare('SELECT * FROM users WHERE user_id = ?');
      $userStmt->execute([$user['id']]);
      $fullUser = $userStmt->fetch();
    ?>
      <div style="margin-bottom: 24px;">
        <span class="eyebrow">Institutional Account</span>
        <h1 style="font-size: 34px; margin-bottom: 6px;">Your Profile & Security</h1>
        <p style="color:var(--text-muted);">Manage your personal student details and security credentials.</p>
      </div>

      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 28px;">
        <!-- Left: Academic Details -->
        <div class="card-panel">
          <span class="eyebrow">Registry Profile</span>
          <h2 style="font-size: 22px; margin-bottom: 18px;">Student Information</h2>

          <form method="post" action="index.php?page=profile">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="update_profile">

            <div class="form-group">
              <label class="form-label">Username</label>
              <input class="form-control" type="text" value="<?=e($fullUser['username'])?>" disabled style="opacity: 0.8;">
              <small style="color:var(--text-muted); font-size:11px;">Username is permanently tied to student institutional record.</small>
            </div>

            <div class="form-group">
              <label class="form-label">Email Address</label>
              <input class="form-control" type="email" value="<?=e($fullUser['email'])?>" disabled style="opacity: 0.8;">
            </div>

            <div class="form-group">
              <label class="form-label" for="profFullName">Full Legal Name *</label>
              <input class="form-control" type="text" id="profFullName" name="full_name" value="<?=e($studentRecord['full_name'] ?? $fullUser['username'])?>" required>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="profDept">Department *</label>
                <input class="form-control" type="text" id="profDept" name="department" value="<?=e($studentRecord['department'] ?? 'General Studies')?>" required>
              </div>

              <div class="form-group">
                <label class="form-label" for="profSem">Semester *</label>
                <input class="form-control" type="number" id="profSem" name="semester" min="1" max="12" value="<?=e((string)($studentRecord['semester'] ?? 1))?>" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="profPhone">Contact Phone</label>
              <input class="form-control" type="tel" id="profPhone" name="phone" value="<?=e($studentRecord['phone'] ?? '')?>">
            </div>

            <button class="btn btn-primary" type="submit">Update Information</button>
          </form>
        </div>

        <!-- Right: Security & Password Management -->
        <div class="card-panel">
          <span class="eyebrow">Credential Protection</span>
          <h2 style="font-size: 22px; margin-bottom: 18px;">Change Password</h2>

          <form method="post" action="index.php?page=profile">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
              <label class="form-label" for="currPass">Current Password *</label>
              <input class="form-control" type="password" id="currPass" name="current_password" required autocomplete="current-password" placeholder="••••••••">
            </div>

            <div class="form-group">
              <label class="form-label" for="newPass">New Password (Min 8 chars) *</label>
              <input class="form-control" type="password" id="newPass" name="new_password" required minlength="8" autocomplete="new-password" placeholder="••••••••">
            </div>

            <div class="form-group">
              <label class="form-label" for="confPass">Confirm New Password *</label>
              <input class="form-control" type="password" id="confPass" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="••••••••">
            </div>

            <button class="btn btn-secondary" type="submit">Update Password Hash</button>
          </form>

          <div style="margin-top: 28px; padding-top: 18px; border-top: 1px solid var(--border-subtle); font-size: 13px; color: var(--text-muted);">
            <div><strong>Security Metadata:</strong></div>
            <div>Account Created: <?=date('d M Y, h:i A', strtotime($fullUser['created_at']))?></div>
            <div>Role Authorization: <span class="pill pill-brown"><?=e($fullUser['role'])?></span></div>
            <div>Session Security: HttpOnly, SameSite=Lax, CSRF Protected</div>
          </div>
        </div>
      </div>

    <?php
    // =================================================================
    // PAGE: FAQ (Dedicated View)
    // =================================================================
    elseif ($page === 'faq'):
    ?>
      <div style="margin-bottom: 24px;">
        <span class="eyebrow">Help & Knowledge Base</span>
        <h1 style="font-size: 34px; margin-bottom: 6px;">Campus Support & Portal Guide</h1>
        <p style="color:var(--text-muted);">Common technical and operational questions regarding StudentHub Practical 15.</p>
      </div>

      <div class="faq-list" style="max-width: 100%;">
        <div class="faq-item active">
          <button class="faq-question" type="button" aria-expanded="true">
            <span>What are the key technical specifications of StudentHub Practical 15?</span>
            <span class="faq-chevron" aria-hidden="true">▼</span>
          </button>
          <div class="faq-answer">
            StudentHub is built with PHP 8, PDO with prepared statements, MySQL relational database with foreign key cascading, vanilla CSS with custom properties (classy beige & espresso palette, zero bootstrap blue defaults), and vanilla JavaScript with Fetch API AJAX endpoints.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" type="button" aria-expanded="false">
            <span>How does the student search functionality work?</span>
            <span class="faq-chevron" aria-hidden="true">▼</span>
          </button>
          <div class="faq-answer">
            The student directory features an asynchronous Fetch API request to <code>api/search_students.php</code>. Inputs are debounced by 250 milliseconds to minimize server strain, and query results return clean JSON. Table rows are populated dynamically using safe <code>textContent</code> to prevent DOM-based XSS attacks.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" type="button" aria-expanded="false">
            <span>What measures protect against SQL Injection and CSRF?</span>
            <span class="faq-chevron" aria-hidden="true">▼</span>
          </button>
          <div class="faq-answer">
            Every database interaction runs via parameterized PDO prepared statements where parameters are strictly bound. All state-changing POST actions require a valid cryptographic CSRF token generated via <code>bin2hex(random_bytes(32))</code> and checked via <code>hash_equals()</code>.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" type="button" aria-expanded="false">
            <span>How do I switch between Light and Dark themes?</span>
            <span class="faq-chevron" aria-hidden="true">▼</span>
          </button>
          <div class="faq-answer">
            Click the theme toggle in the upper right navigation (🌙 for dark mode, ☀️ for light mode). Both themes use tailored beige, cognac, and roasted espresso tones. Your selection is remembered via <code>localStorage</code>.
          </div>
        </div>
      </div>
    <?php endif; ?>

  </main>

  <!-- Site Footer -->
  <footer class="site-footer" role="contentinfo">
    <div class="footer-container">
      <div class="footer-brand">
        <span>🏛️ StudentHub</span>
        <span>·</span>
        <span>College Practical 15 Integration</span>
      </div>
      <div>
        <span>Environment: PHP <?=phpversion()?> · MySQL · XAMPP</span>
      </div>
      <div>
        <span>Ready for Examination Submission</span>
      </div>
    </div>
  </footer>

  <!-- Application Logic & AJAX Controller -->
  <script src="assets/app.js"></script>
</body>
</html>
