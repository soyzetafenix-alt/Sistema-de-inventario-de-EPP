<?php
// epp_report.php - Reporte detallado de uso de EPP por empleado
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();

// Obtener el ID del EPP
$epp_id = isset($_GET['epp_id']) ? intval($_GET['epp_id']) : 0;

// Obtener filtros - AGREGAMOS TRIM() para evitar espacios vacíos accidentales
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$job_position = isset($_GET['job_position']) ? trim($_GET['job_position']) : ''; 
$show_only_incumplimiento = isset($_GET['show_only_incumplimiento']) && $_GET['show_only_incumplimiento'] === '1';

// Validar que el EPP existe
$conn = getDBConnection();
$epp_name = '';
$epp_data = null;
$epp_price = 0;

if ($conn && $epp_id > 0) {
    $res = pg_query_params($conn, 'SELECT id, name, price FROM epp_items WHERE id = $1', array($epp_id));
    if ($res && pg_num_rows($res) === 1) {
        $epp_data = pg_fetch_assoc($res);
        $epp_name = $epp_data['name'];
        $epp_price = floatval($epp_data['price']);
    } else {
        $epp_id = 0;
    }
}

// Construir la consulta de datos
$records = [];
$job_positions = [];

if ($conn && $epp_id > 0) {
    // Obtener lista de cargos
    $positions_res = pg_query_params($conn, 
        'SELECT DISTINCT e.position FROM employees e 
         JOIN epp_records er ON e.id = er.employee_id 
         WHERE er.epp_item_id = $1 AND e.position IS NOT NULL AND e.position != \'\' 
         ORDER BY e.position', 
        array($epp_id));
        
    if ($positions_res) {
        while ($p = pg_fetch_assoc($positions_res)) {
            $job_positions[] = $p['position'];
        }
    }

    // Construir consulta de registros
    $where = ['er.epp_item_id = $1'];
    $params = [$epp_id];
    $param_idx = 2;

    if ($date_from !== '') {
        $where[] = "er.delivery_date >= \$" . $param_idx;
        $params[] = $date_from;
        $param_idx++;
    }

    if ($date_to !== '') {
        $where[] = "er.delivery_date <= \$" . $param_idx;
        $params[] = $date_to;
        $param_idx++;
    }

    // Lógica del filtro de cargo
    if ($job_position !== '') {
        $where[] = "e.position = \$" . $param_idx;
        $params[] = $job_position;
        $param_idx++;
    }

    $sql = "SELECT 
            er.id,
            er.employee_id,
            er.epp_item_id,
            er.delivery_date,
            er.price_at_delivery,
            er.lifespan_status,
            COALESCE(er.condition, 'Nuevo') AS condition,
            e.first_name,
            e.last_name,
            e.position,
            e.area,
            e.dni,
            ei.name as epp_name
        FROM epp_records er
        JOIN employees e ON er.employee_id = e.id
        JOIN epp_items ei ON er.epp_item_id = ei.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY er.delivery_date ASC, e.last_name, e.first_name";

    $res = pg_query_params($conn, $sql, $params);
    if ($res) {
        while ($r = pg_fetch_assoc($res)) {
            $records[] = $r;
        }
    }

    // Agrupar registros
    $records_by_emp_item = [];
    foreach ($records as $r) {
        $key = $r['employee_id'] . '_' . $r['epp_item_id'];
        if (!isset($records_by_emp_item[$key])) {
            $records_by_emp_item[$key] = [];
        }
        $records_by_emp_item[$key][] = $r;
    }

    // NO RECALCULAR ESTADOS EN PHP
    // El trigger trg_recalc_after_insert en la BD recalcula lifespan_status de TODOS
    // los registros relacionados (mismo empleado + EPP) cuando se inserta uno nuevo.
    // Los reportes usan DIRECTAMENTE el valor guardado en BD.

    // Aplicar filtro de incumplimiento
    if ($show_only_incumplimiento) {
        $records = array_filter($records, function($r) {
            return $r['lifespan_status'] === 'INCUMPLIMIENTO';
        });
        $records = array_values($records);
    }

    // Ordenar
    usort($records, function($a, $b) {
        $cmp = strcmp($b['delivery_date'], $a['delivery_date']);
        if ($cmp !== 0) return $cmp;
        return strcmp($a['last_name'], $b['last_name']);
    });

    closeDBConnection($conn);
}
// ... El resto del archivo (HTML) sigue igual ...
// Función para formatear fecha
function formatDate($date_str) {
    if (!$date_str) return '-';
    $date = DateTime::createFromFormat('Y-m-d', $date_str);
    if ($date === false) return $date_str;
    return $date->format('d/m/Y');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reporte de EPP - <?php echo htmlspecialchars($epp_name); ?></title>
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
            max-width:1400px; 
            margin:0 auto; 
        }
        .header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:20px;
        }
        .header h1 {
            font-size:24px;
            margin:0;
        }
        .header p {
            color:#666;
            font-size:14px;
            margin:5px 0 0 0;
        }
        .btn-back {
            background:#6c757d;
            color:#fff;
            padding:10px 16px;
            border-radius:6px;
            text-decoration:none;
            border:none;
            cursor:pointer;
            font-size:14px;
            display:inline-block;
            margin-left:8px;
        }
        .btn-back:hover {
            background:#5a6268;
        }
        .btn-print {
            background:#28a745;
            color:#fff;
            padding:10px 16px;
            border-radius:6px;
            border:none;
            cursor:pointer;
            font-size:14px;
            display:inline-block;
        }
        .btn-print:hover {
            background:#218838;
        }

        /* Estilos específicos para impresión: ocultar controles y adaptar layout */
        @media print {
            .btn-back, .btn-print, .filters, .stats, .checkbox-wrapper { display: none !important; }
            body { background: #fff; padding: 0; }
            .card { box-shadow: none; margin: 0; max-width: 100%; border-radius: 0; }
            thead { background: #f0f0f0; }
            tr:hover { background: transparent; }
        }
        .filters {
            background:#f9f9f9;
            padding:15px;
            border-radius:6px;
            margin-bottom:20px;
            border:1px solid #e9e9e9;
        }
        .filter-row {
            display:grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap:12px;
            margin-bottom:12px;
        }
        .filter-row.full {
            grid-template-columns: 1fr;
        }
        .filter-group {
            display:flex;
            flex-direction:column;
            gap:5px;
        }
        .filter-group label {
            font-size:13px;
            font-weight:600;
            color:#333;
        }
        .filter-group input,
        .filter-group select {
            padding:8px;
            border:1px solid #ddd;
            border-radius:4px;
            font-size:13px;
        }
        .filter-actions {
            display:flex;
            gap:8px;
        }
        .btn-filter {
            background:#0066cc;
            color:#fff;
            padding:8px 16px;
            border-radius:4px;
            border:none;
            cursor:pointer;
            font-size:13px;
            font-weight:600;
        }
        .btn-filter:hover {
            background:#0052a3;
        }
        .btn-reset {
            background:#6c757d;
            color:#fff;
            padding:8px 16px;
            border-radius:4px;
            border:none;
            cursor:pointer;
            font-size:13px;
        }
        .btn-reset:hover {
            background:#5a6268;
        }
        .stats {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap:12px;
            margin-bottom:20px;
        }
        .stat-card {
            background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color:#fff;
            padding:15px;
            border-radius:6px;
            text-align:center;
        }
        .stat-value {
            font-size:24px;
            font-weight:bold;
            margin-bottom:5px;
        }
        .stat-label {
            font-size:13px;
            opacity:0.9;
        }
        table {
            width:100%;
            border-collapse:collapse;
            margin-top:15px;
        }
        thead {
            background:#f0f0f0;
        }
        th,td {
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
        .status-cumplido {
            background:#d4edda;
            color:#155724;
            padding:4px 8px;
            border-radius:3px;
            font-size:12px;
            font-weight:600;
        }
        .status-incumplimiento {
            background:#f8d7da;
            color:#721c24;
            padding:4px 8px;
            border-radius:3px;
            font-size:12px;
            font-weight:600;
        }
        .badge-nuevo {
            background:#e7f3ff;
            color:#0066cc;
            padding:4px 8px;
            border-radius:3px;
            font-size:12px;
            font-weight:600;
        }
        .badge-usado {
            background:#fff3cd;
            color:#856404;
            padding:4px 8px;
            border-radius:3px;
            font-size:12px;
            font-weight:600;
        }
        .no-records {
            text-align:center;
            padding:40px;
            color:#666;
        }
        .checkbox-wrapper {
            display:flex;
            align-items:center;
            gap:8px;
            margin-top:10px;
        }
        .checkbox-wrapper input[type="checkbox"] {
            cursor:pointer;
            width:18px;
            height:18px;
        }
        .checkbox-wrapper label {
            margin:0;
            cursor:pointer;
            font-weight:normal;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div>
                <h1>Reporte de Uso - <?php echo htmlspecialchars($epp_name ?: 'EPP no encontrado'); ?></h1>
                <p>Historial detallado de entrega y cumplimiento de plazos</p>
                <?php if ($date_from !== '' || $date_to !== '' || $job_position !== '' || $show_only_incumplimiento): ?>
                <p style="color:#666; font-size:13px; margin-top:8px;">
                    Filtros activos: 
                    <?php if ($date_from !== '') echo "Desde: " . htmlspecialchars($date_from) . " | "; ?>
                    <?php if ($date_to !== '') echo "Hasta: " . htmlspecialchars($date_to) . " | "; ?>
                    <?php if ($job_position !== '') echo "Cargo: " . htmlspecialchars($job_position) . " | "; ?>
                    <?php if ($show_only_incumplimiento) echo "Solo incumplimientos"; ?>
                </p>
                <?php endif; ?>
            </div>
            <div>
                <a href="epp_stock_manage.php" class="btn-back">← Volver</a>
                <button id="btnPrint" class="btn-print" type="button">Imprimir reporte</button>
            </div>
        </div>

        <?php if ($epp_id <= 0): ?>
            <div class="no-records">
                <p>El EPP solicitado no existe. <a href="epp_stock_manage.php">Volver a la gestión de stock</a></p>
            </div>
        <?php else: ?>
            <div class="filters">
                <form method="GET" action="epp_report.php">
                    <input type="hidden" name="epp_id" value="<?php echo $epp_id; ?>">
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="date_from">Fecha desde:</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="date_to">Fecha hasta:</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="job_position">Cargo:</label>
                            <select id="job_position" name="job_position">
                                <option value="">-- Todos los cargos --</option>
                                <?php foreach ($job_positions as $pos): ?>
                                    <option value="<?php echo htmlspecialchars($pos); ?>" <?php echo $job_position === $pos ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pos); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn-filter">Filtrar</button>
                        </div>
                    </div>

                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="show_only_incumplimiento" name="show_only_incumplimiento" value="1" 
                            <?php echo $show_only_incumplimiento ? 'checked' : ''; ?>>
                        <label for="show_only_incumplimiento">Mostrar solo incumplimientos</label>
                    </div>
                </form>
            </div>

            <?php 
                $total_records = count($records);
                $incumplimientos = count(array_filter($records, function($r) { return $r['lifespan_status'] === 'INCUMPLIMIENTO'; }));
                $cumplidos = $total_records - $incumplimientos;
                // Calcular total gastado usando precios históricos (price_at_delivery) de cada registro
                $total_gastado = 0.0;
                foreach ($records as $r) {
                    $price = floatval($r['price_at_delivery'] ?? 0);
                    $total_gastado += $price;
                }
            ?>
            
            <div class="stats">
                <div class="stat-card" style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="stat-value"><?php echo $total_records; ?></div>
                    <div class="stat-label">Total de registros</div>
                </div>
                <div class="stat-card" style="background:linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stat-value"><?php echo $incumplimientos; ?></div>
                    <div class="stat-label">Incumplimientos</div>
                </div>
                <div class="stat-card" style="background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stat-value"><?php echo $cumplidos; ?></div>
                    <div class="stat-label">Cumplidos</div>
                </div>
                <div class="stat-card" style="background:linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <div class="stat-value">$<?php echo number_format($total_gastado, 2, ',', '.'); ?></div>
                    <div class="stat-label">Total gastado</div>
                </div>
            </div>

            <?php if (count($records) === 0): ?>
                <div class="no-records">
                    <p>No hay registros que coincidan con los filtros aplicados.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>DNI</th>
                            <th>Cargo</th>
                            <th>Área</th>
                            <th>Fecha de entrega</th>
                            <th>Próxima fecha permitida</th>
                            <th>Condición</th>
                            <th>Precio</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $rec): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($rec['dni']); ?></td>
                            <td><?php echo htmlspecialchars($rec['position']); ?></td>
                            <td><?php echo htmlspecialchars($rec['area']); ?></td>
                            <td><?php echo formatDate($rec['delivery_date']); ?></td>
                            <td><?php echo formatDate($rec['next_allowed_date']); ?></td>
                            <td>
                                <span class="badge-<?php echo strtolower($rec['condition']); ?>">
                                    <?php echo htmlspecialchars($rec['condition']); ?>
                                </span>
                            </td>
                            <td>$<?php echo number_format(floatval($rec['price_at_delivery'] ?? 0), 2, ',', '.'); ?></td>
                            <td>
                                <span class="status-<?php echo strtolower($rec['lifespan_status']); ?>">
                                    <?php echo htmlspecialchars($rec['lifespan_status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <script>
        (function(){
            var btn = document.getElementById('btnPrint');
            if (!btn) return;
            btn.addEventListener('click', function(e){
                e.preventDefault();
                // Llamar al diálogo de impresión del navegador. El usuario podrá elegir "Guardar como PDF".
                window.print();
            });
        })();
    </script>
</body>
</html>
