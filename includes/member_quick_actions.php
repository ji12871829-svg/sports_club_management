<?php
/**
 * includes/member_quick_actions.php
 * Shared "Quick Actions Hub" sidebar card for member portal pages.
 *
 * Renders the full card (header + grouped action links). Includes it inside
 * a Bootstrap column, e.g.:
 *
 *   <div class="col-lg-4">
 *       <?php include __DIR__ . '/../includes/member_quick_actions.php'; ?>
 *   </div>
 *
 * Requires portal.css (public/css/portal.css) for the md-card / md-action
 * classes. Links are relative to public/, so this include is only used from
 * pages in public/ (or pages whose relative links resolve to public/).
 */
?>
<div class="md-card">
    <div class="md-card-head">
        <div>
            <h4 class="md-card-title"><i class="fas fa-bolt"></i>Quick Actions Hub</h4>
            <small class="text-muted">Jump straight to what you need</small>
        </div>
    </div>
    <div class="card-body p-4">
        <div class="mb-4">
            <div class="md-hub-group-label mb-2">Reservations Engine</div>
            <div class="d-flex flex-column gap-2">
                <a href="booking.php" class="md-action"><i class="fas fa-calendar-plus"></i>Book a Court</a>
                <a href="book_training.php" class="md-action"><i class="fas fa-person-chalkboard"></i>Schedule a Class</a>
                <a href="view_coaches.php" class="md-action"><i class="fas fa-user-tie"></i>Find a Coach</a>
                <a href="view_bookings.php" class="md-action"><i class="fas fa-list-check"></i>View My Bookings</a>
                <a href="booking_calendar.php" class="md-action"><i class="fas fa-calendar-alt"></i>View Booking Calendar</a>
                <a href="ai_booking_suggestions.php" class="md-action"><i class="fas fa-robot"></i>AI Booking Suggestions</a>
            </div>
        </div>
        <div class="mb-4">
            <div class="md-hub-group-label mb-2">Ticketing &amp; Finances</div>
            <div class="d-flex flex-column gap-2">
                <a href="payments.php" class="md-action"><i class="fas fa-file-invoice-dollar"></i>View Invoices</a>
                <a href="tickets.php" class="md-action"><i class="fas fa-ticket"></i>Buy Event Tickets</a>
                <a href="my_tickets.php" class="md-action"><i class="fas fa-ticket-simple"></i>View My Tickets</a>
                <a href="payments.php" class="md-action"><i class="fas fa-money-bill-wave"></i>Make a Payment</a>
                <a href="payments.php" class="md-action"><i class="fas fa-wallet"></i>Payment Methods</a>
            </div>
        </div>
        <div class="mb-4">
            <div class="md-hub-group-label mb-2">Profile</div>
            <div class="d-flex flex-column gap-2">
                <a href="update_profile.php" class="md-action"><i class="fas fa-user-pen"></i>Update Personal Details</a>
                <a href="memberships.php" class="md-action"><i class="fas fa-medal"></i>Manage Membership</a>
                <a href="fitness_dashboard.php" class="md-action"><i class="fas fa-heart-pulse"></i>Health Stats</a>
                <a href="team_registration.php" class="md-action"><i class="fas fa-users"></i>Join a League Team</a>
                <a href="delete_account.php" class="md-action"><i class="fas fa-trash"></i>Delete Account</a>
            </div>
        </div>
        <div class="mb-4">
            <div class="md-hub-group-label mb-2">Explore the Club</div>
            <div class="d-flex flex-column gap-2">
                <a href="view_sports.php" class="md-action"><i class="fas fa-basketball-ball"></i>View Sports Directory</a>
                <a href="view_facilities.php" class="md-action"><i class="fas fa-building"></i>View Facilities</a>
                <a href="view_coaches.php" class="md-action"><i class="fas fa-user-tie"></i>View Certified Coaches</a>
            </div>
        </div>
        <div>
            <div class="md-hub-group-label mb-2">Session</div>
            <div class="d-flex flex-column gap-2">
                <a href="logout.php" class="md-action md-action-logout"><i class="fas fa-sign-out-alt"></i>Sign Out</a>
            </div>
        </div>
    </div>
</div>
