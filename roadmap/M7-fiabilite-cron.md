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

- [ ] Garde-fou : refuser l'exécution hors ligne de commande (`PHP_SAPI !== 'cli'`) ; verrou `GET_LOCK('cron_retry', 0)` pour éviter deux passages simultanés.
- [ ] Débloquer : `processing` dont `locked_at` a plus de 15 min → `failed` (`last_error = 'stale_lock'`).
- [ ] Reprendre `pending` et `failed` non révoquées, **les plus anciennes d'abord** ; s'arrêter dès que le bucket est vide.
- [x] (fait en M5 : `markFailed`, alerte dans `var/log/alerts.log`) Échec au-delà de `MAX_ATTEMPTS` (3) → `error`, `error_code = 'max_attempts'`, à mettre dans l'alerte.
- [ ] Une commande révoquée encore `pending` ou `failed` n'est jamais envoyée (reste en l'état, visible dans les requêtes de suivi).
- [ ] **Alertes groupées** : un seul mail par passage vers `ALERT_EMAIL`, qui liste les nouvelles `error` (`payload_errors`, `no_email`, `max_attempts`) depuis le passage précédent. Le mail d'alerte passe par le bucket (`kind = 'alert'`) ; bucket vide → l'alerte attend le passage suivant.
- [ ] Les alertes notées en M3 dans `var/log/alerts.log` passent désormais par ce mail (le journal reste en secours).
- [ ] **Purge** : `pickup_orders` (et leurs `scans`, par cascade) dont `created_at` a plus de `RETENTION_MONTHS` (12) ; `mail_log` de plus de `MAIL_LOG_RETENTION_DAYS` (30) ; fichiers de `var/log/` de plus de 30 jours.
- [ ] Sortie texte résumée (traitées, envoyées, en attente, en erreur, purgées), lisible dans le journal du cron OVH.
- [ ] Déclarer la tâche dans l'espace client OVH : `backoffice/cron/retry.php`, toutes les heures, même version de PHP, journal activé.

### Suivi

- [ ] Requêtes de suivi prêtes à coller dans phpMyAdmin (fichier `docs/requetes-suivi.sql`) : commandes du jour par statut, file `pending`, erreurs, scans du jour, consommation du bucket sur l'heure.

## Critères de fin

- Réponse au webhook en moins d'une seconde, même avec un envoi lent (simuler en local un SMTP qui ne répond pas).
- Envoi coupé (mauvais mot de passe SMTP en local, ou `MAIL_FROM` refusé par l'hébergement) : la commande passe `failed`, le cron la rejoue, puis `error` au 3e échec, et une seule alerte part.
- Bucket saturé : le cron vide la file au passage suivant, les plus anciennes d'abord.
- Le cron tourne sur OVH et son journal montre le résumé.

## Risques

- Le cron OVH ne tourne qu'une fois par heure : une commande bloquée par un bucket saturé peut attendre jusqu'à une heure. C'est accepté (§2 du plan v2).
- Un `processing` bloqué par un crash ne repart qu'au passage suivant du cron.
