<?php
// init_db.php - Script para inicializar la base de datos
require_once 'config.php';

$conn = getDBConnection();

if (!$conn) {
    die('Error de conexión a la base de datos');
}

// Crear tabla epp_change_history si no existe
$sql = "
CREATE TABLE IF NOT EXISTS epp_change_history (
    id SERIAL PRIMARY KEY,
    epp_item_id INTEGER NOT NULL,
    user_id INTEGER,
    change_type VARCHAR(50) NOT NULL,
    old_value VARCHAR(255),
    new_value VARCHAR(255),
    change_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    change_description TEXT,
    FOREIGN KEY (epp_item_id) REFERENCES epp_items(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_epp_change_history_epp_id ON epp_change_history(epp_item_id);
CREATE INDEX IF NOT EXISTS idx_epp_change_history_timestamp ON epp_change_history(change_timestamp);
";

// Ejecutar cada sentencia SQL
$statements = explode(';', $sql);
foreach ($statements as $statement) {
    $statement = trim($statement);
    if (empty($statement)) continue;
    
    $result = pg_query($conn, $statement);
    if (!$result) {
        echo "Error al ejecutar: " . $statement . "\n";
        echo "Error: " . pg_last_error($conn) . "\n";
        closeDBConnection($conn);
        die();
    }
}

closeDBConnection($conn);
echo "Base de datos inicializada correctamente. La tabla epp_change_history ha sido creada.";
?>
