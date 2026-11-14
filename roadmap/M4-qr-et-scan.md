# M4 — QR code et route de scan

**Quand** : lundi 16/11, matin.
**Dépend de** : M3 (lignes `pending` en base).
**Débloque** : M5.

## Objectif

Générer un jeton et un QR par commande. Au scan, journaliser puis rediriger le staff vers la commande dans l'admin, ou afficher une page d'avertissement si le QR a été invalidé.

## Tâches

### Jeton et QR

- [ ] À la prise en charge d'une commande (`pending` → `processing`, M7) : si `token` est nul, `bin2hex(random_bytes(16))`, enregistré **avant** l'envoi, pour qu'un nouvel essai réutilise le même QR.
- [ ] Service `QrGenerator` : PNG de `https://app.itsmadeleine.shop/r/<jeton>`, correction d'erreur M, marge suffisante, environ 300 px, généré en mémoire (aucun fichier sur disque).
- [ ] Test : le PNG produit se relit avec un décodeur (bibliothèque de test ou vérification manuelle par téléphone).

### Route `GET /r/{token}`

- [ ] Format du jeton contrôlé (`^[0-9a-f]{32}$`), sinon `404` neutre.
- [ ] Jeton inconnu → `404` neutre (même page, même temps de réponse, pas d'indication).
- [ ] Jeton connu, **non révoqué** → ligne `scans` (`result = 'redirected'`, IP, user-agent tronqué à 255) → `302` vers `https://admin.shopify.com/store/<SHOPIFY_STORE_HANDLE>/orders/<shopify_order_id>`.
- [ ] Jeton connu, **révoqué** → ligne `scans` (`result = 'revoked'`) → **page d'avertissement** (`200`, `Cache-Control: no-store`, `noindex`) :
  - fond rouge, titre « Ne pas remettre la commande » ;
  - « Commande #1234 annulée » ou « remboursée », avec la date ;
  - **lien vers la commande dans l'admin Shopify** (même URL que la redirection), pour que le staff vérifie l'état ;
  - pas d'e-mail client ni d'autre donnée personnelle sur la page.
- [ ] Gabarit `templates/scan/revoked.html.php`, lisible sur téléphone, sans ressource externe.

## Critères de fin

- QR scanné avec l'appareil photo d'un téléphone : la commande s'ouvre dans l'app Shopify ou dans le navigateur connecté à l'admin.
- Même scan depuis un téléphone **non connecté** : page de connexion Shopify, rien d'autre.
- QR d'une commande annulée : page d'avertissement avec le lien vers la commande ; le lien ouvre bien la commande.
- Chaque scan crée une ligne dans `scans` avec le bon `result`.

## Risques

- Les liens `admin.shopify.com` ne s'ouvrent pas toujours dans l'app Shopify selon le téléphone (iOS ou Android, navigateur par défaut). À tester en M8 sur les téléphones du staff ; à défaut, le navigateur connecté à l'admin suffit.
- La page d'avertissement affiche le numéro de commande à quiconque scanne le QR. Accepté : c'est le QR du client, et il ne contient rien d'autre.
