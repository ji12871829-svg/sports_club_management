<?php
/**
 * Membership Renewal Helpers
 * 
 * Functions to handle automated membership renewal via Paystack recurring billing
 */

/**
 * Save Paystack authorization code for recurring billing
 * 
 * @param mysqli $conn Database connection
 * @param int $memberId Member ID
 * @param string $authorizationCode Paystack authorization code
 * @param string $customerCode Paystack customer code
 * @param string $lastFourDigits Last 4 digits of card
 * @param string $cardBrand Card brand (visa, mastercard, etc.)
 * @param int|null $paymentId Associated payment ID
 * @return array ['success' => bool, 'authorization_id' => int|null, 'error' => string|null]
 */
function save_paystack_authorization(
    mysqli $conn,
    int $memberId,
    string $authorizationCode,
    string $customerCode,
    string $lastFourDigits,
    string $cardBrand,
    ?int $paymentId = null
): array {
    if (!db_table_exists($conn, 'paystack_authorizations')) {
        return ['success' => false, 'error' => 'Authorizations table does not exist.'];
    }

    $sql = "
        INSERT INTO paystack_authorizations (
            member_id,
            authorization_code,
            customer_code,
            last_four_digits,
            card_brand,
            payment_id,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, 'Active')
        ON DUPLICATE KEY UPDATE
            customer_code = VALUES(customer_code),
            last_four_digits = VALUES(last_four_digits),
            card_brand = VALUES(card_brand),
            payment_id = VALUES(payment_id),
            status = 'Active',
            last_used_at = CURRENT_TIMESTAMP
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return ['success' => false, 'error' => 'Failed to prepare statement.'];
    }

    $stmt->bind_param(
        'issssi',
        $memberId,
        $authorizationCode,
        $customerCode,
        $lastFourDigits,
        $cardBrand,
        $paymentId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to save authorization.'];
    }

    $authId = $stmt->insert_id ?: null;
    $stmt->close();

    return ['success' => true, 'authorization_id' => $authId];
}

/**
 * Get active Paystack authorization for a member
 * 
 * @param mysqli $conn Database connection
 * @param int $memberId Member ID
 * @return array|null Authorization record or null if not found
 */
function get_paystack_authorization(mysqli $conn, int $memberId): ?array {
    if (!db_table_exists($conn, 'paystack_authorizations')) {
        return null;
    }

    $sql = "
        SELECT
            authorization_id,
            authorization_code,
            customer_code,
            last_four_digits,
            card_brand,
            status,
            last_used_at
        FROM paystack_authorizations
        WHERE member_id = ?
          AND status = 'Active'
        ORDER BY last_used_at DESC, created_at DESC
        LIMIT 1
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return null;
    }

    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    $auth = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $auth;
}

/**
 * Enable auto-renewal for a membership
 * 
 * @param mysqli $conn Database connection
 * @param int $membershipId Membership ID
 * @param bool $enable Enable or disable auto-renewal
 * @return bool Success status
 */
function set_membership_auto_renew(
    mysqli $conn,
    int $membershipId,
    bool $enable = true
): bool {
    if (!db_table_exists($conn, 'member_memberships')) {
        return false;
    }

    $sql = "
        UPDATE member_memberships
        SET auto_renew = ?
        WHERE membership_id = ?
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $enable_int = $enable ? 1 : 0;
    $stmt->bind_param('ii', $enable_int, $membershipId);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Get memberships expiring soon that have auto-renewal enabled.
 *
 * Each member's personal renewal_days_before preference (stored in renewal_settings)
 * is respected via a LEFT JOIN + COALESCE. Members without a preference row fall
 * back to $fallbackDays. The WHERE clause evaluates each member individually so a
 * member who set 14 days is picked up 14 days before expiry, not just 7.
 *
 * @param mysqli $conn        Database connection
 * @param int    $fallbackDays Fallback days when member has no preference (default: 7)
 * @return array Array of membership records ready for renewal
 */
function get_memberships_for_renewal(
    mysqli $conn,
    int $fallbackDays = 7
): array {
    if (
        !db_table_exists($conn, 'member_memberships') ||
        !db_table_exists($conn, 'membership_plans') ||
        !db_table_exists($conn, 'renewal_settings')
    ) {
        return [];
    }

    $sql = "
        SELECT
            mm.membership_id,
            mm.member_id,
            mm.plan_id,
            mm.end_date,
            mm.auto_renew,
            mm.renewal_reminder_sent,
            mp.name AS plan_name,
            mp.price,
            mp.duration_days,
            m.email,
            m.first_name,
            m.last_name,
            m.phone_number,
            m.payment_method,
            COALESCE(rs.renewal_days_before, ?) AS effective_days_before
        FROM member_memberships mm
        JOIN membership_plans mp ON mp.plan_id = mm.plan_id
        JOIN members m ON m.member_id = mm.member_id
        LEFT JOIN renewal_settings rs ON rs.member_id = mm.member_id
        WHERE mm.status = 'Active'
          AND mm.auto_renew = TRUE
          AND mm.end_date >= CURDATE()
          AND mm.end_date <= DATE_ADD(CURDATE(), INTERVAL COALESCE(rs.renewal_days_before, ?) DAY)
        ORDER BY mm.end_date ASC
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return [];
    }

    $stmt->bind_param('ii', $fallbackDays, $fallbackDays);
    $stmt->execute();
    $result = $stmt->get_result();
    $memberships = $result->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    return $memberships;
}

/**
 * Log a renewal attempt
 * 
 * @param mysqli $conn Database connection
 * @param int $memberId Member ID
 * @param int $membershipId Membership ID
 * @param int $planId Plan ID
 * @param string $status Status (Pending, Success, Failed)
 * @param float $amount Renewal amount
 * @param string|null $paystackReference Paystack transaction reference
 * @param string|null $errorMessage Error message if failed
 * @return array ['success' => bool, 'renewal_log_id' => int|null]
 */
function log_renewal_attempt(
    mysqli $conn,
    int $memberId,
    int $membershipId,
    int $planId,
    string $status,
    float $amount,
    ?string $paystackReference = null,
    ?string $errorMessage = null
): array {
    if (!db_table_exists($conn, 'membership_renewal_logs')) {
        return ['success' => false];
    }

    $renewalDate = date('Y-m-d');
    $completedAt = ($status === 'Success' || $status === 'Failed') ? date('Y-m-d H:i:s') : null;

    $sql = "
        INSERT INTO membership_renewal_logs (
            member_id,
            membership_id,
            plan_id,
            renewal_date,
            amount,
            status,
            paystack_reference,
            error_message,
            completed_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return ['success' => false];
    }

    $stmt->bind_param(
        'iiisdssss',
        $memberId,
        $membershipId,
        $planId,
        $renewalDate,
        $amount,
        $status,
        $paystackReference,
        $errorMessage,
        $completedAt
    );

    $success = $stmt->execute();
    $logId = $stmt->insert_id ?: null;
    $stmt->close();

    return ['success' => $success, 'renewal_log_id' => $logId];
}

/**
 * Update renewal log status
 * 
 * @param mysqli $conn Database connection
 * @param int $renewalLogId Renewal log ID
 * @param string $status New status
 * @param string|null $paystackReference Paystack reference if successful
 * @param string|null $errorMessage Error message if failed
 * @return bool Success status
 */
function update_renewal_log(
    mysqli $conn,
    int $renewalLogId,
    string $status,
    ?string $paystackReference = null,
    ?string $errorMessage = null
): bool {
    if (!db_table_exists($conn, 'membership_renewal_logs')) {
        return false;
    }

    $completedAt = ($status === 'Success' || $status === 'Failed') ? date('Y-m-d H:i:s') : null;

    $sql = "
        UPDATE membership_renewal_logs
        SET status = ?,
            paystack_reference = ?,
            error_message = ?,
            completed_at = ?
        WHERE renewal_log_id = ?
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $stmt->bind_param(
        'ssssi',
        $status,
        $paystackReference,
        $errorMessage,
        $completedAt,
        $renewalLogId
    );

    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Mark renewal reminder as sent
 * 
 * @param mysqli $conn Database connection
 * @param int $membershipId Membership ID
 * @return bool Success status
 */
function mark_renewal_reminder_sent(
    mysqli $conn,
    int $membershipId
): bool {
    if (!db_table_exists($conn, 'member_memberships')) {
        return false;
    }

    $sql = "
        UPDATE member_memberships
        SET renewal_reminder_sent = TRUE
        WHERE membership_id = ?
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $stmt->bind_param('i', $membershipId);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Get renewal settings for a member
 * 
 * @param mysqli $conn Database connection
 * @param int $memberId Member ID
 * @return array|null Renewal settings or null if not found
 */
function get_renewal_settings(
    mysqli $conn,
    int $memberId
): ?array {
    if (!db_table_exists($conn, 'renewal_settings')) {
        return null;
    }

    $sql = "
        SELECT
            setting_id,
            auto_renew_enabled,
            renewal_days_before,
            preferred_plan_id,
            updated_at
        FROM renewal_settings
        WHERE member_id = ?
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return null;
    }

    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $settings;
}

/**
 * Update or create renewal settings for a member
 * 
 * @param mysqli $conn Database connection
 * @param int $memberId Member ID
 * @param bool $autoRenewEnabled Enable auto-renewal
 * @param int $renewalDaysBefore Days before expiry to trigger renewal
 * @param int|null $preferredPlanId Preferred plan for renewal
 * @return array ['success' => bool, 'setting_id' => int|null]
 */
function upsert_renewal_settings(
    mysqli $conn,
    int $memberId,
    bool $autoRenewEnabled,
    int $renewalDaysBefore = 7,
    ?int $preferredPlanId = null
): array {
    if (!db_table_exists($conn, 'renewal_settings')) {
        return ['success' => false];
    }

    $autoRenewInt = $autoRenewEnabled ? 1 : 0;

    $sql = "
        INSERT INTO renewal_settings (
            member_id,
            auto_renew_enabled,
            renewal_days_before,
            preferred_plan_id
        )
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            auto_renew_enabled = VALUES(auto_renew_enabled),
            renewal_days_before = VALUES(renewal_days_before),
            preferred_plan_id = VALUES(preferred_plan_id),
            updated_at = CURRENT_TIMESTAMP
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return ['success' => false];
    }

    $stmt->bind_param(
        'iiii',
        $memberId,
        $autoRenewInt,
        $renewalDaysBefore,
        $preferredPlanId
    );

    $success = $stmt->execute();
    $settingId = $stmt->insert_id ?: null;
    $stmt->close();

    return ['success' => $success, 'setting_id' => $settingId];
}

/**
 * Reset the renewal_reminder_sent flag after a successful renewal or new activation.
 * This ensures the next renewal cycle can send fresh reminders for the new period.
 *
 * @param mysqli $conn        Database connection
 * @param int    $membershipId Membership ID to reset
 * @return bool Success status
 */
function reset_renewal_reminder_sent(
    mysqli $conn,
    int $membershipId
): bool {
    if (!db_table_exists($conn, 'member_memberships')) {
        return false;
    }

    $sql = "
        UPDATE member_memberships
        SET renewal_reminder_sent = FALSE
        WHERE membership_id = ?
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $stmt->bind_param('i', $membershipId);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Apply a grace period to a membership after a failed renewal charge.
 *
 * Extends end_date by $graceDays and sets status to 'Grace' so the member
 * retains access while resolving their payment issue rather than being cut off
 * immediately. Admin panels can filter on status = 'Grace' to follow up.
 *
 * @param mysqli $conn        Database connection
 * @param int    $membershipId Membership to extend
 * @param int    $graceDays   Days to extend (default: 3)
 * @return bool Success status
 */
function set_membership_grace_period(
    mysqli $conn,
    int $membershipId,
    int $graceDays = 3
): bool {
    if (!db_table_exists($conn, 'member_memberships')) {
        return false;
    }

    $graceDays = max(1, $graceDays);

    $sql = "
        UPDATE member_memberships
        SET end_date = DATE_ADD(end_date, INTERVAL ? DAY),
            status   = 'Grace'
        WHERE membership_id = ?
          AND status IN ('Active', 'Grace')
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $stmt->bind_param('ii', $graceDays, $membershipId);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}
