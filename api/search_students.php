<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes.php';

header('Content-Type: application/json; charset=utf-8');

// Strictly enforce administrator access on the server side
require_admin();

try {
    $term = trim((string)($_GET['q'] ?? ''));
    $dept = trim((string)($_GET['department'] ?? ''));
    $semester = filter_var($_GET['semester'] ?? null, FILTER_VALIDATE_INT);
    $sort = (string)($_GET['sort'] ?? 'id_desc');

    $sql = 'SELECT student_id, user_id, full_name, email, department, semester, phone, created_at 
            FROM students 
            WHERE 1=1';
    $params = [];

    if ($term !== '') {
        $sql .= ' AND (full_name LIKE :q1 OR email LIKE :q2 OR department LIKE :q3 OR phone LIKE :q4)';
        $searchWildcard = '%' . $term . '%';
        $params[':q1'] = $searchWildcard;
        $params[':q2'] = $searchWildcard;
        $params[':q3'] = $searchWildcard;
        $params[':q4'] = $searchWildcard;
    }

    if ($dept !== '') {
        $sql .= ' AND department = :department';
        $params[':department'] = $dept;
    }

    if ($semester !== false && $semester !== null && $semester > 0) {
        $sql .= ' AND semester = :semester';
        $params[':semester'] = $semester;
    }

    // Safe sorting map
    $sortOrders = [
        'id_desc'    => 'student_id DESC',
        'id_asc'     => 'student_id ASC',
        'name_asc'   => 'full_name ASC',
        'name_desc'  => 'full_name DESC',
        'dept_asc'   => 'department ASC, full_name ASC',
        'sem_asc'    => 'semester ASC, full_name ASC'
    ];
    $orderBy = $sortOrders[$sort] ?? 'student_id DESC';
    $sql .= " ORDER BY $orderBy LIMIT 200";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'count' => count($students),
        'data' => $students
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    log_error($e);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to complete search request. Please check server logs.'
    ]);
}
