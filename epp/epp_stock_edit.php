<?php
// epp_stock_edit.php - Interfaz para editar stock actual y stock inicial
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();
$user_id = isset($user['id']) ? intval($user['id']) : 0;

// Obtener ID del EPP
$epp_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($epp_id <= 0) {
    header('Location: epp_stock_manage.php');
    exit;
}

$conn = getDBConnection();
$epp_data = null;

if ($conn) {
    $res = pg_query_params($conn, 'SELECT id, name, price, initial_stock, current_stock FROM epp_items WHERE id = $1', array($epp_id));
    if ($res && pg_num_rows($res) === 1) {
        $epp_data = pg_fetch_assoc($res);
    } else {
        closeDBConnection($conn);
        header('Location: epp_stock_manage.php');
        exit;
    }
    closeDBConnection($conn);
}

// Procesar formulario POST
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $conn = getDBConnection();
    
    if ($action === 'update_current_stock' && $conn) {
        $adjustment = isset($_POST['adjustment']) ? intval($_POST['adjustment']) : 0;
        $new_current = (int)$epp_data['current_stock'] + $adjustment;
        
        if ($new_current < 0) {
            $error = 'El stock no puede ser negativo';
        } else {
            $old_value = $epp_data['current_stock'];
            $upd = pg_query_params($conn, 'UPDATE epp_items SET current_stock = $1 WHERE id = $2', array($new_current, $epp_id));
            
            if ($upd) {
                // Registrar en historial
                $change_desc = $adjustment > 0 ? 'Incremento' : 'Decremento';
                $change_desc .= ' de ' . abs($adjustment) . ' unidades';
                
                pg_query_params($conn, 
                    'INSERT INTO epp_change_history (epp_item_id, user_id, change_type, old_value, new_value, change_description) 
                     VALUES ($1, $2, $3, $4, $5, $6)',
                    array($epp_id, $user_id, 'CURRENT_STOCK', $old_value, $new_current, $change_desc)
                );
                
                $epp_data['current_stock'] = $new_current;
                $success = 'Stock actual actualizado exitosamente';
            } else {
                $error = 'Error al actualizar el stock';
            }
        }
    } elseif ($action === 'update_initial_stock' && $conn) {
        $new_initial = isset($_POST['new_initial']) ? intval($_POST['new_initial']) : 0;
        
        if ($new_initial < 0) {
            $error = 'El stock inicial no puede ser negativo';
        } else {
            $old_value = $epp_data['initial_stock'];
            $upd = pg_query_params($conn, 'UPDATE epp_items SET initial_stock = $1 WHERE id = $2', array($new_initial, $epp_id));
            
            if ($upd) {
                // Registrar en historial
                $change_desc = 'Stock inicial modificado de ' . $old_value . ' a ' . $new_initial;
                
                pg_query_params($conn, 
                    'INSERT INTO epp_change_history (epp_item_id, user_id, change_type, old_value, new_value, change_description) 
                     VALUES ($1, $2, $3, $4, $5, $6)',
                    array($epp_id, $user_id, 'INITIAL_STOCK', $old_value, $new_initial, $change_desc)
                );
                
                $epp_data['initial_stock'] = $new_initial;
                $success = 'Stock inicial actualizado exitosamente';
            } else {
                $error = 'Error al actualizar el stock inicial';
            }
        }
    }
    
    if ($conn) closeDBConnection($conn);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Editar stock - <?php echo htmlspecialchars($epp_data['name']); ?></title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background:#f5f5f5; 
            padding:20px; 
        }
        .card { 
            background:#fff; 
            padding:30px; 
            border-radius:8px; 
            box-shadow:0 2px 10px rgba(0,0,0,0.1); 
            max-width:600px; 
            margin:0 auto; 
        }
        .header {
            margin-bottom:30px;
            border-bottom:2px solid #0066cc;
            padding-bottom:15px;
        }
        .header h1 {
            font-size:24px;
            margin:0 0 5px 0;
            color:#333;
        }
        .header p {
            color:#666;
            font-size:14px;
            margin:0;
        }
        .epp-info {
            background:#f9f9f9;
            padding:15px;
            border-radius:6px;
            margin-bottom:25px;
            border-left:4px solid #0066cc;
        }
        .epp-info div {
            margin:8px 0;
            font-size:14px;
        }
        .epp-info strong {
            color:#333;
        }
        .section {
            margin-bottom:30px;
        }
        .section h2 {
            font-size:16px;
            font-weight:600;
            color:#333;
            margin:0 0 15px 0;
            padding-bottom:10px;
            border-bottom:1px solid #eee;
        }
        .form-group {
            display:flex;
            flex-direction:column;
            gap:8px;
            margin-bottom:15px;
        }
        .form-group label {
            font-size:13px;
            font-weight:600;
            color:#333;
        }
        .form-group input {
            padding:10px;
            border:1px solid #ddd;
            border-radius:4px;
            font-size:14px;
        }
        .form-group input:focus {
            outline:none;
            border-color:#0066cc;
            box-shadow:0 0 0 3px rgba(0,102,204,0.1);
        }
        .button-group {
            display:flex;
            gap:10px;
            margin-top:15px;
        }
        .btn {
            padding:10px 16px;
            border-radius:4px;
            border:none;
            cursor:pointer;
            font-size:14px;
            font-weight:600;
            flex:1;
        }
        .btn-primary {
            background:#0066cc;
            color:#fff;
        }
        .btn-primary:hover {
            background:#0052a3;
        }
        .btn-secondary {
            background:#6c757d;
            color:#fff;
        }
        .btn-secondary:hover {
            background:#5a6268;
        }
        .alert {
            padding:12px 16px;
            border-radius:4px;
            margin-bottom:20px;
            font-size:14px;
        }
        .alert-success {
            background:#d4edda;
            color:#155724;
            border:1px solid #c3e6cb;
        }
        .alert-error {
            background:#f8d7da;
            color:#721c24;
            border:1px solid #f5c6cb;
        }
        .input-helper {
            font-size:12px;
            color:#666;
            margin-top:5px;
        }
        a {
            color:#0066cc;
            text-decoration:none;
        }
        a:hover {
            text-decoration:underline;
        }
        .back-link {
            display:block;
            margin-bottom:20px;
        }
    </style>
</head>
<body>
    <div class="card">
        <a href="epp_stock_manage.php" class="back-link">← Volver a gestión de stock</a>

        <div class="header">
            <h1>Editar stock de EPP</h1>
            <p>Ajusta el stock actual o establece un nuevo stock inicial</p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($epp_data): ?>
        
        <div class="epp-info">
            <div><strong>Nombre:</strong> <?php echo htmlspecialchars($epp_data['name']); ?></div>
            <div><strong>Precio:</strong> $<?php echo number_format((float)$epp_data['price'], 2, ',', '.'); ?></div>
            <div><strong>Stock actual:</strong> <span style="color:#0066cc; font-weight:bold;"><?php echo (int)$epp_data['current_stock']; ?></span> unidades</div>
            <div><strong>Stock inicial:</strong> <span style="color:#27ae60; font-weight:bold;"><?php echo (int)$epp_data['initial_stock']; ?></span> unidades</div>
        </div>

        <!-- Sección: Modificar stock actual -->
        <div class="section">
            <h2>Modificar stock actual</h2>
            <p style="color:#666; font-size:13px; margin:0 0 15px 0;">Aumenta o disminuye el stock disponible actualmente</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_current_stock">
                
                <div class="form-group">
                    <label for="adjustment">Cantidad a ajustar:</label>
                    <input type="number" id="adjustment" name="adjustment" value="0" required>
                    <div class="input-helper">
                        Ingresa un número positivo para aumentar, negativo para disminuir
                        (Ej: +5 para agregar 5 unidades, -3 para quitar 3)
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn btn-primary">Guardar cambio de stock</button>
                </div>
            </form>
        </div>

        <!-- Sección: Cambiar stock inicial -->
        <div class="section">
            <h2>Establecer nuevo stock inicial</h2>
            <p style="color:#666; font-size:13px; margin:0 0 15px 0;">Define el stock inicial (100%) para este EPP</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_initial_stock">
                
                <div class="form-group">
                    <label for="new_initial">Nuevo stock inicial:</label>
                    <input type="number" id="new_initial" name="new_initial" value="<?php echo (int)$epp_data['initial_stock']; ?>" min="0" required>
                    <div class="input-helper">
                        El stock inicial define el 100% de disponibilidad
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn btn-primary">Establecer stock inicial</button>
                </div>
            </form>
        </div>

        <?php else: ?>
        <div class="alert alert-error">No se encontró el EPP solicitado</div>
        <?php endif; ?>
    </div>
</body>
</html>
