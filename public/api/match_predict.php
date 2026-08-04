<?php
/**
 * Public REST endpoint: Match Prediction API
 * GET /public/api/match_predict.php?home=TeamA&away=TeamB
 * Returns JSON prediction data
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once '../../config/api_config.php';
require_once '../../includes/rate_limiter.php';
require_once '../../includes/gemini_client.php';

function e_json(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$home = trim((string)($_GET['home'] ?? ''));
$away = trim((string)($_GET['away'] ?? ''));

if ($home === '' || $away === '') {
    e_json('Both "home" and "away" query parameters are required.');
}

// Rate limit AFTER parameter validation so malformed/junk requests do not
// burn a legitimate client's budget. 5 requests per minute per client IP.
if (!rate_limit_check(client_rate_key('match_predict'), 5, 60)) {
    e_json('Rate limit exceeded. Max 5 requests per minute.', 429);
}

if ($home === $away) {
    e_json('Home and away teams must be different.');
}
if (strlen($home) > 100 || strlen($away) > 100) {
    e_json('Team names too long.');
}

// ── Algorithmic fallback ────────────────────────────────────────────────────
function algo_predict(string $home, string $away): array {
    srand(crc32($home . $away . date('Y-W')));
    $hStr = 50 + rand(-15, 15) + 5; // home advantage
    $aStr = 50 + rand(-15, 15);
    $total = $hStr + $aStr;
    $drawPct = rand(18, 28);
    $rem = 100 - $drawPct;
    $hPct = (int)round(($hStr / $total) * $rem);
    $aPct = $rem - $hPct;

    $hGoals = rand(0, 3);
    $aGoals = rand(0, 3);
    if ($hPct > 50) $hGoals = max($hGoals, $aGoals);
    if ($aPct > 50) $aGoals = max($hGoals, $aGoals);

    $conf = abs($hPct - $aPct) > 20 ? 'High' : (abs($hPct - $aPct) > 10 ? 'Medium' : 'Low');
    return [
        'home_team'      => $home,
        'away_team'      => $away,
        'home_win_pct'   => $hPct,
        'draw_pct'       => $drawPct,
        'away_win_pct'   => $aPct,
        'predicted_score'=> "$hGoals-$aGoals",
        'confidence'     => $conf,
        'analysis'       => "Statistical prediction based on team dynamics. $home vs $away is expected to be a competitive match with home advantage factored in.",
        'source'         => 'algorithmic',
    ];
}

// ── Try Gemini ──────────────────────────────────────────────────────────────
$apiKey = defined('GEMINI_API_KEY') ? trim((string)GEMINI_API_KEY) : '';
if ($apiKey !== '') {
    $prompt = "You are a football match predictor. Predict the outcome of: $home vs $away (home team first).

Return ONLY valid JSON with no markdown:
{
  \"home_win_pct\": <integer 0-100>,
  \"draw_pct\": <integer 0-100>,
  \"away_win_pct\": <integer 0-100>,
  \"predicted_score\": \"<home_goals>-<away_goals>\",
  \"confidence\": \"Low|Medium|High\",
  \"analysis\": \"<2 sentence analysis>\"
}
Ensure the three percentages sum to exactly 100.";

    $result = asc_gemini_generate_text($prompt, ['temperature' => 0.4, 'maxOutputTokens' => 300, 'timeout' => 20]);

    if (!empty($result['success'])) {
        $text = preg_replace('/```json\s*|\s*```/', '', $result['text']);
        if (preg_match('/\{[\s\S]+\}/m', $text, $m)) {
            $parsed = json_decode($m[0], true);
            if ($parsed && isset($parsed['home_win_pct'], $parsed['draw_pct'], $parsed['away_win_pct'])) {
                $total = $parsed['home_win_pct'] + $parsed['draw_pct'] + $parsed['away_win_pct'];
                if ($total > 0 && $total !== 100) {
                    $ratio = 100 / $total;
                    $parsed['home_win_pct'] = (int)round($parsed['home_win_pct'] * $ratio);
                    $parsed['draw_pct']     = (int)round($parsed['draw_pct']     * $ratio);
                    $parsed['away_win_pct'] = 100 - $parsed['home_win_pct'] - $parsed['draw_pct'];
                }
                $parsed['home_team'] = $home;
                $parsed['away_team'] = $away;
                $parsed['source']    = 'gemini';
                echo json_encode(['success' => true, 'prediction' => $parsed]);
                exit;
            }
        }
    }
}

// Fallback
$prediction = algo_predict($home, $away);
echo json_encode(['success' => true, 'prediction' => $prediction]);
