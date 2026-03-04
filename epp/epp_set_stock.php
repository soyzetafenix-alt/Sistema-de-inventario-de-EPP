<?php
// epp_set_stock.php - set absolute stock (initial_stock and current_stock) to provided value
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$epp_id = isset($_POST['epp_item_id']) ? intval($_POST['epp_item_id']) : 0;
$new_stock = isset($_POST['new_stock']) ? intval($_POST['new_stock']) : -1;
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : null;

$errors = [];
if ($epp_id <= 0) $errors[] = 'EPP inválido.';
if ($new_stock < 0) $errors[] = 'El stock debe ser 0 o mayor.';

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
    pg_query($conn, 'BEGIN');
    // get previous stock FOR UPDATE
    $res = pg_query_params($conn, 'SELECT current_stock FROM epp_items WHERE id = $1 FOR UPDATE', array($epp_id));
    if (!$res || pg_num_rows($res) !== 1) throw new Exception('EPP no encontrado');
    $row = pg_fetch_assoc($res);
    $prev = intval($row['current_stock']);

    // set both initial_stock and current_stock to new_stock (new full level)
    $upd = pg_query_params($conn, 'UPDATE epp_items SET initial_stock = $1, current_stock = $1 WHERE id = $2', array($new_stock, $epp_id));
    if ($upd === false) throw new Exception(pg_last_error($conn));

    // insert movement record: record the change (quantity = new - prev)
    $movement_qty = $new_stock - $prev;
    $movement_type = $movement_qty >= 0 ? 'in' : 'out';
    $abs_qty = abs($movement_qty);
    if ($abs_qty > 0) {
        $ins = pg_query_params($conn, 'INSERT INTO epp_stock_movements (epp_item_id, movement_type, quantity, previous_stock, new_stock, reason) VALUES ($1,$2,$3,$4,$5,$6)', array($epp_id, $movement_type, $abs_qty, $prev, $new_stock, $reason));
        if ($ins === false) throw new Exception(pg_last_error($conn));
    }

    pg_query($conn, 'COMMIT');
    if (isset($_POST['ajax']) && $_POST['ajax']) {
        header('Content-Type: application/json');
        echo json_encode(array('success' => true, 'message' => 'Stock actualizado correctamente.', 'epp_item_id' => $epp_id, 'new_stock' => $new_stock));
        exit;
    }
    $_SESSION['flash_success'] = 'Stock actualizado correctamente.';
    header('Location: dashboard.php');
    exit;
} catch (Exception $e) {
    pg_query($conn, 'ROLLBACK');
    if (isset($_POST['ajax']) && $_POST['ajax']) {
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'message' => 'No se pudo actualizar stock: ' . $e->getMessage()));
        exit;
    }
    $_SESSION['flash_errors'] = ['No se pudo actualizar stock: ' . $e->getMessage()];
    header('Location: dashboard.php');
    exit;
} finally {
    closeDBConnection($conn);
}
