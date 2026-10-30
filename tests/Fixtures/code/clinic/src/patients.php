<?php
// Test account: jeanne.tremblay@example.test, NAM TREJ85011512
$patient = mysqli_fetch_assoc(mysqli_query($db, 'SELECT nom, courriel FROM patients WHERE id = ' . (int) $_GET['id']));
error_log('Patient ' . $patient['nom']);
$villes = mysqli_query($db, 'SELECT nom FROM ville_reference ORDER BY nom');
