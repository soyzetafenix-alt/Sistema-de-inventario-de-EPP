<?php
// config.php - Archivo de configuración de base de datos

// Configuración de la base de datos PostgreSQL
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'proEPP'); // Cambia esto por el nombre de tu base de datos
define('DB_USER', 'postgres');    // Usuario de PostgreSQL
define('DB_PASS', 'Valmet');      // Contraseña de PostgreSQL

// Función para conectar a la base de datos
function getDBConnection() {
    try {
        $connection_string = sprintf(
            "host=%s port=%s dbname=%s user=%s password=%s",
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_USER,
            DB_PASS
        );
        
        $conn = pg_connect($connection_string);
        
        if (!$conn) {
            throw new Exception("Error al conectar con la base de datos");
        }
        
        return $conn;
    } catch (Exception $e) {
        error_log("Error de conexión: " . $e->getMessage());
        return false;
    }
}

// Función para cerrar la conexión
function closeDBConnection($conn) {
    if ($conn) {
        pg_close($conn);
    }
}
?>