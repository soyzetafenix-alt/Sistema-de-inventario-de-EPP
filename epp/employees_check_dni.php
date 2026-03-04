<?php
// employees_check_dni.php - Verifica si un DNI ya existe (uso AJAX)
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

header('Content-Type: application/json');

$dni = trim($_GET['dni'] ?? '');

if ($dni === '' || !preg_match('/^\d{8}$/', $dni)) {
    echo json_encode([
        'success' => false,
        'message' => 'DNI inválido.',
        'exists'  => false
    ]);
    exit;
}

$conn = getDBConnection();

if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión.',
        'exists'  => false
    ]);
    exit;
}

$exists = false;

$query = "SELECT 1 FROM employees WHERE dni = $1 LIMIT 1";
$result = pg_query_params($conn, $query, array($dni));

if ($result && pg_num_rows($result) > 0) {
    $exists = true;
}

closeDBConnection($conn);

echo json_encode([
    'success' => true,
    'exists'  => $exists
]);

