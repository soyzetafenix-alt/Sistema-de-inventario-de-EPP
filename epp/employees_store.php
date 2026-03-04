<?php
// employees_store.php - Procesa el alta de un nuevo empleado
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees_new.php');
    exit;
}

$errors = [];

// Obtener campos
$first_name = trim($_POST['first_name'] ?? '');
$last_name  = trim($_POST['last_name'] ?? '');
$dni        = trim($_POST['dni'] ?? '');
$area       = trim($_POST['area'] ?? '');
$job_position_id = trim($_POST['job_position_id'] ?? '');

// Guardar "old" para repintar el formulario si hay errores
$_SESSION['flash_old'] = [
    'first_name' => $first_name,
    'last_name' => $last_name,
    'dni' => $dni,
    'area' => $area,
    'job_position_id' => $job_position_id
];

// Validaciones básicas
if ($first_name === '') {
    $errors[] = 'El campo Nombres es obligatorio.';
}

if ($last_name === '') {
    $errors[] = 'El campo Apellidos es obligatorio.';
}

if ($dni === '') {
    $errors[] = 'El campo DNI es obligatorio.';
} else {
    if (!preg_match('/^\d{8}$/', $dni)) {
        $errors[] = 'El DNI debe tener exactamente 8 dígitos numéricos.';
    }
}

// Validar cargo: preferimos job_position_id (FK), si viene un texto lo aceptamos como nombre de cargo
if ($job_position_id === '') {
    $errors[] = 'El campo Cargo es obligatorio.';
} else {
    // Si viene numérico lo trataremos como FK, si no lo usaremos como nombre
    if (!ctype_digit($job_position_id)) {
        // permitir texto como nombre de cargo (ej. cuando la tabla job_positions no tiene datos)
        // ningún error aquí
    }
}

// Si hay errores, volver al formulario con mensajes
if (!empty($errors)) {
    $_SESSION['flash_errors'] = $errors;
    header('Location: employees_new.php');
    exit;
}

// Conectar a la base de datos
$conn = getDBConnection();

if (!$conn) {
    $_SESSION['flash_errors'] = ['Error de conexión a la base de datos.'];
    header('Location: employees_new.php');
    exit;
}

try {
    // Verificar si el DNI ya existe
    $checkQuery = "SELECT id FROM employees WHERE dni = $1";
    $checkResult = pg_query_params($conn, $checkQuery, array($dni));

    if ($checkResult && pg_num_rows($checkResult) > 0) {
        $_SESSION['flash_errors'] = ['Ya existe un empleado registrado con ese DNI.'];
        closeDBConnection($conn);
        header('Location: employees_new.php');
        exit;
    }

    // Insertar nuevo empleado
    // Obtener nombre del cargo para guardar en campo position (compatibilidad)
    $jobName = null;
    $jobIdToStore = null;
    if (ctype_digit($job_position_id)) {
        $jobRes = pg_query_params($conn, "SELECT name FROM job_positions WHERE id = $1", array(intval($job_position_id)));
        if ($jobRes && pg_num_rows($jobRes) > 0) {
            $jobRow = pg_fetch_assoc($jobRes);
            $jobName = $jobRow['name'];
            $jobIdToStore = intval($job_position_id);
        } else {
            throw new Exception('El cargo seleccionado no existe en la base de datos.');
        }
    } else {
        // Si es texto, lo usamos como nombre de cargo. Intentamos encontrarlo en job_positions o crearlo si no existe
        $jobName = $job_position_id;
        $jobIdToStore = null;

        // Buscar cargo por nombre (case-insensitive)
        $jpRes = pg_query_params($conn, "SELECT id FROM job_positions WHERE LOWER(name) = LOWER($1) LIMIT 1", array($jobName));
        if ($jpRes === false) {
            throw new Exception('Error al buscar el cargo: ' . pg_last_error($conn));
        }
        if ($jpRes && pg_num_rows($jpRes) > 0) {
            $jpRow = pg_fetch_assoc($jpRes);
            $jobIdToStore = intval($jpRow['id']);
        } else {
            // Insertar nuevo cargo
            $ins = pg_query_params($conn, "INSERT INTO job_positions (name, is_active) VALUES ($1, true) RETURNING id", array($jobName));
            if ($ins === false) {
                throw new Exception('Error al crear el cargo: ' . pg_last_error($conn));
            }
            if ($ins && pg_num_rows($ins) > 0) {
                $insRow = pg_fetch_assoc($ins);
                $jobIdToStore = intval($insRow['id']);
            } else {
                throw new Exception('No se pudo crear el cargo.');
            }
        }
    }

    // Insertar, guardando job_position_id y position (texto)
    $insertQuery = "INSERT INTO employees (first_name, last_name, dni, position, area, job_position_id)
                    VALUES ($1, $2, $3, $4, $5, $6)";

    $params = array($first_name, $last_name, $dni, $jobName ?: '', $area, $jobIdToStore);

    $insertResult = pg_query_params($conn, $insertQuery, $params);
    if ($insertResult === false) {
        throw new Exception('Error al insertar el empleado: ' . pg_last_error($conn));
    }

    closeDBConnection($conn);

    // Limpiar valores antiguos y mostrar success en la misma pantalla
    unset($_SESSION['flash_old']);
    $_SESSION['flash_success'] = 'Empleado registrado correctamente.';
    header('Location: employees_new.php');
    exit;

} catch (Exception $e) {
    // Log detallado y mostrar mensaje descriptivo al usuario
    error_log('Error al registrar empleado: ' . $e->getMessage());
    $msg = 'Ocurrió un error al registrar el empleado: ' . $e->getMessage();
    $_SESSION['flash_errors'] = [$msg];
    header('Location: employees_new.php');
    exit;
} finally {
    closeDBConnection($conn);
}

