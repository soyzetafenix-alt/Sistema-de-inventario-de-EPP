<?php
// epps_store_lifespan.php - Guarda la vida útil por cargo en la tabla epp_item_job_position
require_once 'auth_check.php';
requireLogin();

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: epps_new.php');
    exit;
}

$eppId = isset($_POST['epp_id']) ? (int) $_POST['epp_id'] : 0;
$lifeDays = $_POST['life_days'] ?? [];

if ($eppId <= 0 || !is_array($lifeDays)) {
    $_SESSION['flash_errors'] = ['Datos inválidos.'];
    header('Location: epps_new.php');
    exit;
}

$conn = getDBConnection();
if (!$conn) {
    $_SESSION['flash_errors'] = ['Error de conexión a la base de datos.'];
    header('Location: epps_new.php');
    exit;
}

try {
    // Verify EPP exists
    $eRes = pg_query_params($conn, "SELECT id FROM epp_items WHERE id = $1", array($eppId));
    if (!$eRes || pg_num_rows($eRes) === 0) {
        throw new Exception('EPP no encontrado.');
    }

    // Start transaction: delete existing and insert new
    pg_query($conn, "BEGIN");

    // Delete existing entries for this EPP
    $delQ = "DELETE FROM epp_item_job_position WHERE epp_item_id = $1";
    $delR = pg_query_params($conn, $delQ, array($eppId));
    if ($delR === false) {
        throw new Exception('No se pudieron eliminar registros existentes.');
    }

    // Insert new entries
    $insertCount = 0;
    foreach ($lifeDays as $jobPositionId => $days) {
        $jobPositionId = (int) $jobPositionId;
        $days = (int) $days;

        if ($jobPositionId <= 0 || $days < 0) {
            continue;
        }

        // Verify job position exists
        $jpRes = pg_query_params($conn, "SELECT id FROM job_positions WHERE id = $1", array($jobPositionId));
        if (!$jpRes || pg_num_rows($jpRes) === 0) {
            continue;
        }

        $insQ = "INSERT INTO epp_item_job_position (epp_item_id, job_position_id, life_days) VALUES ($1, $2, $3)";
        $insR = pg_query_params($conn, $insQ, array($eppId, $jobPositionId, $days));
        if ($insR === false) {
            throw new Exception('Error al insertar vida útil para cargo ' . $jobPositionId);
        }
        $insertCount++;
    }

    pg_query($conn, "COMMIT");

    $_SESSION['flash_success'] = 'Vida útil por cargo guardada correctamente (' . $insertCount . ' cargos configurados).';
    header('Location: epps_new.php');
    exit;

} catch (Exception $e) {
    pg_query($conn, "ROLLBACK");
    error_log('Error guardando lifespan por cargo: ' . $e->getMessage());
    $_SESSION['flash_errors'] = ['Ocurrió un error: ' . $e->getMessage()];
    header('Location: epps_new.php');
    exit;
} finally {
    closeDBConnection($conn);
}
?>
