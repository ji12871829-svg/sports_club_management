<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/ticket_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int) $_SESSION['member_id'];
$message = '';
$schema_ready = ticketing_ensure_schema($conn);

if (!$schema_ready) {
    $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Ticketing tables are not ready. Import <code>ticketing_schema.sql</code>.</div>";
} elseif (isset($_GET['paid'])) {
    $message = "<div class='alert alert-success' style='border-radius: 8px; font-weight: 500;'>Payment confirmed. Your ticket QR code is ready.</div>";
}

$tickets = [];
if ($schema_ready) {
    $sql = "SELECT t.ticket_id, t.ticket_code, t.ticket_price, t.status, t.issued_at, t.used_at,
                   st.name AS supported_team,
                   f.match_date, f.match_time, f.venue, f.matchday,
                   h.name AS home_team, a.name AS away_team,
                   l.name AS league_name, s.name AS sport_name
            FROM tickets t
            JOIN fixtures f ON f.fixture_id = t.fixture_id
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN sports s ON s.sport_id = l.sport_id
            LEFT JOIN teams st ON st.team_id = t.supported_team_id
            WHERE t.member_id = ?
            ORDER BY f.match_date DESC, t.issued_at DESC";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }
        $stmt->close();
    }
}

$conn->close();
include '../includes/header.php';
?>

<style>
    body { 
        background-color: #f8fafc !important; 
        color: #334155 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    
    .page-header-corporate {
        border-bottom: 1px solid #e2e8f0;
        margin-bottom: 2.5rem;
        padding-bottom: 1.25rem;
    }

    .corporate-title {
        color: #0f172a;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .brand-accent-line {
        width: 40px;
        height: 4px;
        background-color: #2563eb;
        border-radius: 2px;
        margin-bottom: 1rem;
    }

    /* Enterprise Ticket Grid Elements */
    .corporate-ticket-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .corporate-ticket-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
    }

    .ticket-match-title {
        color: #0f172a;
        font-weight: 700;
        font-size: 1.1rem;
        letter-spacing: -0.3px;
    }

    .ticket-meta-label {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.5px;
    }

    .ticket-meta-value {
        font-size: 0.9rem;
        color: #334155;
        font-weight: 500;
    }

    .ticket-code-badge {
        font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 600;
        color: #0f172a;
        background-color: #f1f5f9;
        padding: 0.2rem 0.4rem;
        border-radius: 4px;
        font-size: 0.85rem;
    }

    .price-badge-corporate {
        font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 700;
        color: #16a34a;
        background-color: #f0fdf4;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.85rem;
    }

    /* Unified Actions Framework */
    .btn-corporate {
        font-size: 0.825rem;
        font-weight: 600;
        padding: 0.45rem 1rem;
        border-radius: 6px;
        transition: all 0.15s ease;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .btn-corporate-primary {
        background-color: #2563eb;
        color: #ffffff !important;
    }

    .btn-corporate-primary:hover {
        background-color: #1d4ed8;
    }

    .btn-corporate-secondary {
        background-color: #ffffff;
        border: 1px solid #cbd5e1;
        color: #475569 !important;
    }

    .btn-corporate-secondary:hover {
        background-color: #f8fafc;
        border-color: #94a3b8;
    }

    /* Contextual Allocation Badges */
    .status-pill-corporate {
        font-size: 0.725rem;
        font-weight: 700;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: 1px solid transparent;
    }

    .status-pill-valid {
        background-color: #f0fdf4;
        color: #16a34a;
        border-color: #bbf7d0;
    }

    .status-pill-used {
        background-color: #f1f5f9;
        color: #475569;
        border-color: #cbd5e1;
    }

    .status-pill-cancelled {
        background-color: #fef2f2;
        color: #dc2626;
        border-color: #fecaca;
    }

    .corporate-empty-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 4rem 2rem;
        color: #64748b;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
    }
</style>

<div class="container py-5">
    
    <div class="row page-header-corporate align-items-end">
        <div class="col-md-12 d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div>
                <div class="brand-accent-line"></div>
                <h1 class="corporate-title mb-2">My Access Tickets</h1>
                <p class="text-muted mb-0">Review verified entry tokens, capture gate authorization codes, and trace tournament fixture credentials.</p>
            </div>
            <div>
                <a href="tickets.php" class="btn-corporate btn-corporate-secondary shadow-sm">
                    Acquire Match Tickets
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <?php if (!empty($message)): ?>
                <div class="mb-4">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($tickets)): ?>
                <div class="text-center corporate-empty-box">
                    <h4 class="font-weight-bold mb-2" style="color: #0f172a;">No Active Tokens Discovered</h4>
                    <p class="mb-0 mx-auto text-muted" style="max-width: 480px;">There are currently no verification access codes or digital entry tokens registered to your identity track.</p>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($tickets as $ticket): ?>
                        <?php
                        $status_key = strtolower($ticket['status']);
                        $status_class = 'status-pill-' . $status_key;
                        if (!in_array($status_key, ['valid', 'used', 'cancelled'])) {
                            $status_class = 'status-pill-used';
                        }
                        $verify_url = ticketing_verify_url($ticket['ticket_code']);
                        ?>
                        <div class="col-xl-6 col-lg-12">
                            <div class="corporate-ticket-card p-4">
                                <div class="row align-items-center g-3">
                                    
                                    <div class="col-md-8 border-end-md">
                                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                            <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.72rem; font-weight: 600;">
                                                <?php echo e($ticket['sport_name'] . ' &middot; ' . $ticket['league_name']); ?>
                                            </span>
                                            <span class="status-pill-corporate <?php echo $status_class; ?>">
                                                <?php echo e($ticket['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <h3 class="ticket-match-title mb-3"><?php echo e($ticket['home_team'] . ' vs ' . $ticket['away_team']); ?></h3>
                                        
                                        <div class="row g-2 mb-3">
                                            <div class="col-sm-6">
                                                <div class="ticket-meta-label">Schedule Date</div>
                                                <div class="ticket-meta-value"><?php echo e(date('D, d M Y', strtotime($ticket['match_date']))); ?></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="ticket-meta-label">Kickoff Bound</div>
                                                <div class="ticket-meta-value"><?php echo e(date('H:i', strtotime($ticket['match_time']))); ?> hrs</div>
                                            </div>
                                            <div class="col-12 mt-2">
                                                <div class="ticket-meta-label">Target Asset Arena</div>
                                                <div class="ticket-meta-value text-truncate" style="max-width: 100%;"><?php echo e($ticket['venue'] ?: 'Venue TBC'); ?></div>
                                            </div>
                                        </div>

                                        <div class="pt-3 border-top d-flex flex-wrap gap-x-4 gap-y-2 text-muted small" style="border-color: #f1f5f9 !important;">
                                            <div>Token: <span class="ticket-code-badge"><?php echo e($ticket['ticket_code']); ?></span></div>
                                            <div>Support: <span class="text-dark fw-semibold"><?php echo e($ticket['supported_team'] ?: 'None Alignment'); ?></span></div>
                                            <div>Quantum: <span class="price-badge-corporate">KES <?php echo number_format((float) $ticket['ticket_price'], 2); ?></span></div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4 text-center ps-md-4">
                                        <div class="d-inline-block p-2 bg-white border rounded shadow-sm mb-2">
                                             <img src="<?php echo e(ticketing_qr_image_url($ticket['ticket_code'])); ?>"
                                                 alt="Ticket QR Authorization Token" width="130" height="130" class="d-block" loading="lazy" decoding="async" style="image-rendering: -webkit-optimize-contrast;">
                                        </div>
                                        <div class="mt-1">
                                            <a href="<?php echo e($verify_url); ?>" class="btn-corporate btn-corporate-primary w-100 shadow-sm">
                                                Verify Token Node
                                            </a>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>