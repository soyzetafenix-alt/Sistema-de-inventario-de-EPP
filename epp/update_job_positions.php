<?php
// Script para actualizar la tabla job_positions con todos los cargos
require_once 'config.php';

$conn = getDBConnection();

if (!$conn) {
    die('Error de conexión a la base de datos');
}

// Lista de cargos a insertar
$cargos = [
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

// Primero, limpiar la tabla (opcional, comentar si no deseas hacerlo)
// $delete_result = pg_query($conn, "DELETE FROM job_positions");

// Insertar cada cargo
$inserted = 0;
$skipped = 0;

foreach ($cargos as $cargo) {
    // Verificar si el cargo ya existe
    $check_result = pg_query_params($conn, 
        "SELECT id FROM job_positions WHERE name = $1", 
        array($cargo)
    );
    
    if ($check_result && pg_num_rows($check_result) === 0) {
        // El cargo no existe, insertarlo
        $insert_result = pg_query_params($conn, 
            "INSERT INTO job_positions (name, is_active) VALUES ($1, true)",
            array($cargo)
        );
        
        if ($insert_result) {
            $inserted++;
            echo "✓ Insertado: $cargo<br>";
        } else {
            echo "✗ Error al insertar: $cargo<br>";
        }
    } else {
        $skipped++;
        echo "— Ya existe: $cargo<br>";
    }
}

closeDBConnection($conn);

echo "<hr>";
echo "<strong>Resumen:</strong><br>";
echo "Insertados: $inserted<br>";
echo "Omitidos (ya existentes): $skipped<br>";
echo "<br><a href='employees_new.php'>Volver al formulario de nuevo empleado</a>";
?>
