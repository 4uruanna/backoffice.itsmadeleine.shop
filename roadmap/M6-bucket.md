# M6 — Limite d'envoi (bucket)

**Quand** : mardi 17/11, matin.
**Dépend de** : M5 (envoi fonctionnel).
**Débloque** : M7.
**État** : code fait le lundi 16/11 (108 tests verts, dont MySQL et accès simultanés ; critère de fin vérifié en local). `GET_LOCK` vérifié sur la base OVH le 16/11 ; reste le déploiement.

## Objectif

Ne jamais dépasser **`MAIL_MAX_PER_HOUR` mails sur n'importe quelle période de 60 minutes**, mails clients et alertes confondus. Valeur prévue : 120 ; **60 dans le `.env` du serveur depuis le 16/11**, après le refus du quota d'OVH (200 mails par heure et par compte, qui compte aussi les envois faits hors de l'API depuis la même boîte). Bucket saturé → la commande attend le cron.

## Fonctionnement

Un jeton pris = une ligne dans `mail_log`. Jetons disponibles = `MAIL_MAX_PER_HOUR` moins le nombre de lignes de la dernière heure.

```sql
SELECT GET_LOCK('mail_bucket', 5);
SELECT COUNT(*) FROM mail_log WHERE sent_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR;
-- si < MAIL_MAX_PER_HOUR :
INSERT INTO mail_log (pickup_order_id, kind, sent_at) VALUES (?, ?, UTC_TIMESTAMP());
SELECT RELEASE_LOCK('mail_bucket');
```

## Tâches

- [x] `MailBucket::tryAcquire(?int $orderId, string $kind): bool` (`src/Mail/PdoMailBucket.php`) : verrou, comptage, insertion, libération dans un `finally`. `GET_LOCK` qui échoue (délai dépassé ou refusé) → `false`, traité comme un bucket vide, avec une ligne `bucket: verrou mail_bucket indisponible` dans le journal.
- [x] Envoi des mails clients par `tryAcquire` juste avant l'appel SMTP (`PickupProcessor`), après tous les contrôles (liste d'autorisation, révocation) : une commande non envoyée ne consomme pas de jeton. Les alertes passeront aussi par le bucket (`kind = 'alert'`) en M7.
- [x] Bucket vide → la commande repasse en `pending` avec `pending_reason = 'rate_limited'`, **sans** incrémenter `attempts` ; le cron (M7) la reprend avec le même QR.
- [x] Échec SMTP après acquisition : le jeton reste consommé.
- [x] Refus SMTP de quota d'OVH (`550 5.7.0 Mail quota exceeded … 200 messages per hour and per account`, vu le 16/11) : `RateLimitedException`, traité comme un bucket vide (`pending` / `rate_limited`, `attempts` inchangé, message d'OVH dans `last_error`), jamais comme un échec ni une erreur définitive.
- [x] Toutes les dates en UTC (`UTC_TIMESTAMP()` en SQL), cohérentes avec la connexion PDO.
- [x] Tests PHPUnit sur MySQL : plafond à 2 (deux acquisitions, la troisième échoue, rien d'inséré) ; ligne de 61 min hors du compte, celle de 59 min dedans ; verrou tenu par une autre session → `false` ; verrou libéré après usage ; **8 processus simultanés pour 3 jetons → exactement 3** (stable sur 5 passages).

## Déploiement

1. Avant tout, vérifier que la base OVH accepte `GET_LOCK`, dans phpMyAdmin :
   ```sql
   SELECT GET_LOCK('test_verrou', 1) AS pris, RELEASE_LOCK('test_verrou') AS libere;
   ```
   Attendu : `1` et `1`. Sinon, **ne pas déployer** : aucun mail ne partirait (bucket toujours vide), il faudra un autre verrou.
   **16/11 : `1` et `1`, `GET_LOCK` disponible sur la base OVH.**
2. `.env` du serveur : `MAIL_MAX_PER_HOUR=60` (déjà fait le 16/11).
3. Release (`scripts/build-release.sh`) et envoi par FTP.
4. Après une commande de test envoyée : `SELECT * FROM mail_log ORDER BY id DESC LIMIT 5;` → une ligne `qr` par mail.

## Critères de fin

- [x] Avec `MAIL_MAX_PER_HOUR=2` : trois commandes de test donnent deux mails envoyés et une commande `pending` / `rate_limited` (vérifié en local le 16/11 : Apache, MySQL 8.4, Mailpit).
- [x] Le compte reste juste lorsque deux requêtes arrivent en même temps (8 processus simultanés, test automatisé).

## Risques

- ~~La base MySQL mutualisée OVH doit autoriser `GET_LOCK`~~ : vérifié le 16/11.
