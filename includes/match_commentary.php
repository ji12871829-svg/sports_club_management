<?php

function commentary_table_ready(mysqli $conn): bool
{
    $r = $conn->query("SHOW TABLES LIKE 'match_commentary'");
    return $r && $r->num_rows > 0;
}

function commentary_fetch_by_fixture(mysqli $conn, int $fixtureId): array
{
    if (!commentary_table_ready($conn) || $fixtureId <= 0) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT commentary_id, minute, text, created_at
         FROM match_commentary
         WHERE fixture_id = ?
         ORDER BY COALESCE(minute, 999), created_at ASC"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $fixtureId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function commentary_add(mysqli $conn, int $fixtureId, string $text, ?int $minute = null): bool
{
    if (!commentary_table_ready($conn) || $fixtureId <= 0) {
        return false;
    }
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    $stmt = $conn->prepare(
        "INSERT INTO match_commentary (fixture_id, minute, text) VALUES (?, ?, ?)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iis', $fixtureId, $minute, $text);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function commentary_delete(mysqli $conn, int $commentaryId): bool
{
    if (!commentary_table_ready($conn) || $commentaryId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("DELETE FROM match_commentary WHERE commentary_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $commentaryId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
