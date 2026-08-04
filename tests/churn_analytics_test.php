<?php
/**
 * tests/churn_analytics_test.php
 * CLI test suite for ChurnWellnessAnalytics (deterministic, no AI required).
 *
 * Run:  php tests/churn_analytics_test.php
 * Exit code 0 = all pass, 1 = at least one failure.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/churn_wellness_analytics.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "ChurnWellnessAnalytics CLI tests\n";
echo str_repeat('-', 48) . "\n";

$conn->set_charset('utf8mb4');
$analytics = new ChurnWellnessAnalytics($conn);

// 1. Member exists in DB?
$r = $conn->query('SELECT member_id FROM members ORDER BY member_id LIMIT 1');
$member = $r ? $r->fetch_assoc() : null;
check('A member exists in the database', (bool) $member, 'no members table rows');
if (!$member) { exit(1); }
$mid = (int) $member['member_id'];

// 2. analyzeMemberChurnRisk returns well-formed, deterministic output
$analysis = $analytics->analyzeMemberChurnRisk($mid);
check('analyzeMemberChurnRisk returns array', is_array($analysis));
if (is_array($analysis)) {
    check('risk_score is int 0-100', isset($analysis['risk_score']) && is_numeric($analysis['risk_score'])
        && $analysis['risk_score'] >= 0 && $analysis['risk_score'] <= 100, (string) ($analysis['risk_score'] ?? 'null'));
    check('risk_level is one of low/medium/high/critical',
        isset($analysis['risk_level']) && in_array($analysis['risk_level'], ['low', 'medium', 'high', 'critical'], true),
        (string) ($analysis['risk_level'] ?? 'null'));
    check('risk_factors is array', isset($analysis['risk_factors']) && is_array($analysis['risk_factors']));
    check('engagement_score is numeric', isset($analysis['engagement_score']) && is_numeric($analysis['engagement_score']));
    check('booking_trend is one of declining/flat/improving',
        isset($analysis['booking_trend']) && in_array($analysis['booking_trend'], ['declining', 'flat', 'improving'], true),
        (string) ($analysis['booking_trend'] ?? 'null'));
}

// 3. Deterministic: same input twice => same score (no randomness)
$analysisB = $analytics->analyzeMemberChurnRisk($mid);
check('deterministic — repeat run identical score',
    isset($analysis['risk_score'], $analysisB['risk_score']) && $analysis['risk_score'] === $analysisB['risk_score'],
    ($analysis['risk_score'] ?? 'null') . ' vs ' . ($analysisB['risk_score'] ?? 'null'));

// 4. recommendRetentionActions is deterministic and rule-based (no AI call)
$actions = $analytics->recommendRetentionActions($mid);
check('recommendRetentionActions returns array', is_array($actions));
if (is_array($actions)) {
    check('retention actions non-empty strings', count($actions) > 0 && array_reduce($actions, fn($c, $a) => $c && is_string($a) && $a !== '', true));
    $r2 = $conn->query("SELECT retention_actions_taken FROM member_churn_risk WHERE member_id = $mid");
    $row = $r2 ? $r2->fetch_assoc() : null;
    check('retention_actions_taken persisted to DB', $row && !empty($row['retention_actions_taken']));
}

// 5. getHighRiskMembers returns array of rows
$high = $analytics->getHighRiskMembers(10);
check('getHighRiskMembers returns array', is_array($high));
if (is_array($high)) {
    check('high-risk rows well-formed', array_reduce($high, fn($c, $h) => $c && isset($h['member_id'], $h['risk_score']), true));
}

// 6. Wellness tracking: log + read back (cleanup after)
$t = $analytics->logWellnessTracking($mid, 40, 20, 'minor_injury', 'test entry');
check('logWellnessTracking returns true', $t === true);
$hist = $analytics->getMemberWellnessHistory($mid, 30);
check('getMemberWellnessHistory returns array', is_array($hist));
if (is_array($hist) && count($hist) > 0) {
    check('wellness score in 0-100 range', isset($hist[0]['wellness_score'])
        && $hist[0]['wellness_score'] >= 0 && $hist[0]['wellness_score'] <= 100, (string) ($hist[0]['wellness_score'] ?? 'null'));
}
// Cleanup the test wellness row
$conn->query("DELETE FROM member_wellness_tracking WHERE injury_notes = 'test entry'");

// 7. getInjuredMembers returns array (may be empty — table must just work)
$injured = $analytics->getInjuredMembers();
check('getInjuredMembers returns array', is_array($injured));

echo str_repeat('-', 48) . "\n";
echo "Result: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
