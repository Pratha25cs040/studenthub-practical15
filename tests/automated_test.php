<?php
/**
 * StudentHub — Practical 15 Automated Functional & Security Test Suite
 * Tests all components directly against the live database and application logic.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes.php';

echo "======================================================================\n";
echo " STUDENTHUB PRACTICAL 15: AUTOMATED TEST SUITE\n";
echo " Execution Time: " . date('Y-m-d H:i:s') . "\n";
echo " PHP Version: " . phpversion() . "\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $caseId, string $description, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$caseId}: {$description}" . ($detail ? " — {$detail}" : '') . "\n";
    } else {
        $failCount++;
        echo "[FAIL] {$caseId}: {$description}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ---------------------------------------------------------------------
// TEST 1: Database Connection & Schema Verification
// ---------------------------------------------------------------------
try {
    $pdo = db();
    assertTest('T01', 'Database connectivity via PDO', $pdo instanceof PDO, 'Connected to MySQL studenthub');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $requiredTables = ['users', 'students', 'events', 'registrations'];
    $diff = array_diff($requiredTables, $tables);
    assertTest('T02', 'All core tables exist in database', count($diff) === 0, 'Found: ' . implode(', ', $tables));
} catch (Throwable $e) {
    assertTest('T01', 'Database connectivity via PDO', false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 2: Seed Accounts and Authentication Verification
// ---------------------------------------------------------------------
try {
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute(['admin']);
    $admin = $stmt->fetch();
    assertTest('T03', 'Default admin account exists', $admin && $admin['role'] === 'admin');

    $validPass = password_verify('password', $admin['password_hash']);
    assertTest('T04', 'Bcrypt password hashing verification', $validPass, 'Password verification successful');

    $stmt->execute(['aarav']);
    $studentUser = $stmt->fetch();
    assertTest('T05', 'Demo student user account exists', $studentUser && $studentUser['role'] === 'student');
} catch (Throwable $e) {
    assertTest('T03', 'Authentication tests', false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 3: Relational Foreign Key Integrity
// ---------------------------------------------------------------------
try {
    $stmt = db()->query('SELECT s.full_name, u.username, u.role FROM students s JOIN users u ON s.user_id = u.user_id WHERE u.username = "aarav"');
    $linked = $stmt->fetch();
    assertTest('T06', 'Foreign key link between students and users', !empty($linked['username']), 'Linked to: ' . ($linked['full_name'] ?? 'None'));

    $regs = db()->query('SELECT COUNT(*) FROM registrations r JOIN students s ON r.student_id = s.student_id JOIN events e ON r.event_id = e.event_id')->fetchColumn();
    assertTest('T07', 'Relational join between students, events and registrations', (int)$regs > 0, "Active registrations: {$regs}");
} catch (Throwable $e) {
    assertTest('T06', 'Relational integrity tests', false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 4: Student CRUD Operations
// ---------------------------------------------------------------------
try {
    $testEmail = 'test_student_' . time() . '@example.edu';
    // Create
    $stmt = db()->prepare('INSERT INTO students(full_name, email, department, semester, phone) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['Automated Test Student', $testEmail, 'Computer Science', 4, '+91 99999 11111']);
    $newStudentId = (int)db()->lastInsertId();
    assertTest('T08', 'Student Record Creation (CRUD - Create)', $newStudentId > 0, "Created ID #{$newStudentId}");

    // Read
    $stmt = db()->prepare('SELECT * FROM students WHERE student_id = ?');
    $stmt->execute([$newStudentId]);
    $created = $stmt->fetch();
    assertTest('T09', 'Student Record Retrieval (CRUD - Read)', $created && $created['email'] === $testEmail);

    // Update
    $stmt = db()->prepare('UPDATE students SET department = ?, semester = ? WHERE student_id = ?');
    $stmt->execute(['Data Science', 5, $newStudentId]);
    $stmt = db()->prepare('SELECT department, semester FROM students WHERE student_id = ?');
    $stmt->execute([$newStudentId]);
    $updated = $stmt->fetch();
    assertTest('T10', 'Student Record Update (CRUD - Update)', $updated['department'] === 'Data Science' && (int)$updated['semester'] === 5);

    // Delete
    $stmt = db()->prepare('DELETE FROM students WHERE student_id = ?');
    $stmt->execute([$newStudentId]);
    $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE student_id = ?');
    $stmt->execute([$newStudentId]);
    assertTest('T11', 'Student Record Deletion (CRUD - Delete)', (int)$stmt->fetchColumn() === 0, 'Cleanly removed');
} catch (Throwable $e) {
    assertTest('T08', 'Student CRUD operations', false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 5: Event CRUD & Attendee Roster
// ---------------------------------------------------------------------
try {
    // Create Event
    $stmt = db()->prepare('INSERT INTO events(event_name, category, event_date, venue, max_seats, description) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute(['Test Quantum Symposium', 'Special Workshop', '2026-12-25', 'Lab Q', 50, 'Automated test symposium']);
    $testEventId = (int)db()->lastInsertId();
    assertTest('T12', 'Event Creation (CRUD - Create)', $testEventId > 0, "Created Event ID #{$testEventId}");

    // Register Student for Event
    $stmt = db()->prepare('INSERT INTO registrations(student_id, event_id, status) VALUES (1, ?, "confirmed")');
    $stmt->execute([$testEventId]);
    $regId = (int)db()->lastInsertId();
    assertTest('T13', 'Event Enrollment Registration', $regId > 0, "Created Registration ID #{$regId}");

    // Test Duplicate Registration Prevention (Unique constraint check)
    $duplicateCaught = false;
    try {
        $stmt = db()->prepare('INSERT INTO registrations(student_id, event_id, status) VALUES (1, ?, "confirmed")');
        $stmt->execute([$testEventId]);
    } catch (PDOException $pe) {
        $duplicateCaught = true;
    }
    assertTest('T14', 'Duplicate Event Registration Constraint', $duplicateCaught, 'Prevented double enrollment via UNIQUE constraint');

    // Clean up event (Cascading delete will clean up registration)
    $stmt = db()->prepare('DELETE FROM events WHERE event_id = ?');
    $stmt->execute([$testEventId]);
    $stmt = db()->prepare('SELECT COUNT(*) FROM registrations WHERE registration_id = ?');
    $stmt->execute([$regId]);
    assertTest('T15', 'Cascading Foreign Key Cleanup on Event Delete', (int)$stmt->fetchColumn() === 0, 'Registration row automatically cascaded');
} catch (Throwable $e) {
    assertTest('T12', 'Event CRUD operations', false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 6: Asynchronous Search Query Logic
// ---------------------------------------------------------------------
try {
    $term = 'Aarav';
    $stmt = db()->prepare('SELECT full_name, department FROM students WHERE full_name LIKE ?');
    $stmt->execute(['%' . $term . '%']);
    $results = $stmt->fetchAll();
    assertTest('T16', 'AJAX Student Search Query Logic', count($results) > 0 && str_contains($results[0]['full_name'], 'Aarav'), "Matched: " . ($results[0]['full_name'] ?? ''));

    // Department filter test
    $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE department = ?');
    $stmt->execute(['Computer Science']);
    $csCount = (int)$stmt->fetchColumn();
    assertTest('T17', 'Department Filter Query Logic', $csCount >= 1, "Computer Science count: {$csCount}");
} catch (Throwable $e) {
    assertTest('T16', 'Search Query tests', false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 7: Security Defenses & Escaping Verification
// ---------------------------------------------------------------------
$rawXss = '<script>alert("XSS")</script>';
$escaped = e($rawXss);
assertTest('T18', 'HTML Output Escaping Helper', !str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;'), 'XSS sanitized to HTML entities');

assertTest('T19', 'CSRF Token Generation in Session', !empty($_SESSION['csrf']) && strlen($_SESSION['csrf']) === 64, 'Token length: 64 hex chars');

echo "\n======================================================================\n";
echo " TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount === 0) {
    echo "\nAll automated functional and security tests passed successfully!\n";
}
