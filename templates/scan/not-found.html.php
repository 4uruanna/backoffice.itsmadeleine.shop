<?php
/**
 * Jeton mal formé ou inconnu (M4) : page neutre, identique dans les deux cas.
 *
 * @var \Closure(mixed): string $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>QR code non reconnu</title>
<style>
  body {
    margin: 0; min-height: 100vh; box-sizing: border-box;
    padding: 32px 20px; display: flex; flex-direction: column; justify-content: center;
    background: #f4f4f4; color: #222;
    font: 18px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  main { max-width: 32rem; margin: 0 auto; width: 100%; }
  h1 { font-size: 26px; margin: 0 0 12px; }
  p { margin: 0; }
</style>
</head>
<body>
<main>
  <h1>QR code non reconnu</h1>
  <p>Ce QR code ne correspond à aucune commande à retirer.</p>
</main>
</body>
</html>
