<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes.php';

header('Content-Type: application/json; charset=utf-8');

// Strictly enforce administrator access
require_admin();

try {
    $eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$eventId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid Event ID provided.']);
        exit;
    }

    $db = db();

    // Fetch event info
    $stmt = $db->prepare('SELECT event_id, event_name, venue, event_date FROM events WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Event not found.']);
        exit;
    }

    // Fetch registered students
    $stmt = $db->prepare('SELECT r.registration_id, r.registration_date, r.status, s.student_id, s.full_name, s.email, s.department, s.semester 
                          FROM registrations r 
                          JOIN students s ON r.student_id = s.student_id 
                          WHERE r.event_id = ? 
                          ORDER BY r.registration_date ASC');
    $stmt->execute([$eventId]);
    $attendees = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'event' => $event,
        'count' => count($attendees),
        'attendees' => $attendees
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    log_error($e);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to fetch attendee roster.']);
}
