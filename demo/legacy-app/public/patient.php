<?php
require 'includes/db.php';
exige_connexion();

$id = (int) $_GET['id'];
$patient = mysqli_fetch_assoc(mysqli_query($db, 'SELECT * FROM patients WHERE id = ' . $id));
$rendezvous = mysqli_query($db, 'SELECT date_heure, motif, statut FROM rendezvous WHERE patient_id = ' . $id . ' ORDER BY date_heure DESC');

if (!$rendezvous) {
    // Debug pour le support, a enlever plus tard
    error_log('Erreur dossier patient : ' . print_r($patient, true) . ' / ' . mysqli_error($db));
}

$titre = 'Dossier de ' . $patient['prenom'] . ' ' . $patient['nom'];
include 'includes/header.php';
?>
<table>
    <tr><th>NAM</th><td><?php echo $patient['nam']; ?></td></tr>
    <tr><th>Date de naissance</th><td><?php echo $patient['date_naissance']; ?></td></tr>
    <tr><th>Courriel</th><td><?php echo $patient['courriel']; ?></td></tr>
    <tr><th>Téléphone</th><td><?php echo $patient['telephone']; ?></td></tr>
    <tr><th>Adresse</th><td><?php echo $patient['adresse'] . ', ' . $patient['ville'] . ' ' . $patient['code_postal']; ?></td></tr>
</table>
<h2>Historique</h2>
<table>
    <tr><th>Date</th><th>Motif</th><th>Statut</th></tr>
    <?php while ($r = mysqli_fetch_assoc($rendezvous)) { ?>
    <tr><td><?php echo $r['date_heure']; ?></td><td><?php echo $r['motif']; ?></td><td><?php echo $r['statut']; ?></td></tr>
    <?php } ?>
</table>
<p><a href="rappel.php?patient_id=<?php echo $patient['id']; ?>">Envoyer un rappel SMS</a></p>
<?php include 'includes/footer.php'; ?>
