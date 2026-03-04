<?php
// epp_change_history.php - Muestra el historial de cambios de un EPP
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();

// Obtener ID del EPP
$epp_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($epp_id <= 0) {
    header('Location: epp_stock_manage.php');
    exit;
}

$conn = getDBConnection();
$epp_data = null;
$history = [];

if ($conn) {
    // Obtener datos del EPP
    $res = pg_query_params($conn, 'SELECT id, name, price, initial_stock, current_stock FROM epp_items WHERE id = $1', array($epp_id));
    if ($res && pg_num_rows($res) === 1) {
        $epp_data = pg_fetch_assoc($res);
    } else {
        closeDBConnection($conn);
        header('Location: epp_stock_manage.php');
        exit;
    }

    // Obtener historial de cambios
    $hist_res = pg_query_params($conn, 
        'SELECT 
            h.id,
            h.epp_item_id,
            h.user_id,
            h.change_type,
            h.old_value,
            h.new_value,
            h.change_timestamp,
            h.change_description,
            u.username
         FROM epp_change_history h
         LEFT JOIN app_users u ON h.user_id = u.id
         WHERE h.epp_item_id = $1 
         ORDER BY h.change_timestamp DESC',
        array($epp_id)
    );

    if ($hist_res) {
        while ($h = pg_fetch_assoc($hist_res)) {
            $history[] = $h;
        }
    }

    closeDBConnection($conn);
}

// Función para traducir tipos de cambio
function getChangeTypeLabel($type) {
    $types = [
        'CURRENT_STOCK' => 'Stock actual',
        'INITIAL_STOCK' => 'Stock inicial',
        'PRICE' => 'Precio',
        'LIFE_DAYS' => 'Días de vida',
    ];
    return isset($types[$type]) ? $types[$type] : $type;
}

// Función para formatear fecha
function formatDateTime($date_str) {
    if (!$date_str) return '-';
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $date_str);
    if ($date === false) return $date_str;
    return $date->format('d/m/Y H:i:s');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Historial de cambios - <?php echo htmlspecialchars($epp_data['name']); ?></title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background:#f5f5f5; 
            padding:20px; 
        }
        .card { 
            background:#fff; 
            padding:20px; 
            border-radius:8px; 
            box-shadow:0 2px 10px rgba(0,0,0,0.1); 
            max-width:1200px; 
            margin:0 auto; 
        }
        .header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:25px;
            border-bottom:2px solid #9b59b6;
            padding-bottom:15px;
        }
        .header h1 {
            font-size:24px;
            margin:0;
            color:#333;
        }
        .header-right {
            display:flex;
            gap:10px;
        }
        .btn {
            background:#6c757d;
            color:#fff;
            padding:10px 16px;
            border-radius:6px;
            text-decoration:none;
            border:none;
            cursor:pointer;
            font-size:14px;
            display:inline-block;
        }
        .btn:hover {
            background:#5a6268;
        }
        .btn-back {
            background:#6c757d;
        }
        .btn-back:hover {
            background:#5a6268;
        }
        .epp-header {
            background:linear-gradient(135deg, #9b59b6 0%, #6c3483 100%);
            color:#fff;
            padding:20px;
            border-radius:8px;
            margin-bottom:25px;
        }
        .epp-header h2 {
            font-size:20px;
            margin:0 0 15px 0;
        }
        .epp-details {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap:15px;
        }
        .epp-detail-item {
            background:rgba(255,255,255,0.1);
            padding:12px;
            border-radius:6px;
        }
        .epp-detail-label {
            font-size:12px;
            opacity:0.9;
            text-transform:uppercase;
            letter-spacing:0.5px;
        }
        .epp-detail-value {
            font-size:18px;
            font-weight:bold;
            margin-top:5px;
        }
        table {
            width:100%;
            border-collapse:collapse;
            margin-top:15px;
        }
        thead {
            background:#f0f0f0;
        }
        th, td {
            padding:12px 10px;
            border-bottom:1px solid #e0e0e0;
            text-align:left;
            font-size:13px;
        }
        th {
            font-weight:600;
            color:#333;
        }
        tr:hover {
            background:#f9f9f9;
        }
        .badge {
            display:inline-block;
            padding:4px 8px;
            border-radius:3px;
            font-size:12px;
            font-weight:600;
        }
        .badge-current {
            background:#cfe2ff;
            color:#084298;
        }
        .badge-initial {
            background:#d1e7dd;
            color:#0a3622;
        }
        .badge-price {
            background:#fff3cd;
            color:#664d03;
        }
        .badge-life {
            background:#e2e3e5;
            color:#383d41;
        }
        .no-records {
            text-align:center;
            padding:40px;
            color:#666;
        }
        .timestamp {
            color:#666;
            font-size:12px;
        }
        @media print {
            .header-right, .btn-back { display: none !important; }
            body { background: #fff; padding: 0; }
            .card { box-shadow: none; margin: 0; max-width: 100%; border-radius: 0; }
            thead { background: #f0f0f0; }
            tr:hover { background: transparent; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div>
                <h1>Historial de cambios</h1>
            </div>
            <div class="header-right">
                <button onclick="window.print();" class="btn" style="background:#27ae60;">Imprimir</button>
                <a href="epp_stock_manage.php" class="btn btn-back">← Volver</a>
            </div>
        </div>

        <?php if ($epp_data): ?>
        
        <div class="epp-header">
            <h2><?php echo htmlspecialchars($epp_data['name']); ?></h2>
            <div class="epp-details">
                <div class="epp-detail-item">
                    <div class="epp-detail-label">Precio</div>
                    <div class="epp-detail-value">$<?php echo number_format((float)$epp_data['price'], 2, ',', '.'); ?></div>
                </div>
                <div class="epp-detail-item">
                    <div class="epp-detail-label">Stock actual</div>
                    <div class="epp-detail-value"><?php echo (int)$epp_data['current_stock']; ?> unidades</div>
                </div>
                <div class="epp-detail-item">
                    <div class="epp-detail-label">Stock inicial</div>
                    <div class="epp-detail-value"><?php echo (int)$epp_data['initial_stock']; ?> unidades</div>
                </div>
            </div>
        </div>

        <?php if (count($history) === 0): ?>
            <div class="no-records">
                <p>No hay cambios registrados para este EPP</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Tipo de cambio</th>
                        <th>Descripción</th>
                        <th>Valor anterior</th>
                        <th>Valor nuevo</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $record): ?>
                    <tr>
                        <td>
                            <span class="timestamp"><?php echo formatDateTime($record['change_timestamp']); ?></span>
                        </td>
                        <td>
                            <span class="badge badge-<?php 
                                $ct = strtolower($record['change_type']);
                                if ($ct === 'current_stock') echo 'current';
                                elseif ($ct === 'initial_stock') echo 'initial';
                                elseif ($ct === 'price') echo 'price';
                                else echo 'life';
                            ?>">
                                <?php echo htmlspecialchars(getChangeTypeLabel($record['change_type'])); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($record['change_description'] ?: '-'); ?></td>
                        <td><strong><?php echo htmlspecialchars($record['old_value'] ?: '-'); ?></strong></td>
                        <td><strong><?php echo htmlspecialchars($record['new_value'] ?: '-'); ?></strong></td>
                        <td><?php echo $record['username'] ? htmlspecialchars($record['username']) : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php else: ?>
        <div class="no-records">
            <p>No se encontró el EPP solicitado</p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
