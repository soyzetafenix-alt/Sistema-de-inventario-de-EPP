<?php
// employees_new.php - Formulario para añadir nuevos empleados
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

$user = getUserData();

// Obtener cargos disponibles para el select
$jobPositions = [];
$conn = getDBConnection();
if ($conn) {
    $res = pg_query($conn, "SELECT id, name FROM job_positions WHERE is_active = true ORDER BY name");
    if ($res) {
        while ($row = pg_fetch_assoc($res)) {
            $jobPositions[] = $row;
        }
    }
    closeDBConnection($conn);
}

// Si no hay cargos en la tabla, usar lista por defecto para que el formulario funcione
if (empty($jobPositions)) {
    $defaultCargos = [
        'ADMINISTRACIÓN',
        'JEFE DE PLANTA',
        'CALIDAD',
        'SUPERVISORES Y PLANIFICACION',
        'CAPATAZ DE AREA',
        'CADISTAS',
        'ALMACEN',
        'ACABADO, RECUBRIMIENTO Y DESPACHO',
        'CONDUCTORES',
        'SOLDADORES',
        'ARMADORES',
        'AYUDANTES SOLDADORES',
        'HABILITADO',
        'MAESTRANZA',
        'SOL INOX',
        'MANTENIMIENTO MECANICO',
        'ELECTRICO'
    ];
    foreach ($defaultCargos as $c) {
        $jobPositions[] = ['id' => '', 'name' => $c];
    }
}

// Mensajes tipo "flash" (para volver a esta misma pantalla después de guardar)
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
    <title>Nuevo Empleado - Sistema EPP Valmet</title>
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
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            padding: 25px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .card h2 {
            margin-bottom: 15px;
            color: #333;
        }

        .description {
            margin-bottom: 20px;
            color: #555;
            font-size: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-size: 14px;
            margin-bottom: 5px;
            color: #444;
        }

        input[type="text"], input[type="number"], select {
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 14px;
            background: white;
        }

        input[type="text"]:focus, input[type="number"]:focus, select:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 2px rgba(0, 102, 204, 0.15);
        }

        /* Styled select appearance */
        select {
            border-radius: 8px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background: white url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="6"><path fill="%23666" d="M0 0l5 6 5-6z"/></svg>') no-repeat right 10px center;
            background-size: 10px 6px;
            padding-right: 34px;
        }

        .actions {
            margin-top: 25px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-primary {
            background: #0066cc;
            color: white;
            border: none;
            padding: 9px 20px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-primary:hover {
            background: #0052a3;
        }

        .alert {
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .alert-error {
            background: #ffe6e6;
            color: #b30000;
            border: 1px solid #ffb3b3;
        }

        .alert-success {
            background: #e6ffef;
            color: #006622;
            border: 1px solid #99ffbb;
        }

        ul.error-list {
            margin-top: 5px;
            margin-left: 18px;
        }

        ul.error-list li {
            list-style: disc;
            margin-bottom: 3px;
        }

        /* Modal confirmación */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9999;
        }

        .modal {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.25);
            overflow: hidden;
        }

        .modal-header {
            padding: 16px 18px;
            background: #f6f7ff;
            border-bottom: 1px solid #e6e8ff;
        }

        .modal-header h3 {
            font-size: 16px;
            color: #333;
        }

        .modal-body {
            padding: 16px 18px;
            color: #555;
            font-size: 14px;
            line-height: 1.45;
        }

        .modal-actions {
            padding: 14px 18px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-top: 1px solid #eee;
        }

        .btn-secondary {
            background: #f1f1f1;
            color: #333;
            border: 1px solid #ddd;
            padding: 9px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-secondary:hover {
            background: #e9e9e9;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Nuevo Empleado - Sistema EPP</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <h2>Registrar nuevo empleado</h2>
            <p class="description">
                Completa los datos del trabajador. El DNI debe tener exactamente 8 dígitos y no puede repetirse con otro empleado.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Hay errores en el formulario:</strong>
                    <ul class="error-list">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form id="employeeForm" method="POST" action="employees_store.php" autocomplete="off">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name">Nombres</label>
                        <input type="text" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($old['first_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Apellidos</label>
                        <input type="text" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($old['last_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="dni">DNI (8 dígitos)</label>
                        <input type="text" id="dni" name="dni" maxlength="8" pattern="\d{8}" required value="<?php echo htmlspecialchars($old['dni'] ?? ''); ?>">
                        <small id="dniWarning" style="color:#b30000; font-size:12px; display:none; margin-top:3px;"></small>
                    </div>

                    <div class="form-group">
                        <label for="area">Área</label>
                        <input type="text" id="area" name="area" required value="<?php echo htmlspecialchars($old['area'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="job_position_id">Cargo</label>
                        <select id="job_position_id" name="job_position_id" required>
                            <option value="">-- Selecciona un cargo --</option>
                            <?php foreach ($jobPositions as $jp): ?>
                                <?php $value = (!empty($jp['id']) ? $jp['id'] : $jp['name']); ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (isset($old['job_position_id']) && $old['job_position_id']==$value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($jp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn-primary">Guardar empleado</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal confirmación -->
    <div class="modal-overlay" id="confirmOverlay" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
            <div class="modal-header">
                <h3 id="confirmTitle">Confirmar registro</h3>
            </div>
            <div class="modal-body">
                ¿Deseas guardar este empleado? Verifica que el DNI sea correcto antes de continuar.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="btnCancel">Cancelar</button>
                <button type="button" class="btn-primary" id="btnConfirm">Guardar</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('employeeForm');
            const overlay = document.getElementById('confirmOverlay');
            const btnCancel = document.getElementById('btnCancel');
            const btnConfirm = document.getElementById('btnConfirm');
            const dniInput = document.getElementById('dni');
            const dniWarning = document.getElementById('dniWarning');
            let allowSubmit = false;

            function openModal() {
                overlay.style.display = 'flex';
                overlay.setAttribute('aria-hidden', 'false');
            }

            function closeModal() {
                overlay.style.display = 'none';
                overlay.setAttribute('aria-hidden', 'true');
            }

            function showDniWarning(msg) {
                if (!msg) {
                    dniWarning.style.display = 'none';
                    dniWarning.textContent = '';
                } else {
                    dniWarning.style.display = 'block';
                    dniWarning.textContent = msg;
                }
            }

            form.addEventListener('submit', function (e) {
                if (allowSubmit) return;
                e.preventDefault();

                showDniWarning('');

                const dni = (dniInput.value || '').trim();

                // Validación rápida en frontend
                if (!/^\d{8}$/.test(dni)) {
                    showDniWarning('El DNI debe tener exactamente 8 dígitos numéricos.');
                    dniInput.focus();
                    return;
                }

                // Consultar al servidor si el DNI ya existe antes de mostrar el modal
                fetch('employees_check_dni.php?dni=' + encodeURIComponent(dni), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            // Si hubiera algún problema, solo mostramos un mensaje genérico
                            showDniWarning('No se pudo verificar el DNI. Intenta nuevamente.');
                            return;
                        }

                        if (data.exists) {
                            // Warning: DNI ya existe, no abrimos el modal de confirmación
                            showDniWarning('Ya existe un empleado registrado con este DNI.');
                            dniInput.focus();
                            return;
                        }

                        // Todo bien: abrir modal de confirmación
                        openModal();
                    })
                    .catch(function () {
                        showDniWarning('No se pudo verificar el DNI. Intenta nuevamente.');
                    });
            });

            btnCancel.addEventListener('click', function () {
                closeModal();
            });

            btnConfirm.addEventListener('click', function () {
                allowSubmit = true;
                closeModal();
                form.submit();
            });

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeModal();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.style.display === 'flex') {
                    closeModal();
                }
            });
        })();
    </script>
</body>
</html>

