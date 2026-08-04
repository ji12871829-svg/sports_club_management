<?php
/**
 * Grouped fixture list for ticket purchase pages.
 *
 * Expects: $fixtures_by_sport, $selected_fixture_id, $purchase_page, $list_style ('fan'|'member')
 */
require_once __DIR__ . '/input_sanitize.php';

$purchase_page = $purchase_page ?? 'fan_tickets.php';
$list_style = $list_style ?? 'fan';
$selected_fixture_id = (int) ($selected_fixture_id ?? 0);
$selected_sport_id = 0;

if ($selected_fixture_id > 0) {
    foreach ($fixtures_by_sport as $group) {
        foreach ($group['fixtures'] as $fixture) {
            if ((int) $fixture['fixture_id'] === $selected_fixture_id) {
                $selected_sport_id = (int) $group['sport_id'];
                break 2;
            }
        }
    }
}

$accordion_id = 'ticket-sport-accordion-' . md5($purchase_page . $list_style);
?>

<?php if (empty($fixtures_by_sport)): ?>
    <div class="p-5 text-center text-muted">
        <span class="d-block mb-2" style="font-size: 2rem;">🏟️</span>
        No ticketed fixtures are currently listed.
    </div>
<?php else: ?>
    <div class="sport-ticket-nav d-flex flex-wrap gap-2 p-3 border-bottom bg-light">
        <?php foreach ($fixtures_by_sport as $group): ?>
            <?php
                $count = count($group['fixtures']);
                $target = $accordion_id . '-sport-' . (int) $group['sport_id'];
            ?>
            <a href="#<?php echo e($target); ?>"
               class="btn btn-sm btn-outline-primary sport-jump-link"
               data-bs-toggle="collapse"
               data-bs-target="#<?php echo e($target); ?>"
               aria-expanded="false">
                <?php echo e(ticketing_sport_icon($group['sport_name'])); ?>
                <?php echo e($group['sport_name']); ?>
                <span class="badge bg-primary ms-1"><?php echo (int) $count; ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="accordion accordion-flush" id="<?php echo e($accordion_id); ?>">
        <?php foreach ($fixtures_by_sport as $index => $group): ?>
            <?php
                $sportId = (int) $group['sport_id'];
                $collapseId = $accordion_id . '-sport-' . $sportId;
                $isOpen = $selected_sport_id > 0
                    ? $sportId === $selected_sport_id
                    : $index === 0;
                $saleableCount = 0;
                foreach ($group['fixtures'] as $fx) {
                    if (ticketing_fixture_is_saleable($fx, $fx['ticket_info'])) {
                        $saleableCount++;
                    }
                }
            ?>
            <div class="accordion-item border-0 border-bottom">
                <h2 class="accordion-header" id="<?php echo e($collapseId); ?>-head">
                    <button class="accordion-button <?php echo $isOpen ? '' : 'collapsed'; ?> py-3"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?php echo e($collapseId); ?>"
                            aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>"
                            aria-controls="<?php echo e($collapseId); ?>">
                        <span class="me-2 fs-5"><?php echo e(ticketing_sport_icon($group['sport_name'])); ?></span>
                        <span class="fw-bold"><?php echo e($group['sport_name']); ?></span>
                        <span class="text-muted small ms-2">
                            <?php echo count($group['fixtures']); ?> match<?php echo count($group['fixtures']) === 1 ? '' : 'es'; ?>
                            <?php if ($saleableCount > 0): ?>
                                · <span class="text-success"><?php echo (int) $saleableCount; ?> on sale</span>
                            <?php endif; ?>
                        </span>
                    </button>
                </h2>
                <div id="<?php echo e($collapseId); ?>"
                     class="accordion-collapse collapse <?php echo $isOpen ? 'show' : ''; ?>"
                     aria-labelledby="<?php echo e($collapseId); ?>-head"
                     data-bs-parent="#<?php echo e($accordion_id); ?>">
                    <div class="accordion-body p-0">
                        <?php if ($list_style === 'member'): ?>
                            <div class="table-responsive">
                                <table class="table table-ledger mb-0 align-middle">
                                    <thead>
                                        <tr>
                                            <th>Fixture</th>
                                            <th>Schedule</th>
                                            <th>Venue</th>
                                            <th>Price</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group['fixtures'] as $fixture): ?>
                                            <?php
                                                $info = $fixture['ticket_info'];
                                                $isSaleable = ticketing_fixture_is_saleable($fixture, $info);
                                                $statusText = strtolower($info['sales_status']);
                                                $badgeBg = 'bg-secondary text-white';
                                                if ($statusText === 'open') {
                                                    $badgeBg = 'bg-success-subtle text-success border border-success-subtle';
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="text-cell-dark d-block mb-1"><?php echo e($fixture['home_team'] . ' vs ' . $fixture['away_team']); ?></span>
                                                    <span class="badge bg-light text-secondary border font-monospace" style="font-size:0.7rem;"><?php echo e($fixture['league_name']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="d-block font-monospace text-cell-dark small"><?php echo e(date('d M Y', strtotime($fixture['match_date']))); ?></span>
                                                    <span class="text-muted small font-monospace"><?php echo e(substr((string) $fixture['match_time'], 0, 5)); ?></span>
                                                </td>
                                                <td><span class="text-muted small"><?php echo e($fixture['venue'] ?: 'TBC'); ?></span></td>
                                                <td>
                                                    <span class="text-cell-dark font-monospace d-block">KES <?php echo number_format((float) $info['ticket_price'], 2); ?></span>
                                                    <small class="text-muted"><?php echo $info['available'] === null ? 'Available' : e($info['available'] . ' left'); ?></small>
                                                </td>
                                                <td><span class="badge-sales text-uppercase <?php echo $badgeBg; ?>"><?php echo e($info['sales_status']); ?></span></td>
                                                <td class="text-end">
                                                    <?php if ($isSaleable): ?>
                                                        <a href="<?php echo e($purchase_page); ?>?fixture_id=<?php echo e($fixture['fixture_id']); ?>" class="btn btn-premium-primary btn-sm px-3">Select</a>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-light border text-muted px-3" disabled>Closed</button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <?php foreach ($group['fixtures'] as $fixture): ?>
                                <?php
                                    $info = $fixture['ticket_info'];
                                    $is_saleable = ticketing_fixture_is_saleable($fixture, $info);
                                ?>
                                <div class="match-row d-flex justify-content-between align-items-center flex-wrap gap-3">
                                    <div>
                                        <div class="text-muted small fw-semibold mb-1"><?php echo e($fixture['league_name']); ?></div>
                                        <div class="fw-bold text-dark h6 mb-1"><?php echo e($fixture['home_team'] . ' vs ' . $fixture['away_team']); ?></div>
                                        <div class="text-muted small d-flex align-items-center flex-wrap gap-2">
                                            <span><i class="far fa-calendar text-muted"></i> <?php echo e(date('d M Y', strtotime($fixture['match_date']))); ?></span>
                                            <span>•</span>
                                            <span><i class="far fa-clock text-muted"></i> <?php echo e(substr((string) $fixture['match_time'], 0, 5)); ?></span>
                                            <span>•</span>
                                            <span><i class="fas fa-map-marker-alt text-muted"></i> <?php echo e($fixture['venue'] ?: 'Venue TBC'); ?></span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-4">
                                        <div class="text-md-end">
                                            <div class="fw-bold text-dark">KES <?php echo number_format((float) $info['ticket_price'], 2); ?></div>
                                            <div class="text-muted small"><?php echo $info['available'] === null ? '<span class="text-success">Available</span>' : e($info['available'] . ' slots left'); ?></div>
                                        </div>
                                        <?php if ($is_saleable): ?>
                                            <a href="<?php echo e($purchase_page); ?>?fixture_id=<?php echo e($fixture['fixture_id']); ?>" class="btn btn-ticket-primary btn-sm text-decoration-none px-3">Book Seat</a>
                                        <?php else: ?>
                                            <button class="btn btn-light border btn-sm px-3" disabled>Sold Out</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
