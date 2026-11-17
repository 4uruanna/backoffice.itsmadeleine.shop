# M7 — Fiabilité : traitement après réponse, cron, alertes, purge

**Quand** : mardi 17/11, après-midi.
**Dépend de** : M6.
**Débloque** : M8.

## Reprise (état au 16/11 au soir)

À lire avant de commencer.

- **Déjà fait en M5/M6** (cases cochées ci-dessous) : réponse à Shopify avant l'envoi (`fastcgi_finish_request`), prise en charge atomique (`PickupOrderRepository::claim`), passage en `error` / `max_attempts` au 3e échec avec alerte dans `var/log/alerts.log`, bucket (`PdoMailBucket`, `mail_log`).
- **Point d'entrée à réutiliser** : `PickupProcessor::process(int $id)` (`src/Service/`) fait tout le traitement d'une ligne (prise en charge, liste d'autorisation, jeton, contrôle de révocation, bucket, envoi, statut). Le cron n'a qu'à choisir les lignes et l'appeler ; il ne doit pas dupliquer cette logique.
- **États à rattraper** : `pending` (dont `pending_reason` = `rate_limited`, `not_allowlisted`, `revoked`) et `failed`. Une ligne `pending` / `revoked` reste révoquée (`claim` l'exclut). Une ligne `not_allowlisted` repart dès que `MAIL_ALLOWLIST` est vidée (M8).
- **Envoi en production** : `MAIL_TRANSPORT=phpmail` (fonction `mail()` de l'hébergement), voir [M5b](M5b-envoi-hebergement.md). Les alertes groupées passent par le même transport et le même bucket (`kind = 'alert'`).
- **En base de production** : plusieurs commandes de test en `pending` / `rate_limited` et en `failed` (essais du 16/11 pendant le blocage SMTP). Le premier passage du cron les enverra à l'adresse de `MAIL_ALLOWLIST` : prévenu, c'est attendu.
- **Outils** : `/health` affiche la version déployée et le transport ; `scripts/build-release.sh` écrit `VERSION`. Tests : Docker Compose (`docker compose run --rm php vendor/bin/phpunit`), MySQL compris.
- **Hébergement OVH** : `.ovhconfig` en `environment=production` (OPcache : un fichier PHP remplacé peut mettre quelques minutes à être pris en compte). Le cron OVH lance `php` en ligne de commande : vérifier sa version (8.5) dans la déclaration de la tâche.
- **En suspens, hors M7** : enrichir le texte du mail pour Outlook (en indésirables, `SCL 5`, voir M5b) ; il faut savoir si autre chose que le QR est à présenter le jour du concert et s'il faut une ligne de contact.

## Avancement (16/11 au soir)

**Code fait et testé en local**, pas encore déployé. Tests : 156 au vert (dont les requêtes du cron et la purge sur MySQL). Passage complet de `cron/retry.php` sur une base locale, avec un faux `sendmail` :

- `processing` abandonné depuis 20 min → débloqué, rejoué et envoyé dans le même passage (1 essai compté) ;
- `failed` à 2 essais qui échoue encore → `error` / `max_attempts` ;
- commande annulée encore `pending` → jamais envoyée ;
- **une seule** alerte pour les deux nouvelles `error` (`max_attempts`, `no_email`), sans e-mail client ; passage suivant : aucune nouvelle alerte ;
- purge : commande de 13 mois (et son scan), `mail_log` de 40 jours, fichier de journal de 40 jours ;
- bucket saturé : un mail part, la commande suivante passe `rate_limited` et le passage s'arrête ; la suite au passage suivant ;
- verrou tenu par une autre session : « un autre passage est en cours », sortie 0.

Tests relancés dans Docker Compose (PHP 8.5.12, PHPUnit 13.4.7) : **156 tests OK**, comme dans l'environnement de développement.

**Fait sur OVH le 16/11 au soir** : migration `002` exécutée, `ALERT_EMAIL` renseignée, release déployée, tâche planifiée déclarée. **Le 17/11 : le cron tourne sur OVH** et s'exécute correctement. Reste à confirmer la réception des mails de test (adresse de `MAIL_ALLOWLIST`) et de l'alerte groupée (`ALERT_EMAIL`) ; les critères d'envoi coupé et de bucket saturé, validés en local, seront rejoués en production en M8 (scénarios 6 et 7).

Étapes suivies (pour mémoire) :

1. phpMyAdmin : exécuter `migrations/002_alerted_at.sql` **avant** de déployer (sinon la colonne `alerted_at` manque et `markFailed` échoue).
2. `.env` du serveur : renseigner `ALERT_EMAIL`.
3. `scripts/build-release.sh`, envoi FTP (la release contient maintenant `cron/`), `/health` pour vérifier la version.
4. Déclarer la tâche planifiée (voir plus bas), puis lire le journal du premier passage : les commandes de test en `pending` / `rate_limited` et `failed` partent vers l'adresse de `MAIL_ALLOWLIST`, et **une** alerte liste les commandes déjà en `error` (essais du 14 au 16/11).

## Objectif

Répondre à Shopify immédiatement, traiter ensuite, rattraper tout ce qui a échoué ou attendu, prévenir en cas de problème, et purger.

## Tâches

### Traitement après réponse

- [x] (fait en M5, 16/11) `public/index.php` : `$app->run()`, puis `fastcgi_finish_request()` s'il existe (résultat de M2), puis exécution de `DeferredTasks`. `ignore_user_abort(true)` et `set_time_limit(60)` avant le traitement.
- [x] (fait en M5) Sans `fastcgi_finish_request` : traitement synchrone avant la réponse (un nouvel essai de Shopify n'enverra pas de doublon, grâce au dédoublonnage et à la prise en charge atomique).
- [x] (fait en M5 : `PickupOrderRepository::claim`, appelée par `PickupProcessor`) Prise en charge atomique :

```sql
UPDATE pickup_orders
SET status = 'processing', locked_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
WHERE id = ? AND status IN ('pending','failed') AND revoked_at IS NULL;
```

  On ne traite que si une ligne a été modifiée.

### Cron `cron/retry.php` (toutes les heures)

- [x] (fait le 16/11, `cron/retry.php`) Garde-fou : refuser l'exécution hors ligne de commande (`PHP_SAPI !== 'cli'`) ; verrou `GET_LOCK('cron_retry', 0)` pour éviter deux passages simultanés.
- [x] (fait, `PickupOrderRepository::unlockStale`) Débloquer : `processing` dont `locked_at` a plus de 15 min → `failed` (`last_error = 'stale_lock'`). Le blocage **compte comme un essai** (un crash après l'envoi ne peut pas produire plus de 3 mails) ; au dernier essai → `error` / `max_attempts`, signalée par l'alerte.
- [x] (fait, `RetryRunner` + `PickupProcessor::processResult`) Reprendre `pending` et `failed` non révoquées, **les plus anciennes d'abord** (500 par passage au plus) ; s'arrêter dès que le bucket est vide (ou au quota du serveur d'envoi).
- [x] (fait en M5 : `markFailed`, alerte dans `var/log/alerts.log`) Échec au-delà de `MAX_ATTEMPTS` (3) → `error`, `error_code = 'max_attempts'`, à mettre dans l'alerte.
- [x] (fait : exclue de la file et de `claim`) Une commande révoquée encore `pending` ou `failed` n'est jamais envoyée (reste en l'état, visible dans les requêtes de suivi).
- [x] (fait, `AlertMailer`, colonne `alerted_at` : `migrations/002_alerted_at.sql`) **Alertes groupées** : un seul mail par passage vers `ALERT_EMAIL`, qui liste les nouvelles `error` (`payload_errors`, `no_email`, `max_attempts`) pas encore signalées. Texte seul : numéro de commande, code, nombre d'essais, dernière erreur, lien vers l'admin ; **pas l'e-mail du client**. Le mail d'alerte passe par le bucket (`kind = 'alert'`) ; bucket vide, quota ou échec d'envoi → l'alerte attend le passage suivant. `ALERT_EMAIL` vide → rien n'est envoyé ni marqué. Une ligne qui repasse en `error` (après reprise) est de nouveau signalée.
- [x] Les alertes notées en M3 dans `var/log/alerts.log` passent désormais par ce mail (le journal reste en secours ; une erreur du cron y est aussi écrite, code `cron_failed`).
- [x] (fait, `Purger`) **Purge** : `pickup_orders` (et leurs `scans`, par cascade) dont `created_at` a plus de `RETENTION_MONTHS` (12) ; `mail_log` de plus de `MAIL_LOG_RETENTION_DAYS` (30) ; fichiers de `var/log/` de plus de 30 jours (sauf `.htaccess`). Une durée à `0` désactive la purge concernée.
- [x] Sortie texte résumée (une ligne par commande traitée, puis traitées, envoyées, en attente, en erreur, file restante, alertes, purge), lisible dans le journal du cron OVH, sans donnée personnelle.
- [x] (fait le 16/11) Déclarer la tâche dans l'espace client OVH : `backoffice/cron/retry.php`, toutes les heures, même version de PHP, journal activé. Pas à pas : [`docs/deploiement.md`](../docs/deploiement.md#cron-de-rattrapage-m7). **Avant** : exécuter `migrations/002_alerted_at.sql` dans phpMyAdmin, renseigner `ALERT_EMAIL` dans le `.env` du serveur, déployer la release (elle contient `cron/`).

### Suivi

- [x] (fait) Requêtes de suivi prêtes à coller dans phpMyAdmin ([`docs/requetes-suivi.sql`](../docs/requetes-suivi.sql)) : commandes du jour par statut, file `pending`, erreurs, scans du jour, consommation du bucket sur l'heure, traitements bloqués, recherche par numéro.

## Critères de fin

- ✅ (M5) Réponse au webhook en moins d'une seconde, même avec un envoi lent (simuler en local un SMTP qui ne répond pas) : réponse envoyée avant le traitement (`fastcgi_finish_request`).
- Envoi coupé (mauvais mot de passe SMTP en local, ou `MAIL_FROM` refusé par l'hébergement) : la commande passe `failed`, le cron la rejoue, puis `error` au 3e échec, et une seule alerte part. ✅ en local (16/11) ; en production : scénario 6 de M8.
- Bucket saturé : le cron vide la file au passage suivant, les plus anciennes d'abord. ✅ en local (16/11) ; en production : scénario 7 de M8.
- ✅ (17/11) Le cron tourne sur OVH et son journal montre le résumé.

## Risques

- Le cron OVH ne tourne qu'une fois par heure : une commande bloquée par un bucket saturé peut attendre jusqu'à une heure. C'est accepté (§2 du plan v2).
- Un `processing` bloqué par un crash ne repart qu'au passage suivant du cron.
