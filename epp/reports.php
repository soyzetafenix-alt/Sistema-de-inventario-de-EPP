<?php
// reports.php - Módulo general de reportes con múltiples filtros
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();

// Obtener filtros - sanitizados correctamente
$selected_epp = (isset($_GET['epp_id']) && is_numeric($_GET['epp_id'])) ? intval($_GET['epp_id']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$job_position = isset($_GET['job_position']) ? trim($_GET['job_position']) : '';
$selected_reason = isset($_GET['reason']) ? trim($_GET['reason']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'all';

// Motivos disponibles (ENUM)
$reasons = [
    'DOTACION PLANTA',
    'DOTACION SERVICIO',
    'DOTACION PROYECTOS',
    'CAMBIO PLANTA',
    'CAMBIO SERVICIOS',
    'CAMBIO PROYECTOS',
    'PERDIDA PLANTA',
    'PERDIDA SERVICIOS',
    'PERDIDA PROYECTOS'
];

$conn = getDBConnection();
$records = [];
$epp_list = [];
$job_positions = [];

if ($conn) {
    // Obtener lista de EPP
    $res = pg_query($conn, 'SELECT id, name FROM epp_items ORDER BY name');
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $epp_list[] = $row;
        }
    }

    // Obtener lista de cargos únicos
    $res = pg_query_params($conn, 
        'SELECT DISTINCT e.position FROM employees e 
         WHERE e.position IS NOT NULL AND e.position != \'\' 
         ORDER BY e.position', 
        []);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $job_positions[] = $row['position'];
        }
    }

    // Construir consulta con múltiples filtros
    $where = [];
    $params = [];
    $param_idx = 1;

    // Filtro por EPP
    if ($selected_epp > 0) {
        $where[] = "er.epp_item_id = \$" . $param_idx;
        $params[] = $selected_epp;
        $param_idx++;
    }

    // Filtro por fecha desde
    if ($date_from !== '') {
        $where[] = "er.delivery_date >= \$" . $param_idx;
        $params[] = $date_from;
        $param_idx++;
    }

    // Filtro por fecha hasta
    if ($date_to !== '') {
        $where[] = "er.delivery_date <= \$" . $param_idx;
        $params[] = $date_to;
        $param_idx++;
    }

    // Filtro por cargo
    if ($job_position !== '') {
        $where[] = "e.position = \$" . $param_idx;
        $params[] = $job_position;
        $param_idx++;
    }

    // Filtro por motivo
    if ($selected_reason !== '') {
        $where[] = "er.reason = \$" . $param_idx;
        $params[] = $selected_reason;
        $param_idx++;
    }

    // Filtro por estado
    if ($status_filter === 'cumplido') {
        $where[] = "er.lifespan_status = 'CUMPLIDO'";
    } elseif ($status_filter === 'incumplimiento') {
        $where[] = "er.lifespan_status = 'INCUMPLIMIENTO'";
    }

    // Construir SQL
    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT 
            er.id,
            er.employee_id,
            er.epp_item_id,
            er.delivery_date,
            er.price_at_delivery,
            er.condition,
            er.reason,
            er.lifespan_status,
            e.first_name,
            e.last_name,
            e.position,
            e.area,
            e.dni,
            ei.name as epp_name
        FROM epp_records er
        JOIN employees e ON er.employee_id = e.id
        JOIN epp_items ei ON er.epp_item_id = ei.id
        $where_clause
        ORDER BY er.delivery_date DESC, e.last_name, e.first_name";

    $res = pg_query_params($conn, $sql, $params);
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $records[] = $row;
        }
    }

    closeDBConnection($conn);
}

// NO RECALCULAR ESTADOS EN PHP
// El trigger trg_recalc_after_insert en la BD recalcula lifespan_status de TODOS 
// los registros relacionados (mismo empleado + EPP) cuando se inserta uno nuevo.
// Los reportes usan DIRECTAMENTE el valor guardado en BD, que es 100% correcto.

// Calcular estadísticas
$total_records = count($records);
$incumplimientos = count(array_filter($records, function($r) { return $r['lifespan_status'] === 'INCUMPLIMIENTO'; }));
$cumplidos = $total_records - $incumplimientos;

// Total gastado: excluir "Reserva" (cuenta como $0)
$total_gastado = 0.0;
foreach ($records as $r) {
    // Si la condición es "Reserva", contar precio como 0
    if ($r['condition'] === 'Reserva') {
        $price = 0.0;
    } else {
        $price = floatval($r['price_at_delivery'] ?? 0);
    }
    $total_gastado += $price;
}

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
    <title>Reportes - Sistema EPP Valmet</title>
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
            grid-template-columns: 1fr 1fr;
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
        .badge-reserva {
            background:#f0f0f0;
            color:#333;
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
        .active-filters {
            background:#e3f2fd;
            padding:8px 12px;
            border-radius:4px;
            margin-bottom:12px;
            font-size:12px;
            color:#1565c0;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div>
                <h1>Reportes de EPP</h1>
                <p>Análisis detallado de entregas, cumplimiento y costos</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn-back">← Volver</a>
                <button id="btnPrint" class="btn-print" type="button">Imprimir reporte</button>
            </div>
        </div>

        <div class="filters">
            <form method="GET" action="reports.php" id="filterForm">
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="epp_id">EPP:</label>
                        <select id="epp_id" name="epp_id">
                            <option value="0">-- Todas las EPP --</option>
                            <?php foreach ($epp_list as $epp): ?>
                                <option value="<?php echo $epp['id']; ?>" <?php echo (isset($selected_epp) && $selected_epp === (int)$epp['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($epp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="reason">Motivo de entrega:</label>
                        <select id="reason" name="reason">
                            <option value="">-- Todos los motivos --</option>
                            <?php foreach ($reasons as $r): ?>
                                <option value="<?php echo htmlspecialchars($r); ?>" <?php echo (isset($selected_reason) && $selected_reason === $r) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="job_position">Cargo:</label>
                        <select id="job_position" name="job_position">
                            <option value="">-- Todos los cargos --</option>
                            <?php foreach ($job_positions as $pos): ?>
                                <option value="<?php echo htmlspecialchars($pos); ?>" <?php echo (isset($job_position) && $job_position === $pos) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pos); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Estado:</label>
                        <select id="status" name="status">
                            <option value="all" <?php echo (isset($status_filter) && $status_filter === 'all') ? 'selected' : ''; ?>>-- Todos --</option>
                            <option value="cumplido" <?php echo (isset($status_filter) && $status_filter === 'cumplido') ? 'selected' : ''; ?>>Cumplidos</option>
                            <option value="incumplimiento" <?php echo (isset($status_filter) && $status_filter === 'incumplimiento') ? 'selected' : ''; ?>>Incumplimientos</option>
                        </select>
                    </div>
                </div>

                <div class="filter-row full">
                    <div class="filter-group">
                        <label for="date_from">Fecha desde:</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars(isset($date_from) ? $date_from : ''); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_to">Fecha hasta:</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars(isset($date_to) ? $date_to : ''); ?>">
                    </div>
                </div>

                <div style="display:flex; gap:8px; margin-top:12px;">
                    <button type="submit" class="btn-filter">Filtrar</button>
                    <a href="reports.php" class="btn-reset" style="text-decoration:none; padding:8px 16px; display:inline-block;">Limpiar filtros</a>
                </div>
            </form>

            <?php if ((isset($selected_epp) && $selected_epp > 0) || (isset($date_from) && $date_from !== '') || (isset($date_to) && $date_to !== '') || (isset($job_position) && $job_position !== '') || (isset($selected_reason) && $selected_reason !== '') || (isset($status_filter) && $status_filter !== 'all')): ?>
                <div class="active-filters">
                    <strong>Filtros activos:</strong>
                    <?php if (isset($selected_epp) && $selected_epp > 0) {
                        $epp_name = array_reduce($epp_list, function($carry, $item) use ($selected_epp) { return $item['id'] == $selected_epp ? $item['name'] : $carry; }, '');
                        echo "EPP: " . htmlspecialchars($epp_name) . " | ";
                    } ?>
                    <?php if (isset($selected_reason) && $selected_reason !== '') echo "Motivo: " . htmlspecialchars($selected_reason) . " | "; ?>
                    <?php if (isset($job_position) && $job_position !== '') echo "Cargo: " . htmlspecialchars($job_position) . " | "; ?>
                    <?php if (isset($status_filter) && $status_filter !== 'all') echo "Estado: " . ($status_filter === 'cumplido' ? 'Cumplidos' : 'Incumplimientos') . " | "; ?>
                    <?php if (isset($date_from) && $date_from !== '') echo "Desde: " . htmlspecialchars($date_from) . " | "; ?>
                    <?php if (isset($date_to) && $date_to !== '') echo "Hasta: " . htmlspecialchars($date_to); ?>
                </div>
            <?php endif; ?>
        </div>

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
                <div class="stat-value"><?php echo '$' . number_format($total_gastado, 2, ',', '.'); ?></div>
                <div class="stat-label">Total gastado</div>
            </div>
        </div>

        <?php if ($total_records === 0): ?>
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
                        <th>EPP</th>
                        <th>Fecha de entrega</th>
                        <th>Motivo</th>
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
                        <td><?php echo htmlspecialchars($rec['epp_name']); ?></td>
                        <td><?php echo formatDate($rec['delivery_date']); ?></td>
                        <td><?php echo htmlspecialchars($rec['reason']); ?></td>
                        <td>
                            <span class="badge-<?php echo strtolower($rec['condition']); ?>">
                                <?php echo htmlspecialchars($rec['condition']); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                                $display_price = ($rec['condition'] === 'Reserva') ? 0.0 : floatval($rec['price_at_delivery'] ?? 0);
                                echo '$' . number_format($display_price, 2, ',', '.');
                            ?>
                        </td>
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
    </div>

    <script>
        (function(){
            var btn = document.getElementById('btnPrint');
            if (!btn) return;
            btn.addEventListener('click', function(e){
                e.preventDefault();
                window.print();
            });
        })();
    </script>
</body>
</html>
