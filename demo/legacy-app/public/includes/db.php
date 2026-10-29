<?php
// Connexion a la base. Ne pas toucher, ca marche.
session_start();

$db = mysqli_connect(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USER') ?: 'clinique',
    getenv('DB_PASSWORD') ?: 'clinique',
    getenv('DB_NAME') ?: 'clinique'
);
mysqli_set_charset($db, 'utf8mb4');

function exige_connexion()
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}
