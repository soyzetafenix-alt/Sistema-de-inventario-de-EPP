<?php
// epp_edit.php - editar life_days por cargo y precio de un EPP
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();
$user_id = isset($user['id']) ? intval($user['id']) : 0;
$epp_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
if ($epp_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

$conn = getDBConnection();
if (!$conn) {
    $_SESSION['flash_errors'] = ['Error de conexión a la base de datos.'];
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Obtener datos del formulario
    $price = trim($_POST['price'] ?? '0');
    $life_days_by_position = $_POST['life_days'] ?? []; // Array: job_position_id => life_days
    
    $errors = [];
    if ($price === '' || !is_numeric($price) || floatval($price) < 0) {
        $errors[] = 'Precio inválido.';
    }

    if (!empty($errors)) {
        $_SESSION['flash_errors'] = $errors;
        header('Location: epp_edit.php?id=' . urlencode($epp_id));
        exit;
    }

    try {
        pg_query($conn, "BEGIN");
        
        // Obtener precio antiguo
        $get_old_price = pg_query_params($conn, "SELECT price FROM epp_items WHERE id = $1", array($epp_id));
        $old_price_row = pg_fetch_assoc($get_old_price);
        $old_price = $old_price_row['price'];
        $new_price = number_format(floatval($price), 2, '.', '');
        
        // Actualizar precio en epp_items (si cambió)
        if ($old_price != $new_price) {
            pg_query_params($conn, "UPDATE epp_items SET price = $1 WHERE id = $2", array($new_price, $epp_id));
            
            // Registrar cambio de precio en historial
            $change_desc = 'Precio: $' . $old_price . ' → $' . $new_price;
            pg_query_params($conn, 
                'INSERT INTO epp_change_history (epp_item_id, user_id, change_type, old_value, new_value, change_description) 
                 VALUES ($1, $2, $3, $4, $5, $6)',
                array($epp_id, $user_id, 'PRICE', $old_price, $new_price, $change_desc)
            );
        }
        
        // Procesar cambios de life_days por cargo
        if (!empty($life_days_by_position)) {
            foreach ($life_days_by_position as $job_pos_id => $new_life_days) {
                $job_pos_id = intval($job_pos_id);
                $new_life_days = trim($new_life_days);
                $new_life_days_val = ($new_life_days === '' ? null : intval($new_life_days));
                
                // Obtener valor anterior
                $get_old = pg_query_params($conn, 
                    "SELECT life_days FROM epp_item_job_position WHERE epp_item_id = $1 AND job_position_id = $2",
                    array($epp_id, $job_pos_id)
                );
                
                if (pg_num_rows($get_old) > 0) {
                    // Existe, obtener valor antiguo
                    $old_row = pg_fetch_assoc($get_old);
                    $old_life_days = $old_row['life_days'];
                    
                    if ($old_life_days != $new_life_days_val) {
                        // Actualizar
                        pg_query_params($conn, 
                            "UPDATE epp_item_job_position SET life_days = $1, updated_at = now() 
                             WHERE epp_item_id = $2 AND job_position_id = $3",
                            array($new_life_days_val, $epp_id, $job_pos_id)
                        );
                        
                        // Obtener nombre del cargo
                        $get_pos_name = pg_query_params($conn, "SELECT name FROM job_positions WHERE id = $1", array($job_pos_id));
                        $pos_name_row = pg_fetch_assoc($get_pos_name);
                        $pos_name = $pos_name_row['name'] ?? 'Cargo #' . $job_pos_id;
                        
                        // Registrar cambio
                        $change_desc = "[$pos_name] Tiempo de Vida: " . ($old_life_days ?: 'sin especificar') . " → " . ($new_life_days_val ?: 'sin especificar');
                        pg_query_params($conn, 
                            'INSERT INTO epp_change_history (epp_item_id, user_id, change_type, old_value, new_value, change_description) 
                             VALUES ($1, $2, $3, $4, $5, $6)',
                            array($epp_id, $user_id, 'Tiempo de vida', $old_life_days ?: '', $new_life_days_val ?: '', $change_desc)
                        );
                    }
                } else if ($new_life_days_val !== null) {
                    // No existe pero se intenta crear uno
                    pg_query_params($conn,
                        "INSERT INTO epp_item_job_position (epp_item_id, job_position_id, life_days, created_at, updated_at)
                         VALUES ($1, $2, $3, now(), now())",
                        array($epp_id, $job_pos_id, $new_life_days_val)
                    );
                    
                    // Obtener nombre del cargo
                    $get_pos_name = pg_query_params($conn, "SELECT name FROM job_positions WHERE id = $1", array($job_pos_id));
                    $pos_name_row = pg_fetch_assoc($get_pos_name);
                    $pos_name = $pos_name_row['name'] ?? 'Cargo #' . $job_pos_id;
                    
                    // Registrar cambio
                    $change_desc = "[$pos_name] Tiempo de Vida asignado: " . $new_life_days_val;
                    pg_query_params($conn,
                        'INSERT INTO epp_change_history (epp_item_id, user_id, change_type, old_value, new_value, change_description)
                         VALUES ($1, $2, $3, $4, $5, $6)',
                        array($epp_id, $user_id, 'Tiempo de vida', '', $new_life_days_val, $change_desc)
                    );
                }
            }
        }
        
        pg_query($conn, "COMMIT");
        closeDBConnection($conn);
        $_SESSION['flash_success'] = 'EPP actualizado correctamente.';
        header('Location: epp_stock_manage.php');
        exit;
    } catch (Exception $e) {
        pg_query($conn, "ROLLBACK");
        closeDBConnection($conn);
        $_SESSION['flash_errors'] = ['No se pudo actualizar: ' . $e->getMessage()];
        header('Location: epp_edit.php?id=' . urlencode($epp_id));
        exit;
    }
} else {
    // GET - cargar datos del EPP y todos los cargos con sus life_days
    $res = pg_query_params($conn, "SELECT id, name, price, initial_stock, current_stock FROM epp_items WHERE id = $1", array($epp_id));
    if (!$res || pg_num_rows($res) !== 1) {
        closeDBConnection($conn);
        $_SESSION['flash_errors'] = ['EPP no encontrado.'];
        header('Location: dashboard.php');
        exit;
    }
    $epp = pg_fetch_assoc($res);
    
    // Obtener todos los cargos
    $pos_res = pg_query($conn, "SELECT id, name FROM job_positions ORDER BY name");
    $positions = pg_fetch_all($pos_res) ?: [];
    
    // Obtener life_days actual para cada cargo
    $life_days_map = [];
    $jpos_res = pg_query_params($conn, "SELECT job_position_id, life_days FROM epp_item_job_position WHERE epp_item_id = $1", array($epp_id));
    if ($jpos_res) {
        while ($row = pg_fetch_assoc($jpos_res)) {
            $life_days_map[$row['job_position_id']] = $row['life_days'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Editar EPP</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; margin: 0; }
        .container { max-width: 900px; margin: 30px auto; padding: 20px; }
        .card { background: #fff; padding: 25px; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
        h2 { margin: 0 0 20px 0; color: #333; font-size: 24px; }
        .form-group { margin-bottom: 25px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 14px; }
        input[type="text"], input[type="number"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
        input[type="text"]:focus, input[type="number"]:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0,102,204,0.1); }
        input[readonly] { background: #f8f8f8; cursor: not-allowed; }
        
        .table-wrapper { margin-bottom: 25px; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f8f8; }
        th { padding: 12px; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0; font-size: 13px; }
        td { padding: 12px; border-bottom: 1px solid #e8e8e8; font-size: 14px; }
        tbody tr:nth-child(even) { background: #fafafa; }
        tbody tr:hover { background: #f0f7ff; }
        td input[type="number"] { width: 90px; text-align: center; }
        
        .stock-info { font-size: 14px; color: #666; padding: 10px; background: #f9f9f9; border-left: 3px solid #0066cc; border-radius: 2px; }
        .stock-info strong { color: #333; }
        
        .alert { padding: 12px 15px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #c62828; }
        .alert-error ul { margin: 5px 0 0 20px; padding-left: 0; }
        .alert-error li { margin: 5px 0; }
        
        .actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px; }
        .btn { padding: 11px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; font-size: 14px; }
        .btn-primary { background: #0066cc; color: #fff; }
        .btn-primary:hover { background: #0052a3; }
        .btn-secondary { background: #e8e8e8; color: #333; border: 1px solid #ccc; }
        .btn-secondary:hover { background: #ddd; }
        .btn a { color: inherit; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>Editar EPP: <?php echo htmlspecialchars($epp['name']); ?></h2>

            <?php if (!empty($_SESSION['flash_errors'])): ?>
                <div class="alert alert-error">
                    <strong>Errores:</strong>
                    <ul>
                        <?php foreach($_SESSION['flash_errors'] as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php unset($_SESSION['flash_errors']); ?>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Nombre (no editable)</label>
                    <input type="text" value="<?php echo htmlspecialchars($epp['name']); ?>" readonly>
                </div>

                <div class="form-group">
                    <label for="price">Precio (USD)</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($epp['price']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Días de Vida Útil por Cargo</label>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cargo</th>
                                    <th style="width: 120px; text-align: center;">Días</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($positions as $pos): 
                                    $pos_id = intval($pos['id']);
                                    $current_days = isset($life_days_map[$pos_id]) ? intval($life_days_map[$pos_id]) : '';
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pos['name']); ?></td>
                                        <td style="text-align: center;">
                                            <input type="number" name="life_days[<?php echo $pos_id; ?>]" 
                                                   step="1" min="1"
                                                   value="<?php echo $current_days; ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="stock-info">
                    <strong>Stock:</strong> <?php echo intval($epp['current_stock']); ?> actual 
                    <strong style="margin-left: 20px;">Inicial:</strong> <?php echo intval($epp['initial_stock']); ?>
                </div>

                <div class="actions">
                    <a href="epp_stock_manage.php" class="btn btn-secondary">← Volver</a>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
