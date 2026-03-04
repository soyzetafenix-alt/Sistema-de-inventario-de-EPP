<?php
// users_create.php - Crear nuevos usuarios de la aplicación
require_once 'auth_check.php';
require_once 'config.php';

requireLogin();
$user = getUserData();

$message = '';
$message_type = '';

// Procesar formulario de creación de usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validadores
    $errors = [];

    if (empty($username)) {
        $errors[] = 'El nombre de usuario es requerido';
    } elseif (strlen($username) < 3) {
        $errors[] = 'El nombre de usuario debe tener al menos 3 caracteres';
    }

    if (empty($password)) {
        $errors[] = 'La contraseña es requerida';
    } elseif (strlen($password) < 6) {
        $errors[] = 'La contraseña debe tener al menos 6 caracteres';
    }

    // Si no hay errores, verificar si el usuario existe y crear
    if (empty($errors)) {
        $conn = getDBConnection();
        if ($conn) {
            // Verificar que el username no esté repetido
            $check_query = 'SELECT id FROM public.app_users WHERE username = $1';
            $check_result = pg_query_params($conn, $check_query, [$username]);

            if ($check_result && pg_num_rows($check_result) > 0) {
                $errors[] = 'El nombre de usuario ya está registrado. Por favor, elige otro.';
            } else {
                // Crear hash de la contraseña con bcrypt
                $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 6]);

                // Insertar nuevo usuario
                $insert_query = 'INSERT INTO public.app_users (username, password_hash, display_name, is_active, created_at) 
                                VALUES ($1, $2, $3, $4, NOW())';
                $insert_result = pg_query_params(
                    $conn,
                    $insert_query,
                    [$username, $password_hash, $username, true]
                );

                if ($insert_result) {
                    $message = "Usuario '{$username}' creado exitosamente";
                    $message_type = 'success';
                    // Limpiar el formulario
                    $_POST = [];
                } else {
                    $errors[] = 'Error al crear el usuario. Por favor, intenta de nuevo.';
                }
            }
            closeDBConnection($conn);
        } else {
            $errors[] = 'Error al conectar con la base de datos.';
        }
    }

    // Mostrar errores si existen
    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario - Sistema EPP Valmet</title>
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

        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.3s;
            font-size: 14px;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .form-section {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .form-section h2 {
            color: #333;
            margin-bottom: 30px;
            font-size: 22px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 35px;
        }

        .btn-submit {
            background: #0066cc;
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.3s;
        }

        .btn-submit:hover {
            background: #0052a3;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.3s;
            display: inline-block;
        }

        .btn-cancel:hover {
            background: #5a6268;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 25px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Sistema EPP - Valmet</h1>
        <div class="user-info">
            <span>Bienvenido, <?php echo htmlspecialchars($user['display_name']); ?></span>
            <a href="dashboard.php" class="btn-back">← Volver</a>
        </div>
    </nav>

    <div class="container">
        <div class="form-section">
            <h2>Crear Nuevo Usuario</h2>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="username">Nombre de usuario *</label>
                    <input type="text" id="username" name="username" required 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           placeholder="Ingresa el nombre de usuario"
                           autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="password">Contraseña *</label>
                    <input type="password" id="password" name="password" required 
                           placeholder="Mínimo 6 caracteres"
                           autocomplete="off">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">Crear Usuario</button>
                    <a href="dashboard.php" class="btn-cancel">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
