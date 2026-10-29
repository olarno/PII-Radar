<?php
// Cron mensuel : 0 3 1 * * php /var/www/cron/purge_rendezvous.php
// Supprime les rendez-vous termines depuis plus de 5 ans.
require __DIR__ . '/../public/includes/db.php';

mysqli_query($db, "DELETE FROM rendezvous WHERE statut = 'termine' AND date_heure < DATE_SUB(NOW(), INTERVAL 5 YEAR)");
echo mysqli_affected_rows($db) . " rendez-vous purges\n";
