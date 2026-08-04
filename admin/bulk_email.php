<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/email_campaigns.php";
require_once "../includes/csrf.php";
require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm">Security token check failed. Refresh and try again.</div>';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $bodyText = trim((string) ($_POST['message'] ?? ''));
        $audienceType = (string) ($_POST['audience_type'] ?? 'all_members');
        $leagueId = (int) ($_POST['league_id'] ?? 0);
        $teamId = (int) ($_POST['team_id'] ?? 0);

        $safeHtml = nl2br(e($bodyText));

        $result = create_email_campaign(
            $conn,
            (int) ($_SESSION['admin_id'] ?? 0),
            $title,
            $subject,
            $safeHtml,
            $audienceType,
            $leagueId > 0 ? $leagueId : null,
            $teamId > 0 ? $teamId : null
        );

        if ($result['success']) {
            $message = '<div class="alert alert-success border-0 shadow-sm">' . e($result['message']) . '</div>';
        } else {
            $message = '<div class="alert alert-danger border-0 shadow-sm">' . e($result['message']) . '</div>';
        }
    }
}

$audience = email_campaign_audience_options($conn);
$campaigns = fetch_recent_campaigns($conn, 12);
$conn->close();
?>

<style>
    body { background-color: #f8fafc !important; color: #334155 !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    .page-header-text { font-size: 1.5rem; font-weight: 700; color: #0f172a; letter-spacing: -0.025em; }
    
    /* Premium Minimal Workspace Cards */
    .workspace-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); overflow: hidden; padding: 1.5rem !important; }
    .card-header-title { font-size: 0.95rem; font-weight: 600; color: #0f172a; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem; }
    
    /* Clean Enterprise Inputs */
    .form-label { font-size: 0.75rem; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.025em; margin-bottom: 0.375rem; }
    .form-control, .form-select { background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 8px !important; padding: 0.55rem 0.75rem !important; font-size: 0.9rem !important; color: #0f172a !important; transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out !important; }
    .form-control:focus, .form-select:focus { border-color: #0284c7 !important; box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15) !important; outline: 0 !important; }
    .form-text { font-size: 0.75rem !important; color: #64748b !important; margin-top: 0.375rem; }
    
    /* Premium Action Buttons */
    .btn-primary { background-color: #0f172a !important; color: #ffffff !important; border: none !important; border-radius: 8px !important; padding: 0.6rem 1.25rem !important; font-size: 0.875rem !important; font-weight: 500 !important; transition: background-color 0.1s ease !important; }
    .btn-primary:hover { background-color: #1e293b !important; color: #ffffff !important; }

    /* Structural Data Table System */
    .table-container th { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.75rem 1.25rem; }
    .table-container td { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; color: #334155; vertical-align: middle; }
    .table-container tr:hover td { background-color: #fafafa; }
    
    /* System Transaction Type Badges */
    .status-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-radius: 6px; line-height: 1; border: 1px solid transparent; }
    .status-queued { background-color: #fef3c7 !important; color: #d97706 !important; border-color: #fde68a; }
    .status-sending { background-color: #e0f2fe !important; color: #0284c7 !important; border-color: #bae6fd; }
    .status-completed { background-color: #f0fdf4 !important; color: #16a34a !important; border-color: #bbf7d0; }
    .status-failed { background-color: #fef2f2 !important; color: #dc2626 !important; border-color: #fecaca; }
</style>

<div class="container-fluid py-4 px-md-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2 class="page-header-text mb-1">Bulk Email Broadcaster</h2>
            <p class="text-muted mb-0 small">Compose one message, target a segment, and let the queue worker deliver safely.</p>
        </div>
        <span class="small font-monospace bg-white border px-3 py-1.5 rounded-3 text-secondary shadow-sm">Worker: <code>cron/cron_email_campaigns.php</code></span>
    </div>

    <?php if ($message !== ''): ?>
        <div class="mb-4"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="workspace-card">
                <h5 class="card-header-title">Create Campaign</h5>
                <form method="post">
                    <?php echo csrf_field('admin_csrf'); ?>

                    <div class="mb-3">
                        <label class="form-label">Campaign title</label>
                        <input type="text" name="title" class="form-control" maxlength="180" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email subject</label>
                        <input type="text" name="subject" class="form-control" maxlength="180" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Audience</label>
                        <select class="form-select" name="audience_type" id="audienceType">
                            <option value="all_members">All members</option>
                            <option value="league">Specific league</option>
                            <option value="team">Specific team</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="leagueBlock">
                        <label class="form-label">League</label>
                        <select class="form-select" name="league_id">
                            <option value="0">Choose league</option>
                            <?php foreach ($audience['leagues'] as $league): ?>
                                <option value="<?php echo (int) $league['league_id']; ?>">
                                    <?php echo e($league['name'] . ' (' . $league['season'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="teamBlock">
                        <label class="form-label">Team</label>
                        <select class="form-select" name="team_id">
                            <option value="0">Choose team</option>
                            <?php foreach ($audience['teams'] as $team): ?>
                                <option value="<?php echo (int) $team['team_id']; ?>">
                                    <?php echo e($team['team_name'] . ' — ' . $team['league_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control font-monospace" rows="8" maxlength="12000" required></textarea>
                        <div class="form-text">Line breaks are preserved in email output.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-paper-plane me-2"></i>Queue Campaign
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="workspace-card p-0">
                <h5 class="card-header-title" style="padding: 0 1.5rem 0.75rem 1.5rem;">Recent Campaigns</h5>
                <?php if (empty($campaigns)): ?>
                    <div class="p-4 pt-2">
                        <p class="text-muted small mb-0">No campaigns yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive table-container">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th>Status</th>
                                    <th>Progress</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($campaigns as $campaign): ?>
                                <?php $statusKey = strtolower((string) $campaign['status']); ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-slate-900"><?php echo e($campaign['title']); ?></div>
                                        <div class="small text-muted text-truncate" style="max-width: 200px;"><?php echo e($campaign['subject']); ?></div>
                                    </td>
                                    <td>
                                        <span class="status-pill status-<?php echo e($statusKey); ?>">
                                            <?php echo e($campaign['status']); ?>
                                        </span>
                                    </td>
                                    <td class="small">
                                        <div class="fw-bold text-slate-800">
                                            Sent <?php echo (int) $campaign['sent_count']; ?> / <?php echo (int) $campaign['total_recipients']; ?>
                                        </div>
                                        <span class="text-danger" style="font-size: 0.75rem;">Failed: <?php echo (int) $campaign['failed_count']; ?></span>
                                    </td>
                                    <td class="small text-muted"><?php echo e(date('d M Y H:i', strtotime($campaign['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const audienceType = document.getElementById('audienceType');
        const leagueBlock = document.getElementById('leagueBlock');
        const teamBlock = document.getElementById('teamBlock');

        function syncAudienceFields() {
            const value = audienceType.value;
            leagueBlock.classList.toggle('d-none', value !== 'league');
            teamBlock.classList.toggle('d-none', value !== 'team');
        }

        audienceType.addEventListener('change', syncAudienceFields);
        syncAudienceFields();
    })();
</script>

<?php include_once("../includes/footer.php"); ?>
