<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/smart_access_facility.php';

// Check if user is admin
if (!isset($_SESSION['member_id']) || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit;
}

$facility_mgr = new SmartAccessFacility($conn);

// Get damage reports
$damage_reports = $facility_mgr->getDamageReports(null, 20);

// Get energy consumption
$energy_data = [];
if (!empty($damage_reports)) {
    $energy_data = $facility_mgr->getEnergyConsumptionReport(1, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Access & Facility Management - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .facility-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 40px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #ddd;
        }

        .tab {
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .damage-report-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 20px;
            align-items: center;
        }

        .damage-info h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .damage-meta {
            display: flex;
            gap: 20px;
            margin: 10px 0;
            flex-wrap: wrap;
        }

        .damage-meta-item {
            font-size: 14px;
            color: #666;
        }

        .damage-class-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .class-minor {
            background: #d1ecf1;
            color: #0c5460;
        }

        .class-moderate {
            background: #fff3cd;
            color: #856404;
        }

        .class-severe {
            background: #f8d7da;
            color: #721c24;
        }

        .class-critical {
            background: #721c24;
            color: white;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-reported {
            background: #e7e7ff;
            color: #667eea;
        }

        .status-reviewed {
            background: #fff3cd;
            color: #856404;
        }

        .status-in_repair {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .damage-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .energy-chart {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .energy-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .energy-stat {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }

        .energy-stat h3 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
        }

        .energy-stat .value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="facility-container">
        <div class="header">
            <h1>🏢 Smart Access & Facility Management</h1>
            <p>Manage equipment, track facility usage, and optimize energy consumption</p>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('damage')">
                🔧 Damage Reports
            </button>
            <button class="tab" onclick="switchTab('energy')">
                ⚡ Energy Management
            </button>
            <button class="tab" onclick="switchTab('access')">
                🔐 Access Codes
            </button>
        </div>

        <!-- Damage Reports Tab -->
        <div id="damage" class="tab-content active">
            <h2>Equipment Damage Reports</h2>
            
            <?php if (empty($damage_reports)): ?>
                <div class="empty-state">
                    <p>No damage reports yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($damage_reports as $report): ?>
                    <div class="damage-report-card">
                        <div class="damage-info">
                            <h3><?php echo htmlspecialchars($report['equipment_name']); ?></h3>
                            <p><?php echo htmlspecialchars($report['damage_description']); ?></p>
                            <div class="damage-meta">
                                <div class="damage-meta-item">
                                    Reported by: <?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?>
                                </div>
                                <div class="damage-meta-item">
                                    Facility: <?php echo htmlspecialchars($report['facility_name'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div style="margin-bottom: 10px;">
                                <span class="damage-class-badge class-<?php echo strtolower($report['ai_damage_class'] ?? 'unknown'); ?>">
                                    <?php echo ucfirst($report['ai_damage_class'] ?? 'Unclassified'); ?>
                                </span>
                            </div>
                            <div>
                                <span class="status-badge status-<?php echo strtolower($report['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                                </span>
                            </div>
                        </div>

                        <div class="damage-actions">
                            <button class="btn btn-primary">Update Status</button>
                            <button class="btn btn-secondary">View Details</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Energy Management Tab -->
        <div id="energy" class="tab-content">
            <h2>Energy Consumption & Management</h2>
            
            <div class="energy-stats">
                <div class="energy-stat">
                    <h3>Total KWh (30 days)</h3>
                    <div class="value">
                        <?php 
                            $total_kwh = 0;
                            foreach ($energy_data as $item) {
                                $total_kwh += $item['total_kwh'] ?? 0;
                            }
                            echo number_format($total_kwh, 2);
                        ?>
                    </div>
                </div>
                <div class="energy-stat">
                    <h3>Avg per Activation</h3>
                    <div class="value">
                        <?php 
                            $total_activations = 0;
                            foreach ($energy_data as $item) {
                                $total_activations += $item['activations'] ?? 0;
                            }
                            $avg = $total_activations > 0 ? $total_kwh / $total_activations : 0;
                            echo number_format($avg, 2);
                        ?>
                    </div>
                </div>
                <div class="energy-stat">
                    <h3>Estimated Cost</h3>
                    <div class="value">
                        $<?php echo number_format($total_kwh * 0.12, 2); ?>
                    </div>
                </div>
            </div>

            <div class="energy-chart">
                <h3>Device Breakdown (Last 30 Days)</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #ddd;">
                            <th style="text-align: left; padding: 10px;">Device Type</th>
                            <th style="text-align: center; padding: 10px;">Activations</th>
                            <th style="text-align: center; padding: 10px;">Total KWh</th>
                            <th style="text-align: center; padding: 10px;">Avg KWh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($energy_data as $item): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 10px;"><?php echo ucfirst(str_replace('_', ' ', $item['device_type'])); ?></td>
                                <td style="text-align: center; padding: 10px;"><?php echo $item['activations']; ?></td>
                                <td style="text-align: center; padding: 10px;"><?php echo number_format($item['total_kwh'], 2); ?></td>
                                <td style="text-align: center; padding: 10px;"><?php echo number_format($item['avg_kwh'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Access Codes Tab -->
        <div id="access" class="tab-content">
            <h2>Smart Access Codes</h2>
            <p>Members receive unique time-limited access codes via WhatsApp when they book a facility.</p>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin-top: 20px;">
                <p><strong>✓ Smart Access Enabled</strong></p>
                <p>Members will automatically receive 6-digit access codes for their bookings.</p>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
