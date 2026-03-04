<?php
// epps_new.php - Formulario para añadir nuevos EPP al catálogo
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

$user = getUserData();

$errors = $_SESSION['flash_errors'] ?? [];
$success = $_SESSION['flash_success'] ?? '';
$old = $_SESSION['flash_old'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_success'], $_SESSION['flash_old']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear EPP - Sistema EPP Valmet</title>
    <style>
        /* Reusar estilos similares a employees_new.php para consistencia */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f5f5f5; }
        .navbar { background: linear-gradient(135deg, #003d7a 0%, #0066cc 100%); color:white; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 5px rgba(0,0,0,0.1); }
        .navbar h1{ font-size:22px; }
        .user-info{ display:flex; align-items:center; gap:20px; }
        .btn-logout, .btn-back { background: rgba(255,255,255,0.2); color: white; border:1px solid white; padding:8px 16px; border-radius:5px; text-decoration:none }
        .container{ max-width:800px; margin:30px auto; padding:0 20px; }
        .card{ background:white; padding:25px 30px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
        .form-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:15px 20px; }
        .form-group{ display:flex; flex-direction:column; }
        label{ font-size:14px; margin-bottom:5px; color:#444; }
        input[type="text"], input[type="number"], select { padding:8px 10px; border-radius:6px; border:1px solid #ccc; font-size:14px; }
        select { border-radius:8px; appearance:none; background: white url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="6"><path fill="%23666" d="M0 0l5 6 5-6z"/></svg>') no-repeat right 10px center; background-size:10px 6px; }
        select:focus { outline:none; border-color:#0066cc; box-shadow:0 0 0 3px rgba(0,102,204,0.08); }
        .actions{ margin-top:20px; display:flex; justify-content:flex-end; }
        .btn-primary{ background:#0066cc; color:white; border:none; padding:9px 20px; border-radius:6px; cursor:pointer }
        .alert{ padding:10px 14px; border-radius:6px; margin-bottom:15px; font-size:14px }
        .alert-error{ background:#ffe6e6; color:#b30000; border:1px solid #ffb3b3 }
        .alert-success{ background:#e6ffef; color:#006622; border:1px solid #99ffbb }
        /* Modal confirm */
        .confirm-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 9999; }
        .confirm-modal .overlay { position:absolute; inset:0; background: rgba(0,0,0,0.5); }
        .confirm-modal .content { position:relative; z-index:2; width:480px; max-width:92%; background:#fff; border-radius:10px; box-shadow: 0 12px 40px rgba(0,0,0,0.25); overflow:hidden; }
        .confirm-modal .header { padding:14px 18px; background: linear-gradient(90deg, #f3f6fb 0%, #eef4ff 100%); border-bottom:1px solid #eee; }
        .confirm-modal .header h3 { margin:0; font-size:16px; color:#222; }
        .confirm-modal .body { padding:18px; color:#444; font-size:14px; }
        .confirm-modal .footer { padding:12px 16px; display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f0f0f0; background:#fff; }
        .confirm-modal .btn { padding:8px 14px; border-radius:6px; cursor:pointer; border:1px solid #d0d0d0; background:#f7f7f7; }
        .confirm-modal .btn.primary { background:#0066cc; color:#fff; border-color:#0052a3; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Crear EPP - Sistema EPP</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <h2>Registrar nuevo EPP en el catálogo</h2>
            <p class="description">Completa los datos del EPP. El stock inicial establecerá el stock actual.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Errores en el formulario:</strong>
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form id="eppForm" method="POST" action="epps_store.php" autocomplete="off">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Nombre de la EPP</label>
                        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($old['name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="initial_stock">Stock inicial</label>
                        <input type="number" id="initial_stock" name="initial_stock" min="0" step="1" required value="<?php echo htmlspecialchars($old['initial_stock'] ?? '0'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="price">Precio</label>
                        <input type="text" id="price" name="price" pattern="^\d+(?:\.\d{1,2})?$" value="<?php echo htmlspecialchars($old['price'] ?? '0.00'); ?>">
                    </div>
                </div>

                    <div class="actions">
                        <button type="submit" class="btn-primary">Guardar EPP</button>
                    </div>
                </form>

                <!-- Confirm modal -->
                <div id="confirmModal" class="confirm-modal" role="dialog" aria-hidden="true">
                    <div class="overlay" data-close="1"></div>
                    <div class="content" role="document">
                        <div class="header"><h3>Confirmar registro</h3></div>
                        <div class="body">
                            <p>¿Deseas guardar este EPP en el catálogo? Verifica que los datos (nombre, stock y precio) sean correctos antes de continuar.</p>
                        </div>
                        <div class="footer">
                            <button type="button" class="btn" id="confirmCancel">Cancelar</button>
                            <button type="button" class="btn primary" id="confirmSave">Guardar</button>
                        </div>
                    </div>
                </div>
        </div>
    </div>

        <script>
            (function(){
                const form = document.getElementById('eppForm');
                const modal = document.getElementById('confirmModal');
                const btnCancel = document.getElementById('confirmCancel');
                const btnSave = document.getElementById('confirmSave');

                function openModal(){
                    if (!modal) return;
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden','false');
                }
                function closeModal(){
                    if (!modal) return;
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden','true');
                }

                // intercept submit
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    openModal();
                });

                // cancel
                btnCancel.addEventListener('click', function(){ closeModal(); });
                // overlay close
                modal.addEventListener('click', function(e){ if (e.target && e.target.getAttribute('data-close') === '1') closeModal(); });

                // confirm -> actually submit
                btnSave.addEventListener('click', function(){
                    closeModal();
                    // small delay to allow close animation if any
                    setTimeout(function(){ form.submit(); }, 50);
                });
            })();
        </script>
</body>
</html>
