<?php

declare(strict_types=1);

require_once __DIR__ . '/send_email.php';

function email_campaign_audience_options(mysqli $conn): array
{
    $leagues = [];
    $teams = [];

    $sql = "SELECT league_id, name, season FROM leagues ORDER BY name, season";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $leagues[] = $row;
        }
        $result->free();
    }

    $sql = "SELECT t.team_id, t.name AS team_name, l.name AS league_name
            FROM teams t
            JOIN leagues l ON l.league_id = t.league_id
            ORDER BY l.name, t.name";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
        $result->free();
    }

    return ['leagues' => $leagues, 'teams' => $teams];
}

/**
 * @return array{success: bool, message: string, campaign_id?: int}
 */
function create_email_campaign(
    mysqli $conn,
    int $adminId,
    string $title,
    string $subject,
    string $messageHtml,
    string $audienceType,
    ?int $leagueId,
    ?int $teamId
): array {
    $allowedAudience = ['all_members', 'league', 'team'];
    if (!in_array($audienceType, $allowedAudience, true)) {
        return ['success' => false, 'message' => 'Invalid audience type selected.'];
    }

    if ($audienceType === 'league' && ($leagueId ?? 0) <= 0) {
        return ['success' => false, 'message' => 'Please select a league audience.'];
    }

    if ($audienceType === 'team' && ($teamId ?? 0) <= 0) {
        return ['success' => false, 'message' => 'Please select a team audience.'];
    }

    $title = trim($title);
    $subject = trim($subject);
    $messageHtml = trim($messageHtml);

    if ($title === '' || $subject === '' || $messageHtml === '') {
        return ['success' => false, 'message' => 'Title, subject, and message are required.'];
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO email_campaigns
                (created_by_admin_id, title, subject, message_html, audience_type, league_id, team_id, status, scheduled_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, 'Queued', NOW())"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare campaign insert.');
        }

        $safeLeagueId = $audienceType === 'league' ? $leagueId : null;
        $safeTeamId = $audienceType === 'team' ? $teamId : null;
        $stmt->bind_param(
            'issssii',
            $adminId,
            $title,
            $subject,
            $messageHtml,
            $audienceType,
            $safeLeagueId,
            $safeTeamId
        );
        $stmt->execute();
        $campaignId = (int) $stmt->insert_id;
        $stmt->close();

        $recipients = fetch_campaign_recipients($conn, $audienceType, $safeLeagueId, $safeTeamId);
        if (count($recipients) === 0) {
            throw new RuntimeException('No recipients found for the selected audience.');
        }

        $stmtRecipient = $conn->prepare(
            "INSERT INTO email_campaign_recipients (campaign_id, member_id, email, name, status)
             VALUES (?, ?, ?, ?, 'Queued')
             ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                name = VALUES(name),
                status = 'Queued',
                error_message = NULL,
                provider_message_id = NULL,
                sent_at = NULL"
        );
        if (!$stmtRecipient) {
            throw new RuntimeException('Could not prepare recipient insert.');
        }

        foreach ($recipients as $recipient) {
            $memberId = (int) $recipient['member_id'];
            $email = (string) $recipient['email'];
            $name = trim(((string) $recipient['first_name']) . ' ' . ((string) $recipient['last_name']));
            if ($name === '') {
                $name = 'Member';
            }
            $stmtRecipient->bind_param('iiss', $campaignId, $memberId, $email, $name);
            $stmtRecipient->execute();
        }
        $stmtRecipient->close();

        $stmt = $conn->prepare("UPDATE email_campaigns SET total_recipients = ? WHERE campaign_id = ?");
        $total = count($recipients);
        $stmt->bind_param('ii', $total, $campaignId);
        $stmt->execute();
        $stmt->close();

        create_admin_notification(
            $conn,
            null,
            'email_campaign_queued',
            'Broadcast queued',
            'Campaign "' . $title . '" queued for ' . $total . ' recipients.',
            ['campaign_id' => $campaignId]
        );

        $conn->commit();
        return [
            'success' => true,
            'message' => 'Campaign queued successfully for ' . $total . ' recipients.',
            'campaign_id' => $campaignId
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function fetch_campaign_recipients(mysqli $conn, string $audienceType, ?int $leagueId, ?int $teamId): array
{
    if ($audienceType === 'league') {
        $stmt = $conn->prepare(
            "SELECT DISTINCT m.member_id, m.first_name, m.last_name, m.email
             FROM members m
             JOIN team_memberships tm ON tm.member_id = m.member_id
             WHERE tm.status = 'Active' AND tm.league_id = ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $leagueId);
    } elseif ($audienceType === 'team') {
        $stmt = $conn->prepare(
            "SELECT DISTINCT m.member_id, m.first_name, m.last_name, m.email
             FROM members m
             JOIN team_memberships tm ON tm.member_id = m.member_id
             WHERE tm.status = 'Active' AND tm.team_id = ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $teamId);
    } else {
        $stmt = $conn->prepare(
            "SELECT m.member_id, m.first_name, m.last_name, m.email
             FROM members m
             WHERE m.email IS NOT NULL AND m.email <> ''"
        );
        if (!$stmt) {
            return [];
        }
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function create_admin_notification(
    mysqli $conn,
    ?int $adminId,
    string $eventKey,
    string $title,
    string $message,
    ?array $payload = null
): void {
    $payloadJson = $payload ? json_encode($payload) : null;
    $stmt = $conn->prepare(
        "INSERT INTO admin_notifications (admin_id, event_key, title, message, payload_json)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('issss', $adminId, $eventKey, $title, $message, $payloadJson);
    $stmt->execute();
    $stmt->close();
}

function fetch_recent_campaigns(mysqli $conn, int $limit = 12): array
{
    $stmt = $conn->prepare(
        "SELECT c.campaign_id, c.title, c.subject, c.audience_type, c.status,
                c.total_recipients, c.sent_count, c.failed_count, c.created_at,
                a.email AS admin_email
         FROM email_campaigns c
         JOIN admins a ON a.admin_id = c.created_by_admin_id
         ORDER BY c.campaign_id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * @return array{processed: int, sent: int, failed: int}
 */
function process_queued_campaigns(mysqli $conn, int $maxCampaigns = 3, int $maxRecipientsPerCampaign = 100): array
{
    $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0];

    $stmtCampaigns = $conn->prepare(
        "SELECT campaign_id, title, subject, message_html
         FROM email_campaigns
         WHERE status IN ('Queued', 'Sending')
           AND (scheduled_at IS NULL OR scheduled_at <= NOW())
         ORDER BY campaign_id ASC
         LIMIT ?"
    );
    if (!$stmtCampaigns) {
        return $summary;
    }
    $stmtCampaigns->bind_param('i', $maxCampaigns);
    $stmtCampaigns->execute();
    $campaigns = $stmtCampaigns->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtCampaigns->close();

    foreach ($campaigns as $campaign) {
        $campaignId = (int) $campaign['campaign_id'];
        mark_campaign_started($conn, $campaignId);

        $stmtRecipients = $conn->prepare(
            "SELECT recipient_id, email, name
             FROM email_campaign_recipients
             WHERE campaign_id = ? AND status = 'Queued'
             ORDER BY recipient_id ASC
             LIMIT ?"
        );
        if (!$stmtRecipients) {
            continue;
        }
        $stmtRecipients->bind_param('ii', $campaignId, $maxRecipientsPerCampaign);
        $stmtRecipients->execute();
        $recipients = $stmtRecipients->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtRecipients->close();

        foreach ($recipients as $recipient) {
            $result = sendEmailDetailed(
                (string) $recipient['email'],
                (string) $recipient['name'],
                (string) $campaign['subject'],
                (string) $campaign['message_html']
            );

            $recipientId = (int) $recipient['recipient_id'];
            if ($result['success']) {
                update_campaign_recipient_status($conn, $recipientId, 'Sent', null);
                $summary['sent']++;
            } else {
                $error = trim($result['error']);
                if ($error === '') {
                    $error = 'HTTP ' . $result['http_code'];
                }
                update_campaign_recipient_status($conn, $recipientId, 'Failed', $error);
                $summary['failed']++;
            }
        }

        sync_campaign_counts($conn, $campaignId);
        finalize_campaign_if_done($conn, $campaignId, (string) $campaign['title']);
        $summary['processed']++;
    }

    return $summary;
}

function mark_campaign_started(mysqli $conn, int $campaignId): void
{
    $stmt = $conn->prepare(
        "UPDATE email_campaigns
         SET status = 'Sending',
             started_at = COALESCE(started_at, NOW())
         WHERE campaign_id = ?"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $stmt->close();
}

function update_campaign_recipient_status(mysqli $conn, int $recipientId, string $status, ?string $error): void
{
    $sentAt = $status === 'Sent' ? date('Y-m-d H:i:s') : null;
    $stmt = $conn->prepare(
        "UPDATE email_campaign_recipients
         SET status = ?, error_message = ?, sent_at = ?
         WHERE recipient_id = ?"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sssi', $status, $error, $sentAt, $recipientId);
    $stmt->execute();
    $stmt->close();
}

function sync_campaign_counts(mysqli $conn, int $campaignId): void
{
    $stmt = $conn->prepare(
        "UPDATE email_campaigns c
         JOIN (
             SELECT
                 campaign_id,
                 SUM(CASE WHEN status = 'Sent' THEN 1 ELSE 0 END) AS sent_count,
                 SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) AS failed_count
             FROM email_campaign_recipients
             WHERE campaign_id = ?
             GROUP BY campaign_id
         ) x ON x.campaign_id = c.campaign_id
         SET c.sent_count = x.sent_count,
             c.failed_count = x.failed_count
         WHERE c.campaign_id = ?"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ii', $campaignId, $campaignId);
    $stmt->execute();
    $stmt->close();
}

function finalize_campaign_if_done(mysqli $conn, int $campaignId, string $title): void
{
    $stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN status = 'Queued' THEN 1 ELSE 0 END) AS queued_count,
            SUM(CASE WHEN status = 'Sent' THEN 1 ELSE 0 END) AS sent_count,
            SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) AS failed_count
         FROM email_campaign_recipients
         WHERE campaign_id = ?"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $queued = (int) ($stats['queued_count'] ?? 0);
    $sent = (int) ($stats['sent_count'] ?? 0);
    $failed = (int) ($stats['failed_count'] ?? 0);

    if ($queued > 0) {
        return;
    }

    $status = ($failed > 0 && $sent === 0) ? 'Failed' : 'Completed';
    $stmt = $conn->prepare(
        "UPDATE email_campaigns
         SET status = ?, completed_at = NOW(), sent_count = ?, failed_count = ?
         WHERE campaign_id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('siii', $status, $sent, $failed, $campaignId);
        $stmt->execute();
        $stmt->close();
    }

    create_admin_notification(
        $conn,
        null,
        'email_campaign_finished',
        'Broadcast finished',
        'Campaign "' . $title . '" finished. Sent: ' . $sent . ', failed: ' . $failed . '.',
        ['campaign_id' => $campaignId, 'sent' => $sent, 'failed' => $failed]
    );
}
