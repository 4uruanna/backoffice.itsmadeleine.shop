# M4 — QR code et route de scan

**Quand** : lundi 16/11, matin. Avec le jour d'avance pris le 14/11, possible dès le dimanche 15/11.
**État** : code fait le 15/11, déployé le 16/11 ; scan d'une commande valide validé en production le 16/11. Téléphone non connecté et commande annulée validés le 16/11 ; reste à confirmer le nombre de lignes dans `scans`.
**Dépend de** : M3 (lignes `pending` en base).
**Débloque** : M5.

## Objectif

Générer un jeton et un QR par commande. Au scan, journaliser puis rediriger le staff vers la commande dans l'admin, ou afficher une page d'avertissement si le QR a été invalidé.

## Reprise au démarrage : reliquats de M0 à M3

À traiter (ou au moins à lancer) avant le code de M4. Aucun n'est bloquant pour M4, mais le premier conditionne M5.

- [ ] **Mail de test vers Gmail (M0)**, à régler avant M5 : envoyer un mail depuis le webmail de `commandes@itsmadeleine.shop` vers une adresse Gmail. Le 14/11, **le mail n'est pas arrivé**. Diagnostic :
  - Gmail : chercher `from:commandes@itsmadeleine.shop` dans « Tous les messages », spams compris ;
  - webmail OVH : mail présent dans « Éléments envoyés » ? Message de retour (« Undelivered », « Mail Delivery System ») dans la boîte de réception ? Relever le code d'erreur (Gmail refuse les mails non authentifiés : `550 5.7.26` ou `5.7.1`) ;
  - DNS, depuis un terminal : `dig +short TXT itsmadeleine.shop` (le SPF doit contenir `include:mx.ovh.com`), `dig +short MX itsmadeleine.shop`, `dig +short TXT _dmarc.itsmadeleine.shop`, puis `dig +short TXT <sélecteur>._domainkey.itsmadeleine.shop` (sélecteur affiché dans l'espace client MX Plan ; réponse vide = DKIM pas encore propagé) ;
  - diagnostic complet : envoyer un mail à l'adresse donnée par mail-tester.com et lire la note (SPF, DKIM, DMARC, listes noires).

  Attendu dans l'en-tête du mail reçu (« Afficher l'original ») : `SPF: PASS`, `DKIM: PASS`.
- [ ] **Fixtures réelles (M2)** : régénérer avec le nouveau nommage puis pousser. Elles sont vérifiées par `tests/Unit/RealFixturesTest.php`.
  ```bash
  git pull && rm -f tests/fixtures/livraison-*.json
  php scripts/analyse-livraisons.php var/deliveries-ovh --fixtures=tests/fixtures
  grep -l "@gmail\|authenticate?key=" tests/fixtures/*   # ne doit rien afficher
  git add tests/fixtures && git commit -m "M2 : fixtures réelles anonymisées" && git push
  ```
  « Signature INVALIDE » à l'analyse le 14/11 : mauvais secret collé (le serveur n'enregistre que les livraisons signées). Sans effet sur les fixtures, re-signées avec le secret des tests.
- [ ] **Commande B, paiement différé (M2)** : commande taguée **avec un client rattaché**, paiement en attente (« Recevoir le paiement plus tard »), puis « Marquer comme payée ». Attendu : livraison `PENDING` sans ligne en base, puis ligne `pending` au passage à `PAID`.
- [ ] **Commandes de test (M2)** : annuler et archiver #1094 à #1102 (et celles créées ensuite), pour ne pas fausser les statistiques de la boutique.
- [ ] **Fichiers retirés du dépôt, à supprimer à la main sur le serveur** (l'envoi FTP n'efface rien) : `~/backoffice/public/probe.php`, `~/backoffice/src/Controller/DeliveryProbeController.php`.
- [ ] **Copie brute des livraisons** : `DEBUG_DELIVERIES=1` dans le `.env` du serveur. Le passer à `0` une fois la commande B faite et les fixtures poussées ; supprimer alors le contenu de `~/backoffice/var/log/deliveries/` (données personnelles ; purge automatique prévue en M7).
- [ ] **Temps de réponse (M3)**, non bloquant : relever dans les journaux d'accès OVH la durée des `POST /webhooks/shopify/order-paid` (attendu : sous la seconde ; 50 ms en local).

Déjà en place pour M4 : `SHOPIFY_STORE_HANDLE=itsmadeleine-love-2` dans le `.env` du serveur ; GD avec PNG disponible sur OVH (pas besoin du repli SVG) ; lignes `pending` créées par M3. Le traitement d'une ligne `pending` (fonction passée à `OrderEventHandler` dans `src/AppFactory.php`) attribue désormais le jeton ; le mail (M5), le bucket (M6) et la prise en charge atomique (M7) viendront s'y ajouter.

## Tâches

Code livré le 15/11 (80 tests verts, dont MySQL 8.4 ; parcours complet vérifié sous Apache : livraison signée → jeton → QR décodé → scan → 302).

### Jeton et QR

- [x] Jeton `bin2hex(random_bytes(16))` attribué par le traitement différé de la commande (`AppFactory`), **avant** tout envoi, par `UPDATE … WHERE token IS NULL` : un nouvel essai réutilise le même QR (`PickupOrderRepository::assignToken`). En M7, cet appel suivra la prise en charge atomique `pending` → `processing`.
- [x] Service `QrGenerator` (`src/Service/QrGenerator.php`) : PNG de `<APP_URL>/r/<jeton>`, correction d'erreur M, marge de 4 modules, 328 px (version 4, 41 modules × 8 px), généré en mémoire.
- [x] Test : le PNG produit est relu par le décodeur de `chillerlan/php-qrcode` (`tests/Unit/QrGeneratorTest.php`). Reste la vérification au téléphone (critères de fin).
- [x] Script `scripts/qr.php <jeton>` : écrit le PNG d'un jeton, pour tester le scan avant le mail de M5.

### Route `GET /r/{token}`

- [x] Format du jeton contrôlé (`\A[0-9a-f]{32}\z`, pas de retour à la ligne final accepté), sinon `404` neutre.
- [x] Jeton inconnu → `404`, même page (`templates/scan/not-found.html.php`), aucun scan journalisé.
- [x] Jeton connu, **non révoqué** → ligne `scans` (`redirected`, IP, user-agent tronqué à 255) → `302` vers `https://admin.shopify.com/store/<SHOPIFY_STORE_HANDLE>/orders/<shopify_order_id>`.
- [x] Jeton connu, **révoqué** → ligne `scans` (`revoked`) → page d'avertissement (`200`) : fond rouge, « Ne pas remettre la commande », « Commande #1234 annulée » ou « remboursée » avec la date **à l'heure de Paris**, bouton vers la commande dans l'admin, aucune donnée personnelle.
- [x] Gabarit `templates/scan/revoked.html.php`, lisible sur téléphone, sans ressource externe (CSP `default-src 'none'`).
- [x] Toutes les réponses : `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer` (le jeton n'est pas transmis à `admin.shopify.com`).
- [x] Échec d'écriture du scan : journalisé, sans bloquer la redirection (le staff doit pouvoir ouvrir la commande).
- [x] `SHOPIFY_STORE_HANDLE` absent : `500` et rien de journalisé, plutôt qu'un lien faux.

### Déploiement

- [x] Préparer la release (`scripts/build-release.sh`, qui inclut désormais le nouveau dossier `templates/`) et l'envoyer par FTP (16/11 : une nouvelle commande a bien reçu un jeton en production).
- [ ] Vérifier `https://app.itsmadeleine.shop/r/00000000000000000000000000000000` → page « QR code non reconnu » (404).

### Test au téléphone (critères de fin)

Les lignes créées avant ce déploiement n'ont pas de jeton : il faut une nouvelle commande taguée. La **commande B** (paiement différé, reliquat de M2) fait l'affaire.

1. Après le passage à « payée », lire le jeton dans phpMyAdmin :
   ```sql
   SELECT id, order_name, status, token FROM pickup_orders ORDER BY id DESC LIMIT 5;
   ```
2. Sur le poste, générer le QR (`qr-<début du jeton>.png` à la racine du dépôt) et l'afficher à l'écran. Une seule fois avant, installer les dépendances dans le dépôt (`vendor/` n'est pas versionné, et celui de `build/` ne sert qu'à la release) :
   ```bash
   docker compose run --rm php composer install
   ```
   ```bash
   docker compose run --rm php php scripts/qr.php <jeton>
   ```
   Sans Docker, après `composer install` : `php scripts/qr.php <jeton>` (GD nécessaire).
3. Le scanner avec l'appareil photo d'un téléphone connecté à l'admin, puis d'un téléphone non connecté.
4. Annuler la commande dans l'admin, scanner à nouveau : page rouge, puis bouton → la commande s'ouvre.
5. Contrôler `SELECT result, scanned_at, user_agent FROM scans ORDER BY id DESC;`.

## Critères de fin

- [x] QR scanné avec l'appareil photo d'un téléphone : la commande s'ouvre dans l'app Shopify ou dans le navigateur connecté à l'admin. **Validé le 16/11** sur une nouvelle commande `pending`.
- [x] Même scan depuis un téléphone **non connecté** : page de connexion Shopify, rien d'autre. **Validé le 16/11.**
- [x] QR d'une commande annulée : page d'avertissement avec le lien vers la commande ; le lien ouvre bien la commande. **Validé le 16/11.**
- [ ] Chaque scan crée une ligne dans `scans` avec le bon `result`. 16/11 : une ligne `redirected` (09:35 UTC) et une `revoked` (09:43 UTC), résultats corrects ; à confirmer qu'aucun scan ne manque (3 scans faits ?).

## Risques

- Les liens `admin.shopify.com` ne s'ouvrent pas toujours dans l'app Shopify selon le téléphone (iOS ou Android, navigateur par défaut). À tester en M8 sur les téléphones du staff ; à défaut, le navigateur connecté à l'admin suffit.
- La page d'avertissement affiche le numéro de commande à quiconque scanne le QR. Accepté : c'est le QR du client, et il ne contient rien d'autre.
