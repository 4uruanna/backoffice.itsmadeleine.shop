# M7 — Fiabilité : traitement après réponse, cron, alertes, purge

**Quand** : mardi 17/11, après-midi.
**Dépend de** : M6.
**Débloque** : M8.

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

- Réponse au webhook en moins d'une seconde, même avec un envoi lent (simuler en local un SMTP ou une API Mailjet qui ne répond pas).
- Envoi coupé (mauvais mot de passe SMTP ou clé Mailjet invalide) : la commande passe `failed`, le cron la rejoue, puis `error` au 3e échec, et une seule alerte part.
- Bucket saturé : le cron vide la file au passage suivant, les plus anciennes d'abord.
- Le cron tourne sur OVH et son journal montre le résumé.

## Risques

- Le cron OVH ne tourne qu'une fois par heure : une commande bloquée par un bucket saturé peut attendre jusqu'à une heure. C'est accepté (§2 du plan v2).
- Un `processing` bloqué par un crash ne repart qu'au passage suivant du cron.
