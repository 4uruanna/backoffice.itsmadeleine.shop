<?php
/**
 * Mail au client (M5), version texte : mêmes informations, URL de statut en entier.
 * Texte brut : aucun échappement HTML.
 *
 * @var string $orderName
 * @var string|null $statusPageUrl
 * @var string $contactEmail
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
Vous pouvez aussi retirer votre commande en indiquant son numéro (<?= $orderName ?>)
et en présentant la pièce d'identité de la personne au nom de laquelle elle a été passée.

Une question ? Écrivez-nous à <?= $contactEmail ?>


It's Madeleine
