# M4 — QR code et route de scan

**Quand** : lundi 16/11, matin. Avec le jour d'avance pris le 14/11, possible dès le dimanche 15/11.
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

Déjà en place pour M4 : `SHOPIFY_STORE_HANDLE=itsmadeleine-love-2` dans le `.env` du serveur ; GD avec PNG disponible sur OVH (pas besoin du repli SVG) ; lignes `pending` créées par M3. Le traitement d'une ligne `pending` est pour l'instant un simple journal : fonction passée à `OrderEventHandler` dans `src/AppFactory.php`, à remplacer par le vrai traitement (M4 à M7).

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
