<?php
// employee_profile.php - Perfil del empleado + tabla de EPP con filtro anual, alta/edición y firma táctil (canvas)
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

$user = getUserData();
$employeeId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

if ($employeeId <= 0) {
    header('Location: employees_search.php');
    exit;
}

$flash_errors = $_SESSION['flash_errors'] ?? [];
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_errors'], $_SESSION['flash_success']);

$selectedYear = isset($_REQUEST['year']) ? (int) $_REQUEST['year'] : 0;
// New date-range filters
$fromDate = isset($_REQUEST['from_date']) ? trim($_REQUEST['from_date']) : '';
$toDate = isset($_REQUEST['to_date']) ? trim($_REQUEST['to_date']) : '';

$conn = getDBConnection();
$employee = null;
$years = [];
$records = [];
$totalSpent = 0.0;

// obtener catálogo de EPPs para los selects (id, name, price)
$allEppItems = [];
if ($conn) {
    $eiRes = pg_query($conn, "SELECT id, name, price FROM epp_items ORDER BY name");
    if ($eiRes) {
        while ($row = pg_fetch_assoc($eiRes)) {
            $allEppItems[] = $row;
        }
    }
}

// Guardado (POST) - inserta nuevas filas, permite editar firma de existentes (una vez) y borrar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $rowIds     = $_POST['row_id'] ?? [];
    $equipments = $_POST['equipment'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $dates      = $_POST['delivery_date'] ?? [];
    $reasons    = $_POST['reason'] ?? [];
    $brands     = $_POST['brand_model'] ?? [];
    $prices     = $_POST['price'] ?? [];
    $conditions = $_POST['condition'] ?? [];
    $signatures = $_POST['signature_data'] ?? [];
    $detalles   = $_POST['detalle'] ?? [];
    $detallesBackup = $_POST['detalle_backup'] ?? [];
    $deletedIds = $_POST['deleted_ids'] ?? [];
    $doUpdates = $_POST['do_update'] ?? [];
    $userId     = $user['id'] ?? null;

    $errors = [];

    $empCheck = pg_query_params($conn, "SELECT id FROM employees WHERE id = $1", [$employeeId]);
    if (!$empCheck || pg_num_rows($empCheck) !== 1) {
        $errors[] = 'Empleado no encontrado.';
    }

    if (empty($errors)) {
        pg_query($conn, "BEGIN");
        try {
            // Procesar borrados primero
            if (!empty($deletedIds)) {
                foreach ($deletedIds as $did) {
                    $did = (int) $did;
                    if ($did > 0) {
                        // Obtener el epp_item_id y quantity antes de eliminar
                        $recordCheck = pg_query_params($conn, "SELECT epp_item_id, quantity FROM epp_records WHERE id=$1 AND employee_id=$2", [$did, $employeeId]);
                        if ($recordCheck && pg_num_rows($recordCheck) > 0) {
                            $recordData = pg_fetch_assoc($recordCheck);
                            $epp_item_id = (int) $recordData['epp_item_id'];
                            $qty = (int) $recordData['quantity'];
                            
                            // Aumentar el stock actual del EPP en la cantidad que se eliminó
                            $updateStockResult = pg_query_params($conn, "UPDATE epp_items SET current_stock = current_stock + $1 WHERE id = $2", [$qty, $epp_item_id]);
                            if ($updateStockResult === false) {
                                throw new Exception('No se pudo actualizar stock: ' . pg_last_error($conn));
                            }
                        }
                        
                        // Eliminar el registro
                        $del = pg_query_params($conn, "DELETE FROM epp_records WHERE id=$1 AND employee_id=$2", [$did, $employeeId]);
                        if ($del === false) {
                            throw new Exception('No se pudo eliminar registro id ' . $did . ': ' . pg_last_error($conn));
                        }
                    }
                }
            }

            $count = count($rowIds);
            for ($i = 0; $i < $count; $i++) {
                $rid   = trim($rowIds[$i] ?? '');
                $eqId  = trim($equipments[$i] ?? ''); // ahora contiene epp_item_id
                $qty   = (int) ($quantities[$i] ?? 0);
                $date  = trim($dates[$i] ?? '');
                $reason= trim($reasons[$i] ?? '');
                $brand = trim($brands[$i] ?? '');
                $condition = trim($conditions[$i] ?? 'Nuevo'); // Nuevo o Reserva
                $sigData = trim($signatures[$i] ?? '');
                $sigBase64 = null;

                if ($sigData !== '' && strpos($sigData, 'base64,') !== false) {
                    $parts = explode('base64,', $sigData, 2);
                    $b64 = trim($parts[1] ?? '');
                    if ($b64 !== '' && base64_decode($b64, true) !== false) {
                        $sigBase64 = $b64;
                    }
                }

                // Determine price from selected epp_item if provided
                // El precio siempre es el precio original del EPP, independientemente de la condición
                $computedPrice = 0.0;
                $life_days_value = null;
                if ($eqId !== '' && ctype_digit($eqId)) {
                    // Obtener el life_days correcto según el cargo del empleado
                    $itemRes = pg_query_params($conn, 
                        "SELECT ei.price, ei.name, 
                                COALESCE(eijp.life_days, ei.life_days) AS life_days
                         FROM epp_items ei
                         LEFT JOIN epp_item_job_position eijp 
                           ON ei.id = eijp.epp_item_id 
                           AND eijp.job_position_id = (SELECT job_position_id FROM employees WHERE id = $2)
                         WHERE ei.id = $1", 
                        array(intval($eqId), $employeeId));
                    if ($itemRes && pg_num_rows($itemRes) > 0) {
                        $itemRow = pg_fetch_assoc($itemRes);
                        // Usar siempre el precio original del EPP
                        $computedPrice = floatval($itemRow['price'] ?? 0);
                        $life_days_value = isset($itemRow['life_days']) ? intval($itemRow['life_days']) : null;
                        $equipmentSnapshot = $itemRow['name'];
                    } else {
                        $equipmentSnapshot = null;
                    }
                } else {
                    $equipmentSnapshot = null;
                }

                // Existing record
                if (ctype_digit($rid) && (int)$rid > 0) {
                    $doUpdate = $doUpdates[$i] ?? '0';
                    // Only apply updates for rows explicitly marked for update
                    if ($doUpdate !== '1') {
                        continue;
                    }
                    // Get current record to know existing signature
                    $chk = pg_query_params($conn, "SELECT employee_signature, epp_item_id FROM epp_records WHERE id=$1 AND employee_id=$2", [$rid, $employeeId]);
                    if ($chk && pg_num_rows($chk) === 1) {
                        $current = pg_fetch_assoc($chk);
                        $hasExistingSignature = !empty($current['employee_signature']);

                        // Build update parts
                        $updateParts = [];
                        $updateParams = [];

                        // If equipment selected, update epp_item_id, equipment_snapshot and price
                        if ($eqId !== '' && ctype_digit($eqId)) {
                            $updateParts[] = "epp_item_id = $" . (count($updateParams) + 1);
                            $updateParams[] = intval($eqId);
                            if ($equipmentSnapshot !== null) {
                                $updateParts[] = "equipment_snapshot = $" . (count($updateParams) + 1);
                                $updateParams[] = $equipmentSnapshot;
                            }
                            $updateParts[] = "price = $" . (count($updateParams) + 1);
                            $updateParams[] = $computedPrice;
                        }

                        // Update editable metadata fields
                        $updateParts[] = "quantity = $" . (count($updateParams) + 1);
                        $updateParams[] = $qty;
                        $updateParts[] = "delivery_date = $" . (count($updateParams) + 1);
                        $updateParams[] = $date === '' ? null : $date;
                        $updateParts[] = "reason = $" . (count($updateParams) + 1);
                        $updateParams[] = $reason;
                        $updateParts[] = "brand_model = $" . (count($updateParams) + 1);
                        $updateParams[] = $brand;
                        $updateParts[] = "condition = $" . (count($updateParams) + 1);
                        $updateParams[] = $condition;
                        
                        // Solo actualizar detalle si viene no-vacío en el POST, o si lo está actualizando explícitamente
                        // Si está vacío en POST pero el registro ya tiene detalle (en backup), usar el backup
                        $newDetalle = isset($detalles[$i]) ? trim($detalles[$i]) : '';
                        $backupDetalle = isset($detallesBackup[$i]) ? trim($detallesBackup[$i]) : '';
                        
                        // Usar el nuevo detalle si no está vacío, sino usar el backup
                        $detalle = ($newDetalle !== '') ? $newDetalle : $backupDetalle;
                        
                        if ($detalle !== '') {
                            $updateParts[] = "detalle = $" . (count($updateParams) + 1);
                            $updateParams[] = $detalle;
                        }
                        // Si ambos están vacíos, no actualizar (mantiene NULL)

                        // Signature: allow only if there is no existing signature
                        if ($sigBase64 !== null && !$hasExistingSignature) {
                            $updateParts[] = "employee_signature = decode($" . (count($updateParams) + 1) . ", 'base64')";
                            $updateParams[] = $sigBase64;
                        }

                        // metadata. do not set audit user fields here (columns removed)
                        $updateParts[] = "updated_at = now()";

                        // add ids
                        $updateParams[] = $rid;
                        $updateParams[] = $employeeId;

                        $sql = "UPDATE epp_records SET " . implode(', ', $updateParts) . " WHERE id = $" . (count($updateParams) - 1) . " AND employee_id = $" . count($updateParams);
                        $upd = pg_query_params($conn, $sql, $updateParams);
                        if (!$upd) {
                            throw new Exception('No se pudo actualizar el registro: ' . pg_last_error($conn));
                        }
                    }
                } else {
                    // New record: skip completely empty rows
                    $isCompletelyEmpty = ($eqId === '' && $qty === 0 && $date === '' && $reason === '' && $brand === '' && $sigData === '');
                    if ($isCompletelyEmpty) continue;

                    if ($eqId === '' || $qty <= 0 || $date === '' || $reason === '') {
                        $errors[] = 'Faltan campos obligatorios en una fila nueva (equipo, cantidad>0, fecha, motivo).';
                        continue;
                    }
                    // Insert using epp_item_id so DB trigger will set snapshot and price and handle stock
                    // Include equipment field (required NOT NULL) with snapshot name
                    // Also capture price_at_delivery and life_days_at_delivery for historical accuracy
                    // And capture condition (Nuevo/Reserva) - el precio siempre es el original
                    // NEW: Include detalle, created_by, created_by_username
                    $detalle = isset($detalles[$i]) ? trim($detalles[$i]) : '';
                    $userName = $user['username'] ?? 'Sistema';
                    $params = [$employeeId, intval($eqId), $equipmentSnapshot ?? '', $qty, $date, $reason, $brand, $computedPrice, $life_days_value, $condition, $detalle, $userId, $userName];
                    $sql = "INSERT INTO epp_records (employee_id, epp_item_id, equipment, quantity, delivery_date, reason, brand_model, price_at_delivery, life_days_at_delivery, condition, detalle, created_by, created_by_username";
                    if ($sigBase64 !== null) {
                        $sql .= ", employee_signature";
                    }
                    $sql .= ") VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13";
                    if ($sigBase64 !== null) {
                        $params[] = $sigBase64;
                        $sql .= ", decode($" . count($params) . ", 'base64')";
                    }
                    $sql .= ")";

                    $ins = pg_query_params($conn, $sql, $params);
                    if (!$ins) {
                        throw new Exception('No se pudo insertar un registro nuevo: ' . pg_last_error($conn));
                    }
                }
            }

            pg_query($conn, "COMMIT");

            // Mostrar éxito
            $successMsg = 'Cambios guardados correctamente.';
            if (!empty($errors)) {
                $successMsg .= ' (Se ignoraron algunas filas con datos incompletos)';
            }
            $_SESSION['flash_success'] = $successMsg;

            // Si hay errores de validación, también mostrarlos
            if (!empty($errors)) {
                $_SESSION['flash_errors'] = $errors;
            }
        } catch (Exception $e) {
            pg_query($conn, "ROLLBACK");
            error_log('Error guardando EPP: ' . $e->getMessage());
            $_SESSION['flash_errors'] = [
                'No se pudieron guardar los cambios. Verifica que Equipo, Cantidad (>0) y Fecha estén completos.',
                'Detalle técnico: ' . $e->getMessage()
            ];
        }
    } else {
        $_SESSION['flash_errors'] = $errors;
    }

    $redirectParams = '';
    if ($fromDate !== '') $redirectParams .= '&from_date=' . urlencode($fromDate);
    if ($toDate !== '') $redirectParams .= '&to_date=' . urlencode($toDate);
    header('Location: employee_profile.php?id=' . urlencode((string)$employeeId) . $redirectParams);
    exit;
}

// Carga de datos (GET)
if ($conn) {
    $empSql = "SELECT id, first_name, last_name, dni, position, area
               FROM employees
               WHERE id = $1";
    $empRes = pg_query_params($conn, $empSql, array($employeeId));
    if ($empRes && pg_num_rows($empRes) === 1) {
        $employee = pg_fetch_assoc($empRes);
    }

    if ($employee) {
        $yearsSql = "SELECT DISTINCT DATE_PART('year', delivery_date)::int AS year
                     FROM epp_records
                     WHERE employee_id = $1
                     ORDER BY year DESC";
        $yearsRes = pg_query_params($conn, $yearsSql, array($employeeId));
        if ($yearsRes) {
            while ($row = pg_fetch_assoc($yearsRes)) {
                $years[] = (int) $row['year'];
            }
        }

        if ($selectedYear <= 0) {
            $selectedYear = !empty($years) ? $years[0] : (int) date('Y');
        }

        // If no explicit from/to provided, default to the selected year
        if ($fromDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $fromDate = sprintf('%04d-01-01', $selectedYear);
        }
        if ($toDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $toDate = sprintf('%04d-12-31', $selectedYear);
        }

        // Ensure proper ordering
        if ($fromDate > $toDate) {
            $tmp = $fromDate; $fromDate = $toDate; $toDate = $tmp;
        }

        $startDate = $fromDate;
        $endDate = $toDate;

                // Load records together with historical values (life_days_at_delivery, price_at_delivery)
                // If not available, fallback to current values from epp_items and job_position-specific life_days
                $recSql = "SELECT er.id, er.epp_item_id, COALESCE(er.equipment_snapshot, er.equipment) AS equipment_name,
                                   er.quantity, er.delivery_date, er.reason, er.brand_model, er.employee_signature, er.price,
                                   COALESCE(er.life_days_at_delivery, COALESCE(eijp.life_days, ei.life_days)) AS life_days,
                                   COALESCE(er.price_at_delivery, ei.price) AS price_historical,
                                   COALESCE(er.condition, 'Nuevo') AS condition,
                                   COALESCE(er.detalle, '') AS detalle,
                                   COALESCE(er.created_by_username, 'Sistema') AS created_by_username
                            FROM epp_records er
                            JOIN epp_items ei ON ei.id = er.epp_item_id
                            LEFT JOIN epp_item_job_position eijp 
                              ON ei.id = eijp.epp_item_id 
                              AND eijp.job_position_id = (SELECT job_position_id FROM employees WHERE id = $1)
                            WHERE er.employee_id = $1
                              AND er.delivery_date BETWEEN $2 AND $3
                            ORDER BY er.delivery_date ASC, er.id ASC";
                $recRes = pg_query_params($conn, $recSql, array($employeeId, $startDate, $endDate));
                if ($recRes) {
                    $records = pg_fetch_all($recRes) ?: [];

                    // compute lifespan_status and next_allowed_date per record (PHP-side, replicando la lógica SQL)
                    // Ahora usa life_days_at_delivery para que cambios futuros al EPP no afecten cálculos históricos
                    // Se evalúa para cada entrega si hay una entrega posterior antes de su fecha permitida
                    foreach ($records as $k => $r) {
                        $life = isset($r['life_days']) ? (int)$r['life_days'] : 0;
                        $delivery = isset($r['delivery_date']) ? $r['delivery_date'] : null;
                        $status = 'CUMPLIDO';
                        $next_allowed = null;

                        if ($life <= 0 || !$delivery) {
                            // sin vida útil -> siempre cumplido
                            $status = 'CUMPLIDO';
                            $next_allowed = $delivery;
                        } else {
                            // calcular fecha permitida para esta entrega
                            $allowed_date = date('Y-m-d', strtotime($delivery . " +{$life} days"));
                            $next_allowed = $allowed_date;

                            // verificar si hay una entrega posterior ANTES de la fecha permitida DEL MISMO EPP
                            $has_later = false;
                            $currentEppId = isset($r['epp_item_id']) ? $r['epp_item_id'] : null;
                            foreach ($records as $other) {
                                $otherEppId = isset($other['epp_item_id']) ? $other['epp_item_id'] : null;
                                $other_delivery = isset($other['delivery_date']) ? $other['delivery_date'] : null;
                                // Solo contar si es el MISMO EPP y la entrega es posterior pero antes del plazo
                                if ($otherEppId == $currentEppId && $other_delivery && $other_delivery > $delivery && $other_delivery < $allowed_date) {
                                    $has_later = true;
                                    break;
                                }
                            }

                            if ($has_later) {
                                // hubo entrega del MISMO EPP anterior al plazo permitido -> INCUMPLIMIENTO
                                $status = 'INCUMPLIMIENTO';
                            } else {
                                // no hubo entrega del MISMO EPP anterior al plazo -> CUMPLIDO
                                $status = 'CUMPLIDO';
                            }
                        }

                        $records[$k]['life_days'] = $life;
                        $records[$k]['next_allowed_date'] = $next_allowed;
                        $records[$k]['lifespan_status'] = $status;
                    }
                }
                        $totalSql = "SELECT COALESCE(SUM(
                                        CASE
                                            WHEN COALESCE(condition, 'Nuevo') = 'Reserva' THEN 0
                                            ELSE COALESCE(price_at_delivery, ei.price) * quantity
                                        END
                                    ), 0)::numeric(12,2) AS total
                                         FROM epp_records er
                                         JOIN epp_items ei ON ei.id = er.epp_item_id
                                         WHERE er.employee_id = $1
                                             AND er.delivery_date BETWEEN $2 AND $3";
                $totalRes = pg_query_params($conn, $totalSql, array($employeeId, $startDate, $endDate));
        if ($totalRes) {
            $row = pg_fetch_assoc($totalRes);
            $totalSpent = (float) ($row['total'] ?? 0);
        }
    }

    closeDBConnection($conn);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil de Empleado - Sistema EPP</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .navbar { background: linear-gradient(135deg, #003d7a 0%, #0066cc 100%); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .navbar h1 { font-size: 22px; }
        .user-info { display: flex; align-items: center; gap: 20px; }
        .btn-logout, .btn-back { background: rgba(255,255,255,0.2); color: white; border: 1px solid white; padding: 8px 16px; border-radius: 5px; cursor: pointer; text-decoration: none; transition: background 0.3s; font-size: 14px; }
        .btn-logout:hover, .btn-back:hover { background: rgba(255,255,255,0.3); }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; padding: 25px 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        h2 { margin-bottom: 15px; color: #333; }
        p { color: #555; font-size: 15px; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 18px; margin-top: 10px; }
        .meta .item { font-size: 14px; color: #444; }
        .meta .label { color: #777; font-size: 12px; margin-bottom: 3px; }
        .filters { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        select, button { font-size: 14px; }
        select { padding: 7px 10px; border-radius: 6px; border: 1px solid #ccc; }
        .btn-primary { background: #0066cc; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        .btn-primary:hover { background: #0052a3; }
        .btn-secondary { background: #f1f1f1; color: #333; border: 1px solid #ddd; padding: 8px 14px; border-radius: 6px; cursor: pointer; }
        .table-wrapper { width: 100%; overflow-x: auto; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 950px; }
        th, td { border-bottom: 1px solid #eee; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { background: #f7f7ff; color: #444; font-weight: 600; }
        .pill { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 12px; border: 1px solid #ddd; background: #f7f7f7; color: #444; }
        .pill.ok { background: #e6ffef; border-color: #99ffbb; color: #006622; }
        .pill.warn { background: #fff1e6; border-color: #ffd0a6; color: #8a4b00; }
        .total { margin-top: 12px; font-size: 16px; color: #333; }
        .no-results { color: #777; font-size: 14px; margin-top: 10px; }
        .actions-top { display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px; }
        .sig-preview { font-size: 12px; color: #555; margin-top: 4px; }
        .sig-editor { display: none; margin-top: 4px; }
        .sig-canvas { border: 1px dashed #bbb; border-radius: 6px; width: 240px; height: 90px; touch-action: none; background: #fff; display: block; }
        .sig-actions { display: flex; gap: 6px; margin-top: 6px; }
        /* Inputs en modo solo lectura que parecen texto */
        .readonly-input {
            border: none;
            background: transparent;
            padding: 0;
        }
        .readonly-input:focus {
            outline: none;
            box-shadow: none;
        }
        .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 15px; font-size: 14px; }
        .alert-error { background: #ffe6e6; color: #b30000; border: 1px solid #ffb3b3; }
        .alert-success { background: #e6ffef; color: #006622; border: 1px solid #99ffbb; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Perfil de Empleado</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
            <a href="employees_search.php" class="btn-back">Volver a búsqueda</a>
            <a href="dashboard.php" class="btn-back">Dashboard</a>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container">
        <?php if (!$employee): ?>
            <div class="card">
                <h2>Empleado no encontrado</h2>
                <p>No existe un empleado con ese ID.</p>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Empleado: <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h2>
                <div class="meta">
                    <div class="item">
                        <div class="label">DNI</div>
                        <div><strong><?php echo htmlspecialchars($employee['dni']); ?></strong></div>
                    </div>
                    <div class="item">
                        <div class="label">Cargo</div>
                        <div><?php echo htmlspecialchars($employee['position'] ?? ''); ?></div>
                    </div>
                    <div class="item">
                        <div class="label">Área</div>
                        <div><?php echo htmlspecialchars($employee['area'] ?? ''); ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Registros de EPP</h2>

                <?php if (!empty($flash_errors)): ?>
                    <div class="alert alert-error">
                        <strong>No se pudo guardar:</strong>
                        <ul style="margin-left:18px;">
                            <?php foreach ($flash_errors as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($flash_success)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($flash_success); ?>
                    </div>
                <?php endif; ?>

                <form class="filters" method="GET" action="employee_profile.php">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$employeeId); ?>">
                    <label for="from_date">Desde:</label>
                    <input type="date" id="from_date" name="from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
                    <label for="to_date">Hasta:</label>
                    <input type="date" id="to_date" name="to_date" value="<?php echo htmlspecialchars($toDate); ?>">
                    <button class="btn-primary" type="submit">Aplicar</button>
                </form>

                <form method="POST" action="employee_profile.php?id=<?php echo htmlspecialchars((string)$employeeId); ?>">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$employeeId); ?>">
                    <input type="hidden" name="from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
                    <input type="hidden" name="to_date" value="<?php echo htmlspecialchars($toDate); ?>">

                    <div class="table-wrapper">
                        <table id="eppTable">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Equipo</th>
                                    <th>Cantidad</th>
                                    <th>Fecha de entrega</th>
                                    <th>Motivo</th>
                                    <th>Detalle</th>
                                    <th>Marca / Modelo</th>
                                    <th>Estado</th>
                                    <th>Usuario</th>
                                    <th>Firma (táctil)</th>
                                    <th>Precio</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $idx = 1; ?>
                                <?php if (!empty($records)): ?>
                                    <?php foreach ($records as $r): ?>
                                        <tr>
                                            <td class="item-cell"><?php echo $idx++; ?>
                                                        <input type="hidden" name="row_id[]" value="<?php echo htmlspecialchars((string)$r['id']); ?>">
                                            </td>
                                            <td>
                                                <select name="equipment[]" class="equipment-select locked-select" data-locked="1">
                                                    <option value="">-- Selecciona EPP --</option>
                                                    <?php foreach ($allEppItems as $ei): ?>
                                                        <option value="<?php echo htmlspecialchars($ei['id']); ?>" <?php echo (isset($r['epp_item_id']) && $r['epp_item_id'] == $ei['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ei['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="hidden" name="equipment_name[]" value="<?php echo htmlspecialchars($r['equipment_name']); ?>">
                                                <?php if (isset($r['lifespan_status']) && ($r['lifespan_status'] === 'INCUMPLIMIENTO')): ?>
                                                    <div style="margin-top:6px;"><span class="pill warn">Incumplimiento</span></div>
                                                <?php else: ?>
                                                    <div style="margin-top:6px;"><span class="pill ok">Vida útil cumplida</span></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><input type="number" name="quantity[]" min="1" value="<?php echo htmlspecialchars((string)$r['quantity']); ?>" style="width:70px;" readonly></td>
                                            <td><input type="date" name="delivery_date[]" value="<?php echo htmlspecialchars((string)$r['delivery_date']); ?>" readonly></td>
                                            <td>
                                                <select name="reason[]" class="reason-select">
                                                    <option value="">-- Selecciona motivo --</option>
                                                    <option value="DOTACION PLANTA" <?php echo isset($r['reason']) && $r['reason'] === 'DOTACION PLANTA' ? 'selected' : ''; ?>>DOTACIÓN PLANTA</option>
                                                    <option value="DOTACION SERVICIO" <?php echo isset($r['reason']) && $r['reason'] === 'DOTACION SERVICIO' ? 'selected' : ''; ?>>DOTACIÓN SERVICIO</option>
                                                    <option value="DOTACION PROYECTOS" <?php echo isset($r['reason']) && $r['reason'] === 'DOTACION PROYECTOS' ? 'selected' : ''; ?>>DOTACIÓN PROYECTOS</option>
                                                    <option value="CAMBIO PLANTA" <?php echo isset($r['reason']) && $r['reason'] === 'CAMBIO PLANTA' ? 'selected' : ''; ?>>CAMBIO PLANTA</option>
                                                    <option value="CAMBIO SERVICIOS" <?php echo isset($r['reason']) && $r['reason'] === 'CAMBIO SERVICIOS' ? 'selected' : ''; ?>>CAMBIO SERVICIOS</option>
                                                    <option value="CAMBIO PROYECTOS" <?php echo isset($r['reason']) && $r['reason'] === 'CAMBIO PROYECTOS' ? 'selected' : ''; ?>>CAMBIO PROYECTOS</option>
                                                    <option value="PERDIDA PLANTA" <?php echo isset($r['reason']) && $r['reason'] === 'PERDIDA PLANTA' ? 'selected' : ''; ?>>PÉRDIDA PLANTA</option>
                                                    <option value="PERDIDA SERVICIOS" <?php echo isset($r['reason']) && $r['reason'] === 'PERDIDA SERVICIOS' ? 'selected' : ''; ?>>PÉRDIDA SERVICIOS</option>
                                                    <option value="PERDIDA PROYECTOS" <?php echo isset($r['reason']) && $r['reason'] === 'PERDIDA PROYECTOS' ? 'selected' : ''; ?>>PÉRDIDA PROYECTOS</option>
                                                </select>
                                            </td>
                                            <td>
                                                <textarea name="detalle[]" style="width:240px; min-height:120px; font-size:13px; padding:8px; border:1px solid #ccc; border-radius:4px; resize:vertical;"><?php echo htmlspecialchars((string)($r['detalle'] ?? '')); ?></textarea>
                                                <input type="hidden" name="detalle_backup[]" value="<?php echo htmlspecialchars((string)($r['detalle'] ?? '')); ?>">
                                            </td>
                                            <td><input type="text" name="brand_model[]" value="<?php echo htmlspecialchars((string)($r['brand_model'] ?? '')); ?>" readonly></td>
                                            <td>
                                                <select name="condition[]" class="condition-select" data-row-id="<?php echo htmlspecialchars((string)$r['id']); ?>">
                                                    <option value="Nuevo" <?php echo (isset($r['condition']) && $r['condition'] === 'Nuevo') ? 'selected' : ''; ?>>Nuevo</option>
                                                    <option value="Reserva" <?php echo (isset($r['condition']) && $r['condition'] === 'Reserva') ? 'selected' : ''; ?>>Reserva</option>
                                                </select>
                                            </td>
                                            <td>
                                                <span><?php echo htmlspecialchars($user['username'] ?? 'Sistema'); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($r['employee_signature'])): ?>
                                                    <span class="pill ok">Firmado</span>
                                                    <?php
                                                        $sig_b64 = '';
                                                        if (!empty($r['employee_signature'])) {
                                                            if (function_exists('pg_unescape_bytea')) {
                                                                $sig_b64 = base64_encode(pg_unescape_bytea($r['employee_signature']));
                                                            } else {
                                                                $sig_b64 = base64_encode($r['employee_signature']);
                                                            }
                                                        }
                                                    ?>
                                                    <div style="margin-top:6px;">
                                                        <button type="button" class="btn-secondary view-signature" data-b64="<?php echo htmlspecialchars($sig_b64); ?>">Ver firma</button>
                                                    </div>
                                                    <input type="hidden" name="signature_data[]" class="sig-data" value="">
                                                    <div class="sig-preview" style="margin-top:4px; color:#999; font-size:12px;">Firma ya guardada</div>
                                                <?php else: ?>
                                                    <span class="pill warn">Pendiente</span>
                                                    <div class="sig-preview">Espera a que el empleado firme:</div>
                                                    <div class="sig-editor" style="display:none;">
                                                        <canvas class="sig-canvas"></canvas>
                                                        <div class="sig-actions">
                                                            <button type="button" class="btn-secondary sig-clear">Limpiar</button>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="signature_data[]" class="sig-data">
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="price-display"><?php echo htmlspecialchars(number_format((float)($r['price_historical'] ?? 0), 2, '.', '')); ?></span>
                                                <input type="hidden" name="price[]" value="<?php echo htmlspecialchars(number_format((float)($r['price_historical'] ?? 0), 2, '.', '')); ?>">
                                            </td>
                                            <td style="display:flex; gap:6px; flex-wrap:wrap;">
                                                <input type="hidden" name="do_update[]" value="0" class="do-update-hidden">
                                                <?php if (empty($r['employee_signature'])): ?>
                                                    <button type="button" class="btn-secondary btn-edit-firma" data-action="firma">Editar firma</button>
                                                <?php endif; ?>
                                                <button type="button" class="btn-secondary remove-row">Quitar fila</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr class="no-data">
                                            <td colspan="9" style="text-align:center; color:#777;">No hay registros de EPP entre <?php echo htmlspecialchars($fromDate); ?> y <?php echo htmlspecialchars($toDate); ?>.</td>
                                        </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="total">
                        Total gastado entre <?php echo htmlspecialchars($fromDate); ?> y <?php echo htmlspecialchars($toDate); ?>:
                        <strong><?php echo htmlspecialchars(number_format((float)$totalSpent, 2, '.', '')); ?></strong>
                    </div>

                    <div class="actions-top">
                        <button type="button" class="btn-secondary" id="addRowBtn">Añadir fila</button>
                        <button type="submit" class="btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <style>
        /* Modal para ver firma */
        .sig-modal {
            position: fixed;
            left: 0; top: 0; right: 0; bottom: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .sig-modal-overlay {
            position: absolute; left:0; top:0; right:0; bottom:0; background: rgba(0,0,0,0.5);
        }
        .sig-modal-content {
            position: relative; z-index: 2; background: #fff; padding: 16px; border-radius: 8px; max-width: 90%; max-height: 90%; box-shadow: 0 6px 24px rgba(0,0,0,0.3);
            display:flex; flex-direction:column; gap:8px; align-items:center;
        }
        .sig-modal-img { max-width: 100%; max-height: 70vh; border:1px solid #ddd; }
        /* Modal para ver detalle */
        .detail-modal {
            position: fixed;
            left: 0; top: 0; right: 0; bottom: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .detail-modal-overlay {
            position: absolute; left:0; top:0; right:0; bottom:0; background: rgba(0,0,0,0.5);
        }
        .detail-modal-content {
            position: relative; z-index: 2; background: #fff; padding: 20px; border-radius: 8px; max-width: 600px; width: 90%; box-shadow: 0 6px 24px rgba(0,0,0,0.3);
            display:flex; flex-direction:column; gap:12px;
        }
        .detail-modal-header {
            display: flex; justify-content: space-between; align-items: center; font-weight: bold; color: #333;
        }
        .detail-modal-text {
            background: #f9f9f9; padding: 12px; border-radius: 4px; border-left: 3px solid #0066cc; white-space: pre-wrap; word-wrap: break-word; line-height: 1.5;
        }
        /* Locked select appearance (readonly-like but still submitted) */
        .locked-select { pointer-events: none; opacity: 0.6; background-color: #f7f7f7; }
        /* Condition select styling */
        .condition-select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; background: #fff; }
        .condition-select:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 3px rgba(0,102,204,0.3); }
    </style>

    <div id="sigModal" class="sig-modal">
        <div class="sig-modal-overlay"></div>
        <div class="sig-modal-content">
            <div style="width:100%; display:flex; justify-content:flex-end;"><button type="button" class="sig-modal-close btn-secondary">Cerrar</button></div>
            <img src="" alt="Firma" class="sig-modal-img">
        </div>
    </div>

    <div id="detailModal" class="detail-modal">
        <div class="detail-modal-overlay"></div>
        <div class="detail-modal-content">
            <div class="detail-modal-header">
                <span>Detalle del registro</span>
                <button type="button" class="detail-modal-close btn-secondary">Cerrar</button>
            </div>
            <div class="detail-modal-text" id="detailModalText"></div>
        </div>
    </div>

    <script>
        (function() {
            const tableBody = document.querySelector('#eppTable tbody');
            const addButtons = [document.getElementById('addRowBtn')].filter(Boolean);

            function initSignaturePads(scope) {
                const canvases = (scope || document).querySelectorAll('.sig-canvas');
                canvases.forEach(canvas => {
                    // evitar inicializar varias veces
                    if (canvas.dataset.sigInit === '1') return;

                    const editor = canvas.closest('.sig-editor');
                    // si el editor está oculto y no venimos de una llamada explícita para un scope concreto, saltar
                    if (!scope && editor && window.getComputedStyle(editor).display === 'none') return;

                    const td = canvas.closest('td');
                    const hidden = td ? td.querySelector('.sig-data') : null;
                    const clearBtn = td ? td.querySelector('.sig-actions .sig-clear') : null;

                    const setSize = () => {
                        const rect = canvas.getBoundingClientRect();
                        const w = rect.width || 240;
                        const h = rect.height || 90;
                        canvas.width = w;
                        canvas.height = h;
                    };

                    setSize();
                    canvas.dataset.sigInit = '1';

                    const ctx = canvas.getContext('2d');
                    let drawing = false;
                    let lastX = 0;
                    let lastY = 0;

                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = '#111';

                    const getPos = (e) => {
                        const rect = canvas.getBoundingClientRect();
                        const x = (e.clientX || (e.touches && e.touches[0] && e.touches[0].clientX) || 0) - rect.left;
                        const y = (e.clientY || (e.touches && e.touches[0] && e.touches[0].clientY) || 0) - rect.top;
                        return { x, y };
                    };

                    const start = (e) => {
                        e.preventDefault();
                        const { x, y } = getPos(e);
                        drawing = true;
                        lastX = x; lastY = y;
                    };

                    const move = (e) => {
                        if (!drawing) return;
                        e.preventDefault();
                        const { x, y } = getPos(e);
                        ctx.beginPath();
                        ctx.moveTo(lastX, lastY);
                        ctx.lineTo(x, y);
                        ctx.stroke();
                        lastX = x; lastY = y;
                    };

                    const end = () => {
                        if (!drawing) return;
                        drawing = false;
                        if (hidden) hidden.value = canvas.toDataURL('image/png');
                    };

                    canvas.addEventListener('pointerdown', start);
                    canvas.addEventListener('pointermove', move);
                    canvas.addEventListener('pointerup', end);
                    canvas.addEventListener('pointerleave', end);

                    clearBtn?.addEventListener('click', (e) => {
                        e.preventDefault();
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        if (hidden) hidden.value = '';
                    });
                });
            }

            function addRow() {
                const noDataRow = tableBody.querySelector('.no-data');
                if (noDataRow) noDataRow.remove();
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="item-cell">0<input type="hidden" name="row_id[]" value=""></td>
                    <td>
                        <select name="equipment[]" class="equipment-select">
                            <option value="">-- Selecciona EPP --</option>
                            <?php foreach ($allEppItems as $ei): ?>
                                <option value="<?php echo htmlspecialchars($ei['id']); ?>"><?php echo htmlspecialchars($ei['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="equipment_name[]" value="">
                    </td>
                    <td><input type="number" name="quantity[]" min="1" value="1" style="width:70px;"></td>
                    <td><input type="date" name="delivery_date[]" value="<?php echo date('Y-m-d'); ?>"></td>
                    <td>
                        <select name="reason[]" class="reason-select" required>
                            <option value="">-- Selecciona motivo --</option>
                            <option value="DOTACION PLANTA">DOTACIÓN PLANTA</option>
                            <option value="DOTACION SERVICIO">DOTACIÓN SERVICIO</option>
                            <option value="DOTACION PROYECTOS">DOTACIÓN PROYECTOS</option>
                            <option value="CAMBIO PLANTA">CAMBIO PLANTA</option>
                            <option value="CAMBIO SERVICIOS">CAMBIO SERVICIOS</option>
                            <option value="CAMBIO PROYECTOS">CAMBIO PROYECTOS</option>
                            <option value="PERDIDA PLANTA">PÉRDIDA PLANTA</option>
                            <option value="PERDIDA SERVICIOS">PÉRDIDA SERVICIOS</option>
                            <option value="PERDIDA PROYECTOS">PÉRDIDA PROYECTOS</option>
                        </select>
                    </td>
                    <td><textarea name="detalle[]" style="width:240px; min-height:120px; font-size:13px; padding:8px; border:1px solid #ccc; border-radius:4px; resize:vertical;" placeholder="Describe el motivo..."></textarea>
                    <input type="hidden" name="detalle_backup[]" value=""></td>
                    <td><input type="text" name="brand_model[]"></td>
                    <td>
                        <select name="condition[]" class="condition-select">
                            <option value="Nuevo" selected>Nuevo</option>
                            <option value="Reserva">Reserva</option>
                        </select>
                    </td>
                    <td>
                        <span><?php echo htmlspecialchars($user['display_name'] ?? 'Usuario'); ?></span>
                    </td>
                    <td>
                        <span class="pill warn">Pendiente</span>
                        <div class="sig-preview">Espera a que el empleado firme:</div>
                        <div class="sig-editor" style="display:block;">
                            <canvas class="sig-canvas"></canvas>
                            <div class="sig-actions">
                                <button type="button" class="btn-secondary sig-clear">Limpiar</button>
                            </div>
                        </div>
                        <input type="hidden" name="signature_data[]" class="sig-data">
                    </td>
                    <td><span class="price-display">0.00</span><input type="hidden" name="price[]" value="0.00"></td>
                    <td style="display:flex; gap:6px; flex-wrap:wrap;">
                        <input type="hidden" name="do_update[]" value="1" class="do-update-hidden">
                        <button type="button" class="btn-secondary btn-edit-firma" data-action="firma">Editar firma</button>
                        <button type="button" class="btn-secondary remove-row">Quitar fila</button>
                    </td>
                `;
                tableBody.appendChild(tr);
                // attach events for new row
                const sel = tr.querySelector('.equipment-select');
                if (sel) sel.addEventListener('change', function(){
                    // reuse updatePriceDisplay if available
                    if (typeof updatePriceDisplay === 'function') updatePriceDisplay(this);
                });
                initSignaturePads(tr);
                initRemove(tr);
                // new rows are processed by default
                initEdit(tr);
                renumberItems();
            }

            function initRemove(scope) {
                const buttons = (scope || document).querySelectorAll('.remove-row');
                buttons.forEach(btn => {
                    // prevenir añadir listeners duplicados
                    if (btn.dataset.removeInit === '1') return;
                    btn.dataset.removeInit = '1';
                    btn.addEventListener('click', function() {
                        const row = this.closest('tr');
                        if (!row) return;
                        const rowIdInput = row.querySelector('input[name="row_id[]"]');
                        if (rowIdInput && rowIdInput.value && rowIdInput.value.trim() !== '') {
                            const form = row.closest('form');
                            if (form) {
                                const delInput = document.createElement('input');
                                delInput.type = 'hidden';
                                delInput.name = 'deleted_ids[]';
                                delInput.value = rowIdInput.value;
                                form.appendChild(delInput);
                            }
                        }
                        row.remove();
                        renumberItems();
                    });
                });
            }

            function markRowForUpdate(row, state) {
                const hidden = row.querySelector('.do-update-hidden');
                if (!hidden) return;
                hidden.value = state ? '1' : '0';
                if (state) row.classList.add('row-marked-update'); else row.classList.remove('row-marked-update');
            }

            function renumberItems() {
                const rows = tableBody.querySelectorAll('tr:not(.no-data)');
                let i = 1;
                rows.forEach(tr => {
                    const cell = tr.querySelector('.item-cell');
                    if (cell) {
                        // mantener hidden input y actualizar el texto visible
                        const txtNode = Array.from(cell.childNodes).find(n => n.nodeType === Node.TEXT_NODE);
                        if (txtNode) txtNode.nodeValue = i++ + ' ';
                    }
                });
            }

            function initEdit(scope) {
                const buttons = (scope || document).querySelectorAll('.edit-row');
                buttons.forEach(btn => {
                    if (btn.dataset.editInit === '1') return;
                    btn.dataset.editInit = '1';
                    btn.addEventListener('click', function() {
                        const row = this.closest('tr');
                        if (!row) return;
                        
                        // Verificar si ya tiene firma
                        const hasSignature = this.getAttribute('data-has-signature') === '1';
                        
                        // Mostrar solo el editor de firma si NO tiene firma guardada
                        const editor = row.querySelector('.sig-editor');
                        if (editor && !hasSignature) {
                            editor.style.display = 'block';
                            // inicializar solo el canvas dentro de esta fila
                            initSignaturePads(row);
                        }
                        
                        // Siempre mostrar el campo de precio para editar
                        const priceDisplay = row.querySelector('.price-display');
                        const priceInput = row.querySelector('.price-input');
                        if (priceDisplay && priceInput) {
                            // Sincronizar el valor del input visible con el display antes de mostrar
                            const displayValue = priceDisplay.textContent.trim();
                            priceInput.value = displayValue;
                            
                            priceDisplay.style.display = 'none';
                            priceInput.style.display = 'inline-block';
                            priceInput.focus();
                        }
                        
                        btn.style.display = 'none'; // ocultar el botón edit después de hacer clic
                    });
                });
            }

            addButtons.forEach(btn => btn.addEventListener('click', addRow));
            // inicializar solo los canvases visibles (los ocultos se inicializan al abrir edición)
            initSignaturePads();
            initRemove();
            initEdit();
            renumberItems();

            // Handle "Editar firma" button (show signature editor, only once per row)
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-edit-firma');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const editor = row.querySelector('.sig-editor');
                if (editor) {
                    editor.style.display = 'block';
                    initSignaturePads(row);
                    btn.style.display = 'none'; // Hide button after clicking
                    // mark this row for update when editing signature
                    if (typeof markRowForUpdate === 'function') markRowForUpdate(row, true);
                }
            });


            // Expose helpers for other scripts
            window.initSignaturePads = initSignaturePads;
            window.initRemove = initRemove;
            window.renumberItems = renumberItems;
            window.initEdit = initEdit;

            // Modal: ver firma
            const sigModal = document.getElementById('sigModal');
            const sigModalImg = sigModal?.querySelector('.sig-modal-img');
            const sigModalClose = sigModal?.querySelector('.sig-modal-close');

            function openSignatureModal(b64) {
                if (!sigModal || !sigModalImg) return;
                sigModalImg.src = 'data:image/png;base64,' + b64;
                sigModal.style.display = 'flex';
            }

            function closeSignatureModal() {
                if (!sigModal || !sigModalImg) return;
                sigModal.style.display = 'none';
                sigModalImg.src = '';
            }

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.view-signature');
                if (btn) {
                    const b64 = btn.getAttribute('data-b64') || '';
                    if (b64) openSignatureModal(b64);
                }
            });
            sigModal?.addEventListener('click', function(e) {
                if (e.target.classList.contains('sig-modal-overlay')) closeSignatureModal();
            });
            sigModalClose?.addEventListener('click', closeSignatureModal);

            // Modal: ver detalle
            const detailModal = document.getElementById('detailModal');
            const detailModalText = detailModal?.querySelector('#detailModalText');
            const detailModalClose = detailModal?.querySelector('.detail-modal-close');

            function openDetailModal(detail) {
                if (!detailModal || !detailModalText) return;
                detailModalText.textContent = detail;
                detailModal.style.display = 'flex';
            }

            function closeDetailModal() {
                if (!detailModal) return;
                detailModal.style.display = 'none';
                if (detailModalText) detailModalText.textContent = '';
            }

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.view-detail');
                if (btn) {
                    const detail = btn.getAttribute('data-detail') || '';
                    if (detail) openDetailModal(detail);
                }
            });
            detailModal?.addEventListener('click', function(e) {
                if (e.target.classList.contains('detail-modal-overlay')) closeDetailModal();
            });
            detailModalClose?.addEventListener('click', closeDetailModal);
        })();
    </script>

    <script>
        // Handle equipment selects and price updates; adjust addRow template
        (function(){
            const eppData = {
                <?php foreach ($allEppItems as $ei): ?>
                '<?php echo $ei['id']; ?>': {name: '<?php echo addslashes($ei['name']); ?>', price: '<?php echo number_format((float)$ei['price'],2,'.',''); ?>'},
                <?php endforeach; ?>
            };

            const tableBody = document.querySelector('#eppTable tbody');

            function updatePriceDisplay(selectEl){
                const tr = selectEl.closest('tr');
                const priceSpan = tr.querySelector('.price-display');
                const hiddenPrice = tr.querySelector('input[name="price[]"]');
                const conditionSelect = tr.querySelector('select[name="condition[]"]');
                const condition = conditionSelect ? conditionSelect.value : 'Nuevo';
                
                const val = selectEl.value;
                let finalPrice = 0.00;
                
                if (val && eppData[val]){
                    // Usar siempre el precio original del EPP
                    finalPrice = parseFloat(eppData[val].price);
                }
                
                priceSpan.textContent = finalPrice.toFixed(2);
                if (hiddenPrice) hiddenPrice.value = finalPrice.toFixed(2);
            }

            // Función para actualizar precio cuando cambia el estado
            function updatePriceOnCondition(conditionSelect){
                const tr = conditionSelect.closest('tr');
                const equipmentSelect = tr.querySelector('.equipment-select');
                if (equipmentSelect) {
                    updatePriceDisplay(equipmentSelect);
                }
            }

            // wire up existing selects
            document.querySelectorAll('.equipment-select').forEach(s => {
                s.addEventListener('change', function(){ updatePriceDisplay(this); });
            });

            // wire up condition selects to update price
            document.querySelectorAll('.condition-select').forEach(c => {
                c.addEventListener('change', function(){ updatePriceOnCondition(this); });
            });

            
        })();
    </script>

    <script>
        // Validación mejorada antes de guardar
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form[method="POST"]');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                // Sincronizar precios visibles con sus inputs antes de enviar
                const rows = document.querySelectorAll('#eppTable tbody tr:not(.no-data)');
                rows.forEach((row) => {
                    const priceInput = row.querySelector('input[name="price[]"]');
                    if (priceInput && priceInput.type !== 'hidden' && priceInput.style.display !== 'none') {
                        // Si el input está visible, su valor es el correcto
                        // Asegurar que sea un valor válido
                        let val = priceInput.value.trim();
                        if (val === '') {
                            priceInput.value = '0.00';
                        } else {
                            // Asegurar formato con 2 decimales
                            priceInput.value = parseFloat(val).toFixed(2);
                        }
                    }
                });

                // Validación de filas incompletas (solo filas nuevas)
                const incompleteRows = [];

                rows.forEach((row, idx) => {
                    const rowIdInput = row.querySelector('input[name="row_id[]"]');
                    const isNewRow = !rowIdInput || !rowIdInput.value || rowIdInput.value.trim() === '';

                    if (!isNewRow) {
                        // Fila existente - saltar validación
                        return;
                    }

                    // Validar fila nueva
                    const equipment = (row.querySelector('select[name="equipment[]"]')?.value || row.querySelector('input[name="equipment[]"]')?.value || '').trim();
                    const quantity = parseInt(row.querySelector('input[name="quantity[]"]')?.value) || 0;
                    const date = row.querySelector('input[name="delivery_date[]"]')?.value.trim() || '';

                    // Si todos están vacíos, ignorar
                    const isEmpty = !equipment && !quantity && !date;
                    if (isEmpty) return;

                    // Si tiene datos pero le faltan obligatorios, marcar
                    if (!equipment || quantity <= 0 || !date) {
                        incompleteRows.push(idx + 1);
                    }
                });

                if (incompleteRows.length > 0) {
                    const msg = 'Las siguientes filas nuevas tienen campos incompletos (se ignorarán):\n- Filas: ' + incompleteRows.join(', ') + '\n\n¿Deseas continuar?';
                    if (!confirm(msg)) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        });
    </script>
</body>
</html>
