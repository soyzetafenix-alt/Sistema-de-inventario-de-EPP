<?php
// epp_stock_manage.php - Manage stock for all EPP items (per-item absolute set via AJAX)
require_once 'auth_check.php';
requireLogin();
require_once 'config.php';

$user = getUserData();

// Obtener parámetro de búsqueda
$search = trim($_GET['search'] ?? '');

// fetch all items (opcionalmente filtrados por búsqueda)
$conn = getDBConnection();
$items = [];
if ($conn) {
    if ($search !== '') {
        $res = pg_query_params($conn, 'SELECT id, name, price, initial_stock, current_stock FROM epp_items WHERE LOWER(name) LIKE LOWER($1) ORDER BY name', array('%' . $search . '%'));
    } else {
        $res = pg_query($conn, 'SELECT id, name, price, initial_stock, current_stock FROM epp_items ORDER BY name');
    }
    if ($res) {
        while ($r = pg_fetch_assoc($res)) $items[] = $r;
    }
    closeDBConnection($conn);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestionar stock - EPP</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f5f5f5; padding:20px; }
        .card { background:#fff; padding:16px; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.06); max-width:1100px; margin:0 auto; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th,td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }
        th { background:#fafafa; }
        input[type=number], input[type=text] { padding:6px 8px; border:1px solid #ccc; border-radius:6px; width:100%; box-sizing:border-box; }
        .btn { padding:8px 12px; background:#0066cc; color:#fff; border-radius:6px; border:none; cursor:pointer; }
        .btn-secondary { background:#6c757d; }
        .btn-edit { background:#3498db; }
        .btn-edit:hover { background:#2980b9; }
        .success { color:green; }
        .error { color:red; }
        .top-actions { display:flex; gap:8px; align-items:center; }
        .modal { display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); }
        .modal-content { background-color:#fff; margin:50px auto; padding:30px; border-radius:10px; width:90%; max-width:450px; box-shadow:0 4px 20px rgba(0,0,0,0.3); }
        .modal-header { font-size:20px; font-weight:bold; margin-bottom:15px; color:#333; }
        .modal-body { font-size:15px; color:#666; margin-bottom:25px; line-height:1.5; }
        .modal-footer { display:flex; justify-content:flex-end; gap:10px; }
        .modal-btn { padding:10px 20px; border-radius:6px; border:none; cursor:pointer; font-weight:600; }
        .modal-btn-cancel { background:#f1f1f1; color:#333; }
        .modal-btn-confirm { background:#0066cc; color:#fff; }
        .search-box { display:flex; gap:8px; margin-bottom:15px; }
        .search-box input { flex:1; padding:8px; border:1px solid #ccc; border-radius:6px; }
        .search-box button { padding:8px 16px; background:#0066cc; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; }
        .search-box button:hover { background:#0052a3; }
    </style>
</head>
<body>
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h2>Gestionar stock - Todos los items</h2>
            <div class="top-actions">
                <a href="dashboard.php" class="btn btn-secondary" style="text-decoration:none; color:#fff;">Volver al dashboard</a>
            </div>
        </div>
        <p>Desde aquí puedes establecer el stock absoluto (nuevo 100%) para cualquier EPP. Los cambios se aplican inmediatamente.</p>

        <form method="GET" action="epp_stock_manage.php" class="search-box">
            <input type="text" name="search" placeholder="Busca por nombre del EPP..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit">Buscar</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Precio</th>
                    <th>Stock actual</th>
                    <th>Stock inicial</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                <tr data-id="<?php echo (int)$it['id']; ?>">
                    <td><?php echo htmlspecialchars($it['name']); ?></td>
                    <td><?php echo number_format((float)$it['price'],2,',','.'); ?></td>
                    <td class="cell-current"><?php echo (int)$it['current_stock']; ?></td>
                    <td class="cell-initial"><?php echo (int)$it['initial_stock']; ?></td>
                    <td>
                        <a href="epp_stock_edit.php?id=<?php echo (int)$it['id']; ?>" class="btn" style="text-decoration:none; color:#fff; display:inline-block;">Editar stock</a>
                        <a href="epp_edit.php?id=<?php echo (int)$it['id']; ?>" class="btn btn-edit" style="text-decoration:none; color:#fff; display:inline-block;">Editar EPP</a>
                        <a href="epp_change_history.php?id=<?php echo (int)$it['id']; ?>" class="btn" style="text-decoration:none; color:#fff; display:inline-block; background:#9b59b6;">Historial</a>
                        <div class="result" style="margin-top:6px;"></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
