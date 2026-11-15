<?php
/**
 * Scan d'un QR invalidé (M4) : la commande ne doit pas être remise.
 * Aucune donnée personnelle : numéro de commande, motif, date, lien admin.
 *
 * @var \Closure(mixed): string $e
 * @var string $orderName
 * @var string $reason annulée | remboursée
 * @var string $revokedAt date locale, ex. 14/11/2026 à 21:05
 * @var string $adminUrl
 */
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Ne pas remettre la commande</title>
<style>
  html { background: #b00020; }
  body {
    margin: 0; min-height: 100vh; box-sizing: border-box;
    padding: 32px 20px; display: flex; flex-direction: column; justify-content: center;
    background: #b00020; color: #fff;
    font: 18px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  main { max-width: 32rem; margin: 0 auto; width: 100%; }
  .stop { font-size: 64px; line-height: 1; margin: 0 0 16px; }
  h1 { font-size: 30px; line-height: 1.15; margin: 0 0 20px; text-transform: uppercase; letter-spacing: .02em; }
  .status { font-size: 22px; font-weight: 700; margin: 0 0 6px; }
  .date { margin: 0 0 32px; opacity: .9; }
  a.button {
    display: block; text-align: center; text-decoration: none;
    background: #fff; color: #b00020; font-weight: 700;
    padding: 16px 20px; border-radius: 10px;
  }
  .hint { margin: 16px 0 0; font-size: 15px; opacity: .85; }
</style>
</head>
<body>
<main>
  <p class="stop" aria-hidden="true">⛔</p>
  <h1>Ne pas remettre la commande</h1>
  <p class="status">Commande <?= $e($orderName) ?> <?= $e($reason) ?></p>
  <p class="date">le <?= $e($revokedAt) ?></p>
  <a class="button" href="<?= $e($adminUrl) ?>" rel="noreferrer">Voir la commande dans Shopify</a>
  <p class="hint">Ce QR code n'est plus valable. Vérifiez l'état de la commande avant toute remise.</p>
</main>
</body>
</html>
