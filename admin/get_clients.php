<?php
session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'hr', 'auditor'], true)) {
    echo json_encode([]);
    exit;
}

$company_id = (int)get_company_id();
$search = isset($_GET['q']) ? $_GET['q'] : '';

$query = "SELECT * FROM clients WHERE company_id = $company_id";
if ($search !== '') {
    $search = $conn->real_escape_string($search);
    $query .= " AND (name LIKE '%$search%' OR phone LIKE '%$search%' OR client_company LIKE '%$search%')";
}
$query .= " LIMIT 10";

$result = $conn->query($query);
$clients = [];
while ($row = $result->fetch_assoc()) {
    $clients[] = $row;
}

echo json_encode($clients);
?>
