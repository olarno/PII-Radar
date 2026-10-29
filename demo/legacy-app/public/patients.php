<?php
require 'includes/db.php';
exige_connexion();

$resultat = mysqli_query($db, 'SELECT id, prenom, nom, date_naissance, nam, telephone, ville FROM patients ORDER BY nom');

$titre = 'Patients';
include 'includes/header.php';
?>
<p><a href="export_patients.csv">Télécharger l'export (2019)</a></p>
<table>
    <tr><th>Nom</th><th>Naissance</th><th>NAM</th><th>Téléphone</th><th>Ville</th><th></th></tr>
    <?php while ($p = mysqli_fetch_assoc($resultat)) { ?>
    <tr>
        <td><?php echo $p['nom'] . ', ' . $p['prenom']; ?></td>
        <td><?php echo $p['date_naissance']; ?></td>
        <td><?php echo $p['nam']; ?></td>
        <td><?php echo $p['telephone']; ?></td>
        <td><?php echo $p['ville']; ?></td>
        <td><a href="patient.php?id=<?php echo $p['id']; ?>">Dossier</a></td>
    </tr>
    <?php } ?>
</table>
<?php include 'includes/footer.php'; ?>
