<?php
// employees_search.php - Búsqueda de empleados por nombre, apellido, DNI, cargo y área
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

$user = getUserData();

// Obtener filtros desde GET
$first_name = trim($_GET['first_name'] ?? '');
$last_name  = trim($_GET['last_name'] ?? '');
$dni        = trim($_GET['dni'] ?? '');
$position   = trim($_GET['position'] ?? '');
$job_position_id = trim($_GET['job_position_id'] ?? '');
$area       = trim($_GET['area'] ?? '');

$hasSearch = ($first_name !== '' || $last_name !== '' || $dni !== '' || $position !== '' || $job_position_id !== '' || $area !== '');
$employees = [];

if ($hasSearch) {
    $conn = getDBConnection();

    if ($conn) {
        $where = [];
        $params = [];
        $i = 1;

        if ($first_name !== '') {
            $where[] = "LOWER(first_name) LIKE LOWER($" . $i . ")";
            $params[] = $first_name . '%';
            $i++;
        }

        if ($last_name !== '') {
            $where[] = "LOWER(last_name) LIKE LOWER($" . $i . ")";
            $params[] = $last_name . '%';
            $i++;
        }

        if ($dni !== '') {
            $where[] = "dni LIKE $" . $i;
            $params[] = $dni . '%';
            $i++;
        }

        if ($job_position_id !== '') {
            $where[] = "job_position_id = $" . $i;
            $params[] = intval($job_position_id);
            $i++;
        } elseif ($position !== '') {
            $where[] = "LOWER(position) LIKE LOWER($" . $i . ")";
            $params[] = '%' . $position . '%';
            $i++;
        }

        if ($area !== '') {
            $where[] = "LOWER(area) LIKE LOWER($" . $i . ")";
            $params[] = '%' . $area . '%';
            $i++;
        }

        $sql = "SELECT id, first_name, last_name, dni, position, area, created_at
                FROM employees";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY last_name, first_name";

        $result = pg_query_params($conn, $sql, $params);

        if ($result) {
            $employees = pg_fetch_all($result) ?: [];
        }

        closeDBConnection($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Búsqueda de Empleados - Sistema EPP</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }

        .navbar {
            background: linear-gradient(135deg, #003d7a 0%, #0066cc 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .navbar h1 {
            font-size: 22px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .btn-logout, .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.3s;
            font-size: 14px;
        }

        .btn-logout:hover, .btn-back:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            padding: 25px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }

        .card h2 {
            margin-bottom: 12px;
            color: #333;
        }

        .description {
            margin-bottom: 18px;
            color: #555;
            font-size: 14px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-size: 13px;
            margin-bottom: 4px;
            color: #444;
        }

        input[type="text"] {
            padding: 7px 9px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 14px;
        }

        /* Styled select to match inputs and look nicer */
        select {
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid #ccc;
            background: white url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="6"><path fill="%23666" d="M0 0l5 6 5-6z"/></svg>') no-repeat right 10px center;
            background-size: 10px 6px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            font-size: 14px;
        }

        select:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0,102,204,0.08);
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 2px rgba(0, 102, 204, 0.15);
        }

        .filter-actions {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-primary {
            background: #0066cc;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-primary:hover {
            background: #0052a3;
        }

        .btn-secondary {
            background: #f1f1f1;
            color: #333;
            border: 1px solid #ddd;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-secondary:hover {
            background: #e9e9e9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }

        th, td {
            border-bottom: 1px solid #eee;
            padding: 8px 10px;
            text-align: left;
        }

        th {
            background: #f7f7ff;
            color: #444;
            font-weight: 600;
        }

        tr:hover td {
            background: #f9f9ff;
        }

        .link-row {
            color: #667eea;
            text-decoration: none;
        }

        .link-row:hover {
            text-decoration: underline;
        }

        .no-results {
            margin-top: 10px;
            color: #777;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Búsqueda de Empleados</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <h2>Buscar empleados</h2>
            <p class="description">
                Puedes combinar los filtros. Por ejemplo: solo nombre, o nombre + apellido, o DNI + área, etc.
            </p>

            <form method="GET" action="employees_search.php" autocomplete="off">
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="first_name">Nombres</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first_name); ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Apellidos</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last_name); ?>">
                    </div>

                    <div class="form-group">
                        <label for="dni">DNI</label>
                        <input type="text" id="dni" name="dni" maxlength="8" value="<?php echo htmlspecialchars($dni); ?>">
                    </div>

                    <div class="form-group">
                        <label for="job_position_id">Cargo</label>
                        <select id="job_position_id" name="job_position_id">
                            <option value="">-- Todos --</option>
                            <?php
                            // Obtener cargos para el select
                            $jobPositions = [];
                            $conn2 = getDBConnection();
                            if ($conn2) {
                                $r = pg_query($conn2, "SELECT id, name FROM job_positions WHERE is_active = true ORDER BY name");
                                if ($r) {
                                    while ($row = pg_fetch_assoc($r)) $jobPositions[] = $row;
                                }
                                closeDBConnection($conn2);
                            }

                            foreach ($jobPositions as $jp): ?>
                                <option value="<?php echo htmlspecialchars($jp['id']); ?>" <?php echo ($job_position_id !== '' && $job_position_id == $jp['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($jp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="area">Área</label>
                        <input type="text" id="area" name="area" value="<?php echo htmlspecialchars($area); ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-primary">Buscar</button>
                    <a href="employees_search.php" class="btn-secondary">Limpiar</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Resultados</h2>

            <?php if ($hasSearch): ?>
                <?php if (!empty($employees)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre completo</th>
                                <th>DNI</th>
                                <th>Cargo</th>
                                <th>Área</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <tr>
                                    <td>
                                        <a class="link-row" href="employee_profile.php?id=<?php echo urlencode($emp['id']); ?>">
                                            <?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($emp['dni']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['position']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['area']); ?></td>
                                    <td>
                                        <a class="link-row" href="employee_profile.php?id=<?php echo urlencode($emp['id']); ?>">Ver perfil</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-results">No se encontraron empleados con esos criterios.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="no-results">Ingresa algún criterio arriba y pulsa "Buscar" para ver resultados.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

