<?php
// epps_assign_lifespan.php - Asignar vida útil por cargo a un EPP
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

$user = getUserData();
$eppId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

if ($eppId <= 0) {
    header('Location: epps_new.php');
    exit;
}

$conn = getDBConnection();
$epp = null;
$jobPositions = [];
$existingLifespans = [];
$errors = $_SESSION['flash_errors'] ?? [];
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_errors'], $_SESSION['flash_success']);

if ($conn) {
    // Get EPP info
    $eRes = pg_query_params($conn, "SELECT id, name, life_days FROM epp_items WHERE id = $1", array($eppId));
    if ($eRes && pg_num_rows($eRes) === 1) {
        $epp = pg_fetch_assoc($eRes);
    } else {
        header('Location: epps_new.php');
        exit;
    }

    // Get all job positions
    $jpRes = pg_query($conn, "SELECT id, name FROM job_positions ORDER BY name");
    if ($jpRes) {
        while ($row = pg_fetch_assoc($jpRes)) {
            $jobPositions[] = $row;
        }
    }

    // Get existing lifespans for this EPP
    $lifeRes = pg_query_params($conn, "SELECT job_position_id, life_days FROM epp_item_job_position WHERE epp_item_id = $1 ORDER BY job_position_id", array($eppId));
    if ($lifeRes) {
        while ($row = pg_fetch_assoc($lifeRes)) {
            $existingLifespans[$row['job_position_id']] = $row['life_days'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar vida útil por cargo - Sistema EPP Valmet</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f5f5f5; }
        .navbar { background: linear-gradient(135deg, #003d7a 0%, #0066cc 100%); color:white; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 5px rgba(0,0,0,0.1); }
        .navbar h1{ font-size:22px; }
        .user-info{ display:flex; align-items:center; gap:20px; }
        .btn-logout, .btn-back { background: rgba(255,255,255,0.2); color: white; border:1px solid white; padding:8px 16px; border-radius:5px; text-decoration:none; cursor:pointer; }
        .container{ max-width:900px; margin:30px auto; padding:0 20px; }
        .card{ background:white; padding:25px 30px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
        .form-grid-cols{ display:grid; grid-template-columns:repeat(auto-fit, minmax(250px,1fr)); gap:20px; }
        .form-group{ display:flex; flex-direction:column; }
        label{ font-size:14px; margin-bottom:8px; color:#444; font-weight:500; }
        input[type="number"], select { padding:10px 12px; border-radius:6px; border:1px solid #ccc; font-size:14px; }
        input[type="number"]:focus, select:focus { outline:none; border-color:#0066cc; box-shadow:0 0 0 3px rgba(0,102,204,0.08); }
        .actions{ margin-top:25px; display:flex; justify-content:flex-end; gap:10px; }
        .btn-primary{ background:#0066cc; color:white; border:none; padding:10px 22px; border-radius:6px; cursor:pointer; font-size:14px; }
        .btn-primary:hover{ background:#0052a3; }
        .btn-secondary{ background:#f1f1f1; color: #333; border: 1px solid #ddd; padding: 10px 22px; border-radius:6px; cursor: pointer; font-size:14px; }
        .alert{ padding:12px 16px; border-radius:6px; margin-bottom:15px; font-size:14px; }
        .alert-error{ background:#ffe6e6; color:#b30000; border:1px solid #ffb3b3; }
        .alert-success{ background:#e6ffef; color:#006622; border:1px solid #99ffbb; }
        .info-box{ background:#f0f4f8; padding:15px; border-left:4px solid #0066cc; margin-bottom:20px;border-radius:4px; }
        .info-box strong { color:#0066cc; }
        .table-wrapper{ width:100%; overflow-x:auto; margin-top:15px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { border-bottom:1px solid #eee; padding:12px 10px; text-align:left; }
        th { background:#f7f7ff; color:#444; font-weight:600; }
        tr:hover { background:#f9f9f9; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Asignar vida útil por cargo</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container">
        <?php if (!$epp): ?>
            <div class="card">
                <h2>EPP no encontrado</h2>
                <p>No existe el EPP con ese ID.</p>
                <a href="epps_new.php" class="btn-secondary" style="display:inline-block; margin-top:10px;">Volver</a>
            </div>
        <?php else: ?>
            <div class="card">
                <h2><?php echo htmlspecialchars($epp['name']); ?></h2>
                <div class="info-box">
                    <strong>Importante:</strong> Asigna la vida útil (en días) que debe tener este EPP para cada cargo. 
                    Por ejemplo: 30 días para Administración, 3 días para Soldadores Inox, etc.
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <strong>Error:</strong>
                        <ul style="margin-left:18px;">
                            <?php foreach ($errors as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form method="POST" action="epps_store_lifespan.php" id="lifespanForm">
                    <input type="hidden" name="epp_id" value="<?php echo htmlspecialchars((string)$eppId); ?>">
                    
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cargo</th>
                                    <th style="width:200px;">Vida útil (días)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobPositions as $jp): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($jp['name']); ?></td>
                                        <td>
                                            <input type="number" 
                                                   name="life_days[<?php echo htmlspecialchars($jp['id']); ?>]" 
                                                   min="0" 
                                                   step="1"
                                                   value="<?php echo htmlspecialchars((string)($existingLifespans[$jp['id']] ?? 0)); ?>"
                                                   style="width:150px;">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="actions">
                        <a href="dashboard.php" class="btn-secondary">Cancelar</a>
                        <button type="submit" class="btn-primary">Guardar vida útil por cargo</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
