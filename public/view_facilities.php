<?php
// ============================================================
//  public/view_facilities.php
//  APIs Added:
//    ✅ Leaflet.js + OpenStreetMap — interactive facility map
//       100% free, no API key required
// ============================================================
session_start();
require_once '../config/api_config.php';
include_once("../includes/header.php");
require_once "../config/db_connect.php";

$facilities = [];
$sql = "SELECT facility_id, name, location, type, capacity FROM facilities ORDER BY name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $facilities[] = $row;
    }
    $result->free();
}
$conn->close();

// Map each facility type to an icon and approximate offset from club centre
// Update lat/lng offsets to match your real club layout
$facilityMeta = [
    'Rugby Field'         => ['icon' => '🏉', 'color' => '#28a745', 'latOff' =>  0.0010, 'lngOff' => -0.0010],
    'Football Pitch'      => ['icon' => '⚽', 'color' => '#007bff', 'latOff' =>  0.0005, 'lngOff' =>  0.0015],
    'Hockey Field'        => ['icon' => '🏑', 'color' => '#17a2b8', 'latOff' => -0.0008, 'lngOff' => -0.0012],
    'Volleyball Court'    => ['icon' => '🏐', 'color' => '#ffc107', 'latOff' =>  0.0000, 'lngOff' =>  0.0000],
    'Chess Room'          => ['icon' => '♟️', 'color' => '#6c757d', 'latOff' => -0.0005, 'lngOff' =>  0.0008],
    'Horse Riding Arena'  => ['icon' => '🐎', 'color' => '#fd7e14', 'latOff' =>  0.0012, 'lngOff' =>  0.0005],
    'Badminton Court'     => ['icon' => '🏸', 'color' => '#e83e8c', 'latOff' => -0.0003, 'lngOff' => -0.0006],
];
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-building me-2"></i>Available Facilities</h2>
            </div>
            <div class="card-body">
                <?php if (count($facilities) > 0): ?>
                    <div class="row">
                        <?php foreach ($facilities as $facility): ?>
                            <?php $meta = $facilityMeta[$facility['name']] ?? ['icon' => '📍', 'color' => '#007bff']; ?>
                            <div class="col-md-4 mb-4">
                                <div class="card h-100 facility-card"
                                     data-name="<?php echo htmlspecialchars($facility['name']); ?>"
                                     style="border-left: 4px solid <?php echo $meta['color']; ?>">
                                    <div class="card-body">
                                        <h5 class="card-title">
                                            <?php echo $meta['icon']; ?>
                                            <?php echo htmlspecialchars($facility['name']); ?>
                                        </h5>
                                        <p class="card-text">
                                            <i class="fas fa-map-marker-alt me-1 text-danger"></i>
                                            <strong>Location:</strong> <?php echo htmlspecialchars($facility['location']); ?>
                                        </p>
                                        <p class="card-text">
                                            <i class="fas fa-tag me-1 text-primary"></i>
                                            <strong>Type:</strong> <?php echo htmlspecialchars($facility['type']); ?>
                                        </p>
                                        <p class="card-text">
                                            <i class="fas fa-users me-1 text-success"></i>
                                            <strong>Capacity:</strong> <?php echo htmlspecialchars($facility['capacity']); ?> people
                                        </p>
                                        <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                                            <a href="booking.php?facility_id=<?php echo $facility['facility_id']; ?>"
                                               class="btn btn-primary btn-sm">
                                               <i class="fas fa-calendar-plus me-1"></i>Book Now
                                            </a>
                                        <?php else: ?>
                                            <a href="login.php" class="btn btn-secondary btn-sm">Login to Book</a>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-secondary btn-sm ms-2"
                                                onclick="focusMarker('<?php echo htmlspecialchars($facility['name']); ?>')">
                                            <i class="fas fa-map-pin me-1"></i>Show on Map
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No facilities currently available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ✅ LEAFLET MAP — OpenStreetMap (Free, no API key needed) -->
<div class="row mt-2">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-map me-2"></i>Facility Map</h4>
                <small class="text-white-50">Powered by OpenStreetMap — Free, no API key needed</small>
            </div>
            <div class="card-body p-0">
                <div id="facilityMap" style="height:450px; width:100%; border-radius:0 0 8px 8px;"></div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Build facility data from PHP ──────────────────────────────
var clubLat = <?php echo CLUB_LAT; ?>;
var clubLng = <?php echo CLUB_LNG; ?>;

var facilityData = <?php
    $jsData = [];
    foreach ($facilities as $f) {
        $meta = $facilityMeta[$f['name']] ?? ['icon' => '📍', 'color' => '#007bff', 'latOff' => 0, 'lngOff' => 0];
        $jsData[] = [
            'name'     => $f['name'],
            'location' => $f['location'],
            'type'     => $f['type'],
            'capacity' => $f['capacity'],
            'color'    => $meta['color'],
            'icon'     => $meta['icon'],
            'lat'      => CLUB_LAT + ($meta['latOff'] ?? 0),
            'lng'      => CLUB_LNG + ($meta['lngOff'] ?? 0),
        ];
    }
    echo json_encode($jsData);
?>;

// ── Initialize Leaflet Map ────────────────────────────────────
var map = L.map('facilityMap').setView([clubLat, clubLng], 17);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19
}).addTo(map);

// ── Club centre marker ────────────────────────────────────────
var clubIcon = L.divIcon({
    html: '<div style="background:#343a40;color:white;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:bold;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.4)">🏆 Apex Sports Club HQ</div>',
    className: '',
    iconAnchor: [60, 20]
});
L.marker([clubLat, clubLng], {icon: clubIcon}).addTo(map);

// ── Facility markers ──────────────────────────────────────────
var markers = {};
facilityData.forEach(function(f) {
    var icon = L.divIcon({
        html: '<div style="background:' + f.color + ';color:white;padding:5px 9px;border-radius:16px;' +
              'font-size:12px;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.3)">' +
              f.icon + ' ' + f.name + '</div>',
        className: '',
        iconAnchor: [60, 16]
    });
    var marker = L.marker([f.lat, f.lng], {icon: icon}).addTo(map);
    marker.bindPopup(
        '<b>' + f.icon + ' ' + f.name + '</b><br>' +
        '<i class="fas fa-map-marker-alt"></i> ' + f.location + '<br>' +
        '<small>Type: ' + f.type + ' &nbsp;|&nbsp; Capacity: ' + f.capacity + '</small>'
    );
    markers[f.name] = marker;
});

// ── Focus a marker from a card button ────────────────────────
function focusMarker(name) {
    if (markers[name]) {
        map.setView(markers[name].getLatLng(), 19);
        markers[name].openPopup();
        document.getElementById('facilityMap').scrollIntoView({behavior: 'smooth'});
    }
}
</script>

<?php include_once("../includes/footer.php"); ?>
