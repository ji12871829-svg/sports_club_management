<?php
// ============================================================
//  includes/send_email.php  —  Brevo (Sendinblue) Email Helper
//  Free tier: 300 emails/day
//  Sign up: https://app.brevo.com
// ============================================================

require_once __DIR__ . '/../config/api_config.php';

/**
 * Send an HTML email via Brevo API
 * @param string $toEmail    Recipient email
 * @param string $toName     Recipient name
 * @param string $subject    Email subject
 * @param string $htmlBody   HTML email body
 * @return bool              true on success
 */
function sendEmail($toEmail, $toName, $subject, $htmlBody) {
    $payload = [
        "sender"      => ["name" => CLUB_EMAIL_NAME, "email" => CLUB_EMAIL_FROM],
        "to"          => [["email" => $toEmail, "name"  => $toName]],
        "subject"     => $subject,
        "htmlContent" => $htmlBody
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.brevo.com/v3/smtp/email",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "api-key: "    . BREVO_API_KEY,
            "Content-Type: application/json",
            "Accept: application/json"
        ]
    ]);
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $httpCode === 201;
}

function sendBookingConfirmationFromPost(array $post, string $toEmail, string $firstName, array $sports, array $facilities): bool {
    $sport = 'Unknown Sport';
    $facility = 'Unknown Facility';
    $sportId = intval($post['sport_id'] ?? 0);
    $facilityId = intval($post['facility_id'] ?? 0);

    foreach ($sports as $item) {
        if (isset($item['sport_id']) && intval($item['sport_id']) === $sportId) {
            $sport = $item['name'];
            break;
        }
    }

    foreach ($facilities as $item) {
        if (isset($item['facility_id']) && intval($item['facility_id']) === $facilityId) {
            $facility = $item['name'];
            break;
        }
    }

    $date      = $post['booking_date'] ?? '';
    $startTime = $post['start_time'] ?? '';
    $endTime   = $post['end_time'] ?? '';

    $subject  = 'Booking Confirmation';
    $htmlBody = emailBookingConfirmation($firstName, $sport, $facility, $date, $startTime, $endTime);

    return sendEmail($toEmail, $firstName, $subject, $htmlBody);
}

// ── Email Templates ──────────────────────────────────────────

function emailWelcome($firstName) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #ddd;border-radius:8px;overflow:hidden'>
      <div style='background:#007bff;padding:24px;text-align:center'>
        <h1 style='color:white;margin:0'>🏆 Welcome to Apex Sports Club!</h1>
      </div>
      <div style='padding:24px'>
        <p style='font-size:16px'>Hi <strong>" . htmlspecialchars($firstName) . "</strong>,</p>
        <p>Your membership account has been created successfully. You can now:</p>
        <ul>
          <li>📅 Book sports sessions and facilities</li>
          <li>🏅 Browse available coaches</li>
          <li>📊 Track your bookings</li>
        </ul>
        <a href='http://localhost/sports_club_management/public/login.php'
           style='display:inline-block;background:#007bff;color:white;padding:12px 24px;
                  border-radius:6px;text-decoration:none;margin-top:12px'>
          Login to Your Account →
        </a>
      </div>
      <div style='background:#f8f9fa;padding:12px;text-align:center;color:#888;font-size:12px'>
        Apex Sports Club
      </div>
    </div>";
}

function emailBookingConfirmation($firstName, $sport, $facility, $date, $startTime, $endTime) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #ddd;border-radius:8px;overflow:hidden'>
      <div style='background:#28a745;padding:24px;text-align:center'>
        <h1 style='color:white;margin:0'>✅ Booking Confirmed!</h1>
      </div>
      <div style='padding:24px'>
        <p>Hi <strong>" . htmlspecialchars($firstName) . "</strong>, your booking is confirmed.</p>
        <table style='width:100%;border-collapse:collapse;margin-top:16px'>
          <tr style='background:#f8f9fa'>
            <td style='padding:10px;border:1px solid #ddd'><strong>Sport</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($sport) . "</td>
          </tr>
          <tr>
            <td style='padding:10px;border:1px solid #ddd'><strong>Facility</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($facility) . "</td>
          </tr>
          <tr style='background:#f8f9fa'>
            <td style='padding:10px;border:1px solid #ddd'><strong>Date</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($date) . "</td>
          </tr>
          <tr>
            <td style='padding:10px;border:1px solid #ddd'><strong>Time</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($startTime) . " – " . htmlspecialchars($endTime) . "</td>
          </tr>
        </table>
        <p style='margin-top:16px;color:#666;font-size:13px'>
          Your booking status is currently <strong>Pending</strong> and will be confirmed by an admin shortly.
        </p>
        <a href='http://localhost/sports_club_management/public/view_bookings.php'
           style='display:inline-block;background:#28a745;color:white;padding:12px 24px;
                  border-radius:6px;text-decoration:none;margin-top:8px'>
          View My Bookings →
        </a>
      </div>
      <div style='background:#f8f9fa;padding:12px;text-align:center;color:#888;font-size:12px'>
        Apex Sports Club
      </div>
    </div>";
}

function emailBookingStatusUpdate($firstName, $sport, $date, $status) {
    $color  = $status === 'Approved' ? '#28a745' : ($status === 'Rejected' ? '#dc3545' : '#ffc107');
    $icon   = $status === 'Approved' ? '✅' : ($status === 'Rejected' ? '❌' : '🔄');
    return "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #ddd;border-radius:8px;overflow:hidden'>
      <div style='background:{$color};padding:24px;text-align:center'>
        <h1 style='color:white;margin:0'>{$icon} Booking {$status}</h1>
      </div>
      <div style='padding:24px'>
        <p>Hi <strong>" . htmlspecialchars($firstName) . "</strong>,</p>
        <p>Your booking for <strong>" . htmlspecialchars($sport) . "</strong> on <strong>" . htmlspecialchars($date) . "</strong>
           has been updated to: <strong style='color:{$color}'>{$status}</strong>.</p>
        <a href='http://localhost/sports_club_management/public/view_bookings.php'
           style='display:inline-block;background:#007bff;color:white;padding:12px 24px;
                  border-radius:6px;text-decoration:none;margin-top:8px'>
          View My Bookings →
        </a>
      </div>
      <div style='background:#f8f9fa;padding:12px;text-align:center;color:#888;font-size:12px'>
        Apex Sports Club
      </div>
    </div>";
}

function emailPaymentReceipt($firstName, $amount, $method, $description) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #ddd;border-radius:8px;overflow:hidden'>
      <div style='background:#17a2b8;padding:24px;text-align:center'>
        <h1 style='color:white;margin:0'>🧾 Payment Receipt</h1>
      </div>
      <div style='padding:24px'>
        <p>Hi <strong>" . htmlspecialchars($firstName) . "</strong>, we received your payment.</p>
        <table style='width:100%;border-collapse:collapse;margin-top:16px'>
          <tr style='background:#f8f9fa'>
            <td style='padding:10px;border:1px solid #ddd'><strong>Amount</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>KES " . number_format($amount, 2) . "</td>
          </tr>
          <tr>
            <td style='padding:10px;border:1px solid #ddd'><strong>Method</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($method) . "</td>
          </tr>
          <tr style='background:#f8f9fa'>
            <td style='padding:10px;border:1px solid #ddd'><strong>Description</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($description) . "</td>
          </tr>
          <tr>
            <td style='padding:10px;border:1px solid #ddd'><strong>Date</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . date('d M Y, H:i') . "</td>
          </tr>
        </table>
        <p style='margin-top:16px;color:#666;font-size:13px'>Thank you for your payment!</p>
      </div>
      <div style='background:#f8f9fa;padding:12px;text-align:center;color:#888;font-size:12px'>
        Apex Sports Club
      </div>
    </div>";
}
