<?php
require 'includes/db.php';

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courriel = $_POST['courriel'];
    $mot_de_passe = md5($_POST['mot_de_passe']);

    $sql = "SELECT * FROM users WHERE courriel = '" . $courriel . "' AND mot_de_passe = '" . $mot_de_passe . "'";
    $resultat = mysqli_query($db, $sql);
    $user = mysqli_fetch_assoc($resultat);

    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['nom_utilisateur'] = $user['nom_utilisateur'];
        mysqli_query($db, "UPDATE users SET derniere_ip = '" . $_SERVER['REMOTE_ADDR'] . "' WHERE id = " . $user['id']);
        header('Location: index.php');
        exit;
    }
    $erreur = 'Courriel ou mot de passe invalide.';
}

$titre = 'Connexion';
include 'includes/header.php';
?>
<?php if ($erreur) { echo '<p class="alerte">' . $erreur . '</p>'; } ?>
<div class="demo">
    <strong>Démo PII Radar</strong> &middot; application fictive, aucune vraie personne.<br>
    Comptes : <code>reception@clinique-bonsoir.test</code> / <code>bonsoir</code>
    &middot; <code>admin@clinique-bonsoir.test</code> / <code>admin2014</code>
</div>
<form method="post">
    <label>Courriel</label> <input type="text" name="courriel" value="reception@clinique-bonsoir.test"><br>
    <label>Mot de passe</label> <input type="password" name="mot_de_passe"><br>
    <button type="submit">Se connecter</button>
</form>
<?php include 'includes/footer.php'; ?>
