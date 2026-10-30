<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Configure and start secure session
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * Escape HTML output safely using htmlspecialchars.
 */
function e(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return hidden CSRF input field.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

/**
 * Helper to determine whether the incoming request is an API / AJAX call.
 */
function is_api_request(): bool {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $acceptJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    return $ajax || $acceptJson || str_contains($uri, '/api/');
}

/**
 * Resolve relative base URL to StudentHub root folder.
 */
function studenthub_root_url(): string {
    $scriptDir = dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_ends_with($scriptDir, '/api') || str_ends_with($scriptDir, '\\api')) {
        $scriptDir = dirname($scriptDir);
    }
    $clean = rtrim(str_replace('\\', '/', $scriptDir), '/');
    return $clean === '' ? '/' : $clean . '/';
}

/**
 * Verify CSRF token from POST body or HTTP header.
 */
function verify_csrf(): void {
    $token = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    if ($token === '' || !hash_equals($sessionToken, $token)) {
        if (is_api_request()) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'CSRF security token expired. Please refresh the page.']);
            exit;
        }
        http_response_code(419);
        exit('Security token expired or invalid. Please refresh the page and try again.');
    }
}

/**
 * Retrieve currently authenticated user array or null.
 */
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Retrieve linked student record for the logged-in user, if any.
 */
function current_student_record(): ?array {
    $u = current_user();
    if (!$u) return null;
    try {
        $stmt = db()->prepare('SELECT * FROM students WHERE user_id = ? LIMIT 1');
        $stmt->execute([(int)$u['id']]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        log_error($e);
        return null;
    }
}

/**
 * Require user to be logged in; returns 401 for API, redirects for web pages.
 */
function require_login(): void {
    if (!current_user()) {
        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Authentication required. Please sign in.']);
            exit;
        }
        flash('Please sign in to access that page.', 'info');
        header('Location: ' . studenthub_root_url() . 'index.php?page=login');
        exit;
    }
}

/**
 * Require administrator role; returns 403 Forbidden if unauthorized.
 */
function require_admin(): void {
    require_login();
    $u = current_user();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        if (is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Access denied: Administrator privileges required.']);
            exit;
        }
        $homeUrl = studenthub_root_url() . 'index.php';
        exit('<!doctype html><html><head><title>403 Forbidden</title><style>body{background:#F7F3EC;color:#271A13;font-family:sans-serif;padding:40px;text-align:center;}</style></head><body><h1>403 — Access Denied</h1><p>Administrator privileges are required to access this resource.</p><p><a href="' . htmlspecialchars($homeUrl) . '">Return to StudentHub</a></p></body></html>');
    }
}

/**
 * Set a session flash message.
 */
function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = [$message, $type];
}

/**
 * Retrieve and clear session flash message.
 */
function take_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Safely log error messages to server error log without leaking credentials.
 */
function log_error(Throwable $e): void {
    error_log('[StudentHub] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

/**
 * Helper to count rows safely in allowed tables.
 */
function count_rows(string $table): int {
    $allowed = ['students', 'events', 'registrations', 'users'];
    if (!in_array($table, $allowed, true)) return 0;
    try {
        return (int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Throwable $e) {
        log_error($e);
        return 0;
    }
}
