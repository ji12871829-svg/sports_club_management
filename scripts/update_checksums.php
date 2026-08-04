<?php
require __DIR__ . '/../config/api_config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    die('DB connect failed: ' . $conn->connect_error);
}
$updates = [
    ['033', '41E496D4BC1ABFDBA73235000E9D6776CC7C1FDC6CA858EA4EA5804E3FB3B87C'],
    ['034', 'D22C56724FA40AA29D6D067A2D582AD7C9FA844D2F8D89A4C300BC62C99D0761'],
];
foreach ($updates as [$version, $checksum]) {
    $stmt = $conn->prepare('UPDATE schema_migrations SET checksum = ? WHERE version = ?');
    $stmt->bind_param('ss', $checksum, $version);
    if (!$stmt->execute()) {
        echo "ERROR updating {$version}: " . $stmt->error . "\n";
    } else {
        echo "Updated {$version}\n";
    }
}
$conn->close();
