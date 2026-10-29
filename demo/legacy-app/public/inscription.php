<?php
require 'includes/db.php';
exige_connexion();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sql = "INSERT INTO patients (prenom, nom, date_naissance, nam, courriel, telephone, adresse, ville, code_postal) VALUES ('"
        . $_POST['prenom'] . "', '" . $_POST['nom'] . "', '" . $_POST['date_naissance'] . "', '" . $_POST['nam'] . "', '"
        . $_POST['courriel'] . "', '" . $_POST['telephone'] . "', '" . $_POST['adresse'] . "', '" . $_POST['ville'] . "', '"
        . $_POST['code_postal'] . "')";
    mysqli_query($db, $sql);
    $message = 'Patient inscrit (#' . mysqli_insert_id($db) . ').';
}

$villes = mysqli_query($db, 'SELECT nom, region FROM ville_reference ORDER BY nom');

$titre = 'Nouveau patient';
include 'includes/header.php';
?>
<?php if ($message) { echo '<p class="alerte">' . $message . '</p>'; } ?>
<form method="post">
    <label>Prénom</label> <input name="prenom"><br>
    <label>Nom</label> <input name="nom"><br>
    <label>Date de naissance</label> <input name="date_naissance" type="date"><br>
    <label>NAM (carte soleil)</label> <input name="nam" maxlength="12"><br>
    <label>Courriel</label> <input name="courriel"><br>
    <label>Téléphone</label> <input name="telephone"><br>
    <label>Adresse</label> <input name="adresse"><br>
    <label>Ville</label>
    <select name="ville">
        <?php while ($v = mysqli_fetch_assoc($villes)) { ?>
        <option><?php echo $v['nom']; ?></option>
        <?php } ?>
    </select><br>
    <label>Code postal</label> <input name="code_postal" maxlength="7"><br>
    <button type="submit">Inscrire</button>
</form>
<?php include 'includes/footer.php'; ?>
