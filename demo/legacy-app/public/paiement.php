<?php
require 'includes/db.php';
exige_connexion();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = mysqli_prepare($db, 'INSERT INTO paiements (patient_id, montant, carte_4_derniers, titulaire_carte) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'idss', $_POST['patient_id'], $_POST['montant'], $_POST['carte_4_derniers'], $_POST['titulaire_carte']);
    mysqli_stmt_execute($stmt);

    // Journal comptable demande par le comptable
    $ligne = date('Y-m-d H:i:s') . ' | patient ' . $_POST['patient_id'] . ' | ' . $_POST['titulaire_carte'] . ' | **** ' . $_POST['carte_4_derniers'] . ' | ' . $_POST['montant'] . " $\n";
    file_put_contents(__DIR__ . '/../logs/paiements.log', $ligne, FILE_APPEND);

    $message = 'Paiement enregistré.';
}

$titre = 'Paiement';
include 'includes/header.php';
?>
<?php if ($message) { echo '<p class="alerte">' . $message . '</p>'; } ?>
<form method="post">
    <label>N° patient</label> <input name="patient_id"><br>
    <label>Montant</label> <input name="montant"><br>
    <label>4 derniers chiffres</label> <input name="carte_4_derniers" maxlength="4"><br>
    <label>Titulaire de la carte</label> <input name="titulaire_carte"><br>
    <button type="submit">Enregistrer</button>
</form>
<?php include 'includes/footer.php'; ?>
