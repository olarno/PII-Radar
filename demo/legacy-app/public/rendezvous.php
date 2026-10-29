<?php
require 'includes/db.php';
exige_connexion();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = mysqli_prepare($db, 'INSERT INTO rendezvous (patient_id, medecin_id, date_heure, motif) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'iiss', $_POST['patient_id'], $_POST['medecin_id'], $_POST['date_heure'], $_POST['motif']);
    mysqli_stmt_execute($stmt);

    // Confirmation par courriel au patient
    $patient = mysqli_fetch_assoc(mysqli_query($db, 'SELECT prenom, courriel FROM patients WHERE id = ' . (int) $_POST['patient_id']));
    $modele = mysqli_fetch_assoc(mysqli_query($db, "SELECT sujet, email_template FROM parametres_courriel WHERE code = 'confirmation_rdv'"));
    $corps = str_replace(['{prenom}', '{date}'], [$patient['prenom'], $_POST['date_heure']], $modele['email_template']);
    mail($patient['courriel'], $modele['sujet'], $corps, 'From: noreply@clinique-bonsoir.test');

    $message = 'Rendez-vous créé et confirmation envoyée.';
}

$patients = mysqli_query($db, 'SELECT id, prenom, nom FROM patients ORDER BY nom');
$medecins = mysqli_query($db, "SELECT id, nom_utilisateur FROM users WHERE role = 'medecin'");

$titre = 'Nouveau rendez-vous';
include 'includes/header.php';
?>
<?php if ($message) { echo '<p class="alerte">' . $message . '</p>'; } ?>
<form method="post">
    <label>Patient</label>
    <select name="patient_id">
        <?php while ($p = mysqli_fetch_assoc($patients)) { ?>
        <option value="<?php echo $p['id']; ?>"><?php echo $p['nom'] . ', ' . $p['prenom']; ?></option>
        <?php } ?>
    </select><br>
    <label>Médecin</label>
    <select name="medecin_id">
        <?php while ($m = mysqli_fetch_assoc($medecins)) { ?>
        <option value="<?php echo $m['id']; ?>"><?php echo $m['nom_utilisateur']; ?></option>
        <?php } ?>
    </select><br>
    <label>Date et heure</label> <input name="date_heure" type="datetime-local"><br>
    <label>Motif</label> <textarea name="motif" rows="3" cols="50"></textarea><br>
    <button type="submit">Créer</button>
</form>
<?php include 'includes/footer.php'; ?>
