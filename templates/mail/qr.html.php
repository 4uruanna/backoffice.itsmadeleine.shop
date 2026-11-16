<?php
/**
 * Mail au client (M5), version HTML. Styles en ligne (clients mail), aucune image distante.
 *
 * @var \Closure(mixed): string $e
 * @var string $orderName
 * @var string|null $statusPageUrl
 * @var string $qrCid
 */
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Votre QR code de retrait — commande <?= $e($orderName) ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;background:#ffffff;border-radius:8px;">
        <tr>
          <td style="padding:32px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#222222;">
            <p style="margin:0 0 16px;">Bonjour,</p>
            <p style="margin:0 0 24px;">Présentez ce QR code le jour du concert pour retirer votre commande.</p>
            <p style="margin:0 0 8px;text-align:center;">
              <img src="cid:<?= $e($qrCid) ?>" width="240" height="240" alt="QR code de retrait de la commande <?= $e($orderName) ?>" style="display:block;margin:0 auto;width:240px;height:240px;border:0;">
            </p>
            <p style="margin:0 0 24px;text-align:center;font-size:20px;font-weight:bold;">Commande <?= $e($orderName) ?></p>
<?php if ($statusPageUrl !== null): ?>
            <p style="margin:0 0 24px;text-align:center;">
              <a href="<?= $e($statusPageUrl) ?>" style="color:#222222;font-weight:bold;">Voir ma commande et le point de retrait</a>
            </p>
<?php endif; ?>
            <p style="margin:0;">It's Madeleine</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
