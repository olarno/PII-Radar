<?php
require 'includes/db.php';
exige_connexion();

$patient_id = (int) $_GET['patient_id'];
$patient = mysqli_fetch_assoc(mysqli_query($db, 'SELECT prenom, nom, telephone FROM patients WHERE id = ' . $patient_id));
$rdv = mysqli_fetch_assoc(mysqli_query($db, "SELECT id, date_heure FROM rendezvous WHERE patient_id = " . $patient_id . " AND statut = 'prevu' ORDER BY date_heure LIMIT 1"));

// Rappel SMS via Twilio (compte du neveu du proprio)
$sid = getenv('TWILIO_SID');
$token = getenv('TWILIO_TOKEN');
$texte = 'Bonjour ' . $patient['prenom'] . ' ' . $patient['nom'] . ', rappel de votre rendez-vous le ' . $rdv['date_heure'] . ' a la Clinique Bonsoir.';

$ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json');
curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'From' => '+15005550006',
    'To' => '+1' . str_replace('-', '', $patient['telephone']),
    'Body' => $texte,
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$reponse = curl_exec($ch);
curl_close($ch);

mysqli_query($db, 'UPDATE rendezvous SET rappel_envoye = 1 WHERE id = ' . (int) $rdv['id']);

$titre = 'Rappel SMS';
include 'includes/header.php';
?>
<p class="alerte">Rappel envoyé à <?php echo $patient['prenom'] . ' ' . $patient['nom']; ?> (<?php echo $patient['telephone']; ?>).</p>
<?php include 'includes/footer.php'; ?>
