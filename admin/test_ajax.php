<?php
// Simple test file to verify AJAX is working
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'AJAX test successful', 'timestamp' => date('Y-m-d H:i:s')]);