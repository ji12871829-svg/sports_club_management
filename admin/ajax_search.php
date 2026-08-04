<?php
// admin/ajax_search.php — Quick Search endpoint for the admin header
// Returns JSON results for members, bookings, and fixtures matching the query.

require_once '../config/db_connect.php';
require_once '../includes/admin_auth.php';

header('Content-Type: application/json');

if (!isset($_GET['q']) || trim($_GET['q']) === '') {
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q']);
$search = '%' . $conn->real_escape_string($q) . '%';
$limit = max(1, min(20, (int)($_GET['limit'] ?? 8)));

$results = [];

// ── 1. Search members ────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT member_id, first_name, last_name, email, phone_number, 'member' AS type
    FROM members
    WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?
    LIMIT ?
");
if ($stmt) {
    $stmt->bind_param('ssssi', $search, $search, $search, $search, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $results[] = [
            'type'       => 'Member',
            'icon'       => 'fa-id-badge',
            'url'        => 'manage_members.php?view=' . $r['member_id'],
            'title'      => $r['first_name'] . ' ' . $r['last_name'],
            'subtitle'   => $r['email'] ?: $r['phone_number'] ?: '',
        ];
    }
    $stmt->close();
}

// ── 2. Search coaches ────────────────────────────────────────────────────
if (count($results) < $limit) {
    $rem = $limit - count($results);
    $stmt = $conn->prepare("
        SELECT coach_id, first_name, last_name, specialization, 'coach' AS type
        FROM coaches
        WHERE first_name LIKE ? OR last_name LIKE ? OR specialization LIKE ?
        LIMIT ?
    ");
    if ($stmt) {
        $stmt->bind_param('sssi', $search, $search, $search, $rem);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $results[] = [
                'type'     => 'Coach',
                'icon'     => 'fa-whistle',
                'url'      => 'manage_coaches.php?view=' . $r['coach_id'],
                'title'    => $r['first_name'] . ' ' . $r['last_name'],
                'subtitle' => $r['specialization'] ?? '',
            ];
        }
        $stmt->close();
    }
}

// ── 3. Search fixtures ──────────────────────────────────────────────────
if (count($results) < $limit * 2 && strlen($q) >= 2) {
    $stmt = $conn->prepare("
        SELECT f.fixture_id, f.match_date, ht.name AS home, at.name AS away, l.name AS league
        FROM fixtures f
        JOIN teams ht ON ht.team_id = f.home_team_id
        JOIN teams at ON at.team_id = f.away_team_id
        JOIN leagues l ON l.league_id = f.league_id
        WHERE ht.name LIKE ? OR at.name LIKE ? OR l.name LIKE ?
        ORDER BY f.match_date DESC
        LIMIT 5
    ");
    if ($stmt) {
        $stmt->bind_param('sss', $search, $search, $search);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $results[] = [
                'type'     => 'Fixture',
                'icon'     => 'fa-calendar-alt',
                'url'      => 'manage_fixtures.php?edit=' . $r['fixture_id'],
                'title'    => $r['home'] . ' vs ' . $r['away'],
                'subtitle' => $r['match_date'] . ' · ' . $r['league'],
            ];
        }
        $stmt->close();
    }
}

// ── 4. Search bookings ───────────────────────────────────────────────────
if (count($results) < $limit * 2 && strlen($q) >= 2) {
    $stmt = $conn->prepare("
        SELECT b.booking_id, b.booking_date, b.status, m.first_name, m.last_name
        FROM bookings b
        JOIN members m ON m.member_id = b.member_id
        WHERE m.first_name LIKE ? OR m.last_name LIKE ? OR b.status LIKE ? OR b.booking_date LIKE ?
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    if ($stmt) {
        $stmt->bind_param('ssss', $search, $search, $search, $search);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $results[] = [
                'type'     => 'Booking',
                'icon'     => 'fa-calendar-check',
                'url'      => 'manage_bookings.php?view=' . $r['booking_id'],
                'title'    => $r['first_name'] . ' ' . $r['last_name'],
                'subtitle' => $r['booking_date'] . ' · ' . ucfirst($r['status']),
            ];
        }
        $stmt->close();
    }
}

echo json_encode(['results' => $results]);
$conn->close();
