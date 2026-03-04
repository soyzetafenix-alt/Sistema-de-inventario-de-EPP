<?php
// login.php - Procesa el inicio de sesión

session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Obtener credenciales
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Validar que no estén vacíos
if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Usuario y contraseña son requeridos']);
    exit;
}

// Conectar a la base de datos
$conn = getDBConnection();

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

try {
    // Preparar consulta para obtener el usuario
    // Usamos pg_query_params para prevenir SQL injection
    $query = "SELECT id, username, password_hash, display_name, is_active 
              FROM app_users 
              WHERE username = $1 AND is_active = true";
    
    $result = pg_query_params($conn, $query, array($username));
    
    if (!$result) {
        throw new Exception("Error en la consulta");
    }
    
    // Verificar si existe el usuario
    if (pg_num_rows($result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Usuario o contraseña incorrectos']);
        closeDBConnection($conn);
        exit;
    }
    
    $user = pg_fetch_assoc($result);
    
    // Verificar la contraseña usando password_verify de PHP (compatible con bcrypt)
    if (password_verify($password, $user['password_hash'])) {
        // Login exitoso
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['logged_in'] = true;
        
        echo json_encode([
            'success' => true,
            'message' => 'Login exitoso',
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'display_name' => $user['display_name']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Usuario o contraseña incorrectos']);
    }
    
} catch (Exception $e) {
    error_log("Error en login: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
} finally {
    closeDBConnection($conn);
}
?>