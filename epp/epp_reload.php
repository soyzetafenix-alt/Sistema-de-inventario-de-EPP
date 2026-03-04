<?php
// epp_reload.php - procesa recarga de stock para un item EPP
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$epp_id = isset($_POST['epp_item_id']) ? intval($_POST['epp_item_id']) : 0;
$qty = isset($_POST['qty']) ? intval($_POST['qty']) : 0;
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : null;

$errors = [];
if ($epp_id <= 0) $errors[] = 'EPP inválido.';
if ($qty <= 0) $errors[] = 'La cantidad debe ser mayor a 0.';

if (!empty($errors)) {
    $_SESSION['flash_errors'] = $errors;
    header('Location: dashboard.php');
    exit;
}

$conn = getDBConnection();
if (!$conn) {
    $_SESSION['flash_errors'] = ['Error de conexión a la base de datos.'];
    header('Location: dashboard.php');
    exit;
}

try {
    // Llamar a la función reload_epp_stock definida en la BD
    $res = pg_query_params($conn, "SELECT reload_epp_stock($1,$2,$3,$4)", array($epp_id, $qty, $user['id'] ?? null, $reason));
    if ($res === false) {
        throw new Exception(pg_last_error($conn));
    }
    $_SESSION['flash_success'] = 'Stock recargado correctamente.';
    header('Location: dashboard.php');
    exit;
} catch (Exception $e) {
    $_SESSION['flash_errors'] = ['No se pudo recargar stock: ' . $e->getMessage()];
    header('Location: dashboard.php');
    exit;
} finally {
    closeDBConnection($conn);
}
