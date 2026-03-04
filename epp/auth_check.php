<?php
// auth_check.php - Verificar que el usuario esté autenticado
// Incluye este archivo en todas las páginas protegidas

session_start();

function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.html');
        exit;
    }
}

function getUserData() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'display_name' => $_SESSION['display_name']
        ];
    }
    return null;
}
?>