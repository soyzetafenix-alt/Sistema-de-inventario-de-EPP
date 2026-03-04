<?php
// epps_store.php - Procesa el alta de un nuevo EPP en el catálogo
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: epps_new.php');
    exit;
}

$errors = [];

$name = trim($_POST['name'] ?? '');
$initial_stock = trim($_POST['initial_stock'] ?? '0');
$price = trim($_POST['price'] ?? '0');

// Save old values for repopulating the form
$_SESSION['flash_old'] = [
    'name' => $name,
    'initial_stock' => $initial_stock,
    'price' => $price
];

// Basic validations
if ($name === '') {
    $errors[] = 'El nombre de la EPP es obligatorio.';
}

if ($initial_stock === '' || !is_numeric($initial_stock) || intval($initial_stock) < 0) {
    $errors[] = 'El stock inicial debe ser un número entero mayor o igual a 0.';
}

if ($price === '' || !is_numeric($price) || floatval($price) < 0) {
    $errors[] = 'El precio debe ser un número mayor o igual a 0.';
}

if (!empty($errors)) {
    $_SESSION['flash_errors'] = $errors;
    header('Location: epps_new.php');
    exit;
}

$conn = getDBConnection();
if (!$conn) {
    $_SESSION['flash_errors'] = ['Error de conexión a la base de datos.'];
    header('Location: epps_new.php');
    exit;
}

try {
    // Verificar nombre duplicado (case-insensitive)
    $dupQ = "SELECT id FROM epp_items WHERE LOWER(name) = LOWER($1)";
    $dupR = pg_query_params($conn, $dupQ, array($name));
    if ($dupR && pg_num_rows($dupR) > 0) {
        $_SESSION['flash_errors'] = ['Ya existe un EPP con ese nombre en el catálogo.'];
        header('Location: epps_new.php');
        exit;
    }

    // Insert into epp_items: life_days is now NULL (defined per job_position in epp_item_job_position)
    $insertQuery = "INSERT INTO epp_items (name, life_days, price, initial_stock, current_stock) VALUES ($1, NULL, $2, $3, $3) RETURNING id";
    $params = array($name, number_format(floatval($price), 2, '.', ''), intval($initial_stock));

    $result = pg_query_params($conn, $insertQuery, $params);
    if (!$result) {
        throw new Exception('Error al insertar el EPP en la base de datos.');
    }

    $newEpp = pg_fetch_assoc($result);
    $eppId = $newEpp['id'];

    unset($_SESSION['flash_old']);
    $_SESSION['flash_success'] = 'EPP registrado correctamente en el catálogo.';
    
    // Redirect to assign lifespan by job position
    header('Location: epps_assign_lifespan.php?id=' . urlencode((string)$eppId));
    exit;

} catch (Exception $e) {
    error_log('Error al registrar EPP: ' . $e->getMessage());
    $_SESSION['flash_errors'] = ['Ocurrió un error inesperado al registrar el EPP.'];
    header('Location: epps_new.php');
    exit;
} finally {
    closeDBConnection($conn);
}
