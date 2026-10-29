<?php
require 'includes/db.php';
exige_connexion();

$resultat = mysqli_query($db, "SELECT r.date_heure, r.motif, r.statut, p.prenom, p.nom, p.telephone
    FROM rendezvous r JOIN patients p ON p.id = r.patient_id
    WHERE DATE(r.date_heure) >= CURDATE() ORDER BY r.date_heure LIMIT 20");

$titre = 'Tableau de bord';
include 'includes/header.php';
?>
<p>Bonjour <?php echo $_SESSION['nom_utilisateur']; ?>. Prochains rendez-vous :</p>
<table>
    <tr><th>Date</th><th>Patient</th><th>Téléphone</th><th>Motif</th><th>Statut</th></tr>
    <?php while ($r = mysqli_fetch_assoc($resultat)) { ?>
    <tr>
        <td><?php echo $r['date_heure']; ?></td>
        <td><?php echo $r['prenom'] . ' ' . $r['nom']; ?></td>
        <td><?php echo $r['telephone']; ?></td>
        <td><?php echo $r['motif']; ?></td>
        <td><?php echo $r['statut']; ?></td>
    </tr>
    <?php } ?>
</table>
<?php include 'includes/footer.php'; ?>
