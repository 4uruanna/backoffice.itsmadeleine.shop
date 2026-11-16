<?php
/**
 * Mail au client (M5), version texte : mêmes informations, URL de statut en entier.
 * Texte brut : aucun échappement HTML.
 *
 * @var string $orderName
 * @var string|null $statusPageUrl
 */
?>
Bonjour,

Présentez ce QR code le jour du concert pour retirer votre commande.
Le QR code est affiché dans la version HTML de ce mail.

Commande <?= $orderName ?>


<?php if ($statusPageUrl !== null): ?>
Voir ma commande et le point de retrait :
<?= $statusPageUrl ?>


<?php endif; ?>
It's Madeleine
