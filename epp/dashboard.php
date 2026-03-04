<?php
// dashboard.php - Página principal después del login
require_once 'auth_check.php';
requireLogin(); // Verifica que el usuario esté autenticado

$user = getUserData();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema EPP Valmet</title>
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
            font-size: 24px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.3s;
        }

        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .welcome-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .welcome-card h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .welcome-card p {
            color: #666;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Sistema EPP - Valmet</h1>
        <div class="user-info">
            <span>Bienvenido, <?php echo htmlspecialchars($user['display_name']); ?></span>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container">
        <div class="welcome-card">
            <h2>¡Bienvenido al Sistema de Control de EPP!</h2>
            <p>Has iniciado sesión correctamente como: <strong><?php echo htmlspecialchars($user['username']); ?></strong></p>
            <p>Aquí podrás gestionar los empleados y sus equipos de protección personal.</p>
        </div>

        <div class="welcome-card">
            <h2>Gestión de empleados</h2>
            <p>Desde aquí podrás registrar y buscar trabajadores para luego asignarles sus EPP.</p>
            <br>
            <a href="employees_new.php" class="btn-logout" style="background:#0066cc;border-color:#0066cc; margin-right:10px;">Añadir nuevo empleado</a>
            <a href="employees_search.php" class="btn-logout" style="background:#22b573;border-color:#22b573;">Buscar empleados</a>
            <a href="epps_new.php" class="btn-logout" style="background:#ff8c00;border-color:#ff8c00; margin-left:10px;">Crear EPP</a>
            <a href="users_create.php" class="btn-logout" style="background:#e74c3c;border-color:#e74c3c; margin-left:10px;">Crear Usuario</a>
        </div>

        <div class="welcome-card">
            <h2>Reportes</h2>
            <p>Accede al módulo de reportes para analizar entregas, cumplimiento y costos con múltiples filtros.</p>
            <div style="margin-top:10px;">
                <a href="reports.php" class="btn-logout" style="background:#9b59b6;border-color:#9b59b6; margin-left:0px;">Ir a Reportes</a>
            </div>
        </div>

        <div class="welcome-card">
            <h2>Catálogo de EPP</h2>
            <p>Lista rápida de items. Los que estén por debajo del umbral mostrarán alerta.</p>
            <div style="margin-top:10px;">
                <a href="epp_stock_manage.php" class="btn-logout" style="background:#17a2b8;border-color:#17a2b8; margin-left:0px;">Gestionar stock</a>
            </div>
            <div id="eppGrid" style="margin-top:14px; display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px;"> 
                <?php
                // Cargar items y alertas
                require_once 'config.php';
                $conn = getDBConnection();
                if ($conn) {
                      // Show only critical items (alert_active = true)
                      $q = "SELECT ei.id, ei.name, ei.initial_stock, ei.current_stock, ei.price, es.alert_active, es.percent_remaining
                          FROM epp_items ei
                          JOIN epp_stock_alerts es ON es.epp_item_id = ei.id
                          WHERE es.alert_active = true
                          ORDER BY ei.name";
                    $res = pg_query($conn, $q);
                    if ($res) {
                        while ($r = pg_fetch_assoc($res)) {
                            $pct = isset($r['percent_remaining']) ? number_format((float)$r['percent_remaining'],2,'.','') : '100.00';
                            $alert = !empty($r['alert_active']);
                            ?>
                            <div class="epp-card" data-id="<?php echo htmlspecialchars($r['id']); ?>" style="background:white; padding:12px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.06); display:flex; flex-direction:column; gap:10px;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                                    <?php if ($alert): ?>
                                        <span style="background:#fff3f1; color:#b33; padding:6px 8px; border-radius:999px; font-size:12px; border:1px solid #ffd0c0;">Alerta</span>
                                    <?php endif; ?>
                                </div>
                                <div style="color:#666; font-size:13px;">Stock: <strong><?php echo (int)$r['current_stock']; ?></strong> / <?php echo (int)$r['initial_stock']; ?></div>
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div style="font-size:13px; color:#444;">% restante: <strong><?php echo $pct; ?>%</strong></div>
                                    <div style="display:flex; gap:8px;">
                                        <button class="btn-logout btn-small epp-add-stock" data-id="<?php echo htmlspecialchars($r['id']); ?>" data-stock="<?php echo (int)$r['current_stock']; ?>">Reponer stock</button>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    closeDBConnection($conn);
                }
                ?>
            </div>
        </div>

        <!-- Modal para añadir stock -->
        <div id="addStockModal" style="display:none; position:fixed; inset:0; align-items:center; justify-content:center; z-index:9999;">
            <div style="position:absolute; inset:0; background:rgba(0,0,0,0.5);" data-close="1"></div>
            <div style="position:relative; z-index:2; width:420px; max-width:94%; background:#fff; border-radius:10px; padding:16px; box-shadow:0 12px 30px rgba(0,0,0,0.2);">
                <h3 style="margin:0 0 8px 0;">Añadir stock</h3>
                <form id="addStockForm">
                    <input type="hidden" name="epp_item_id" id="modal_epp_id" value="">
                    <div style="margin-bottom:8px;"><label>Nuevo stock (valor absoluto)</label><input type="number" id="new_stock" name="new_stock" min="0" value="0" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;"></div>
                    <div style="margin-bottom:8px;"><label>Motivo (opcional)</label><input type="text" id="reload_reason" name="reason" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;"></div>
                    <div style="display:flex; justify-content:flex-end; gap:8px;"><button type="button" id="modal_cancel" class="btn-logout">Cancelar</button><button type="submit" class="btn-logout" style="background:#0066cc;border-color:#0066cc;">Confirmar</button></div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function(){
            const grid = document.getElementById('eppGrid');
            const modal = document.getElementById('addStockModal');
            const modalForm = document.getElementById('addStockForm');
            if (!grid || !modal) return;

            grid.addEventListener('click', function(e){
                const btn = e.target.closest('.epp-add-stock');
                if (!btn) return;
                const id = btn.getAttribute('data-id');
                const stock = btn.getAttribute('data-stock') || '0';
                document.getElementById('modal_epp_id').value = id;
                // default new_stock to current stock
                const newStockInput = document.getElementById('new_stock');
                if (newStockInput) newStockInput.value = stock;
                modal.style.display = 'flex';
            });

            // close modal
            modal.addEventListener('click', function(e){ if (e.target && e.target.getAttribute('data-close') === '1') modal.style.display = 'none'; });
            document.getElementById('modal_cancel')?.addEventListener('click', function(){ modal.style.display = 'none'; });

            modalForm.addEventListener('submit', function(e){
                e.preventDefault();
                const formData = new FormData(modalForm);
                fetch('epp_set_stock.php', { method: 'POST', body: formData }).then(r=>r.text()).then(()=>{
                    // reload page to reflect changes and alerts
                    window.location.reload();
                }).catch(err=>{ alert('Error al actualizar stock'); });
            });
        })();
    </script>
</body>
</html>