# M6 — Limite d'envoi (bucket)

**Quand** : mardi 17/11, matin.
**Dépend de** : M5 (envoi fonctionnel).
**Débloque** : M7.

## Objectif

Ne jamais dépasser **120 mails sur n'importe quelle période de 60 minutes**, mails clients et alertes confondus (`MAIL_MAX_PER_HOUR`). Bucket saturé → la commande attend le cron.

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

- [ ] Service `MailBucket::tryAcquire(?int $orderId, string $kind): bool` : verrou, comptage, insertion, libération dans un `finally`. `GET_LOCK` qui échoue (délai dépassé) → `false` (traité comme un bucket vide).
- [ ] Tout envoi passe par `tryAcquire` avant l'appel SMTP, alertes comprises.
- [ ] Bucket vide → la commande repasse en `pending` avec `pending_reason = 'rate_limited'`, **sans** incrémenter `attempts`.
- [ ] Échec SMTP après acquisition : le jeton reste consommé (prudence vis-à-vis du quota OVH).
- [ ] Refus SMTP de quota d'OVH (`550 5.7.0 Mail quota exceeded … 200 messages per hour and per account`, vu le 16/11) : traité comme un bucket vide (`pending` / `rate_limited`, `attempts` inchangé), pas comme un échec.
- [ ] Toutes les dates en UTC (`UTC_TIMESTAMP()` en SQL), cohérentes avec la connexion PDO.
- [ ] Tests PHPUnit (sur la base MySQL du Docker) : plafond à 2, deux acquisitions réussies, la troisième échoue ; une ligne vieille de 61 min ne compte plus.

## Critères de fin

- Avec `MAIL_MAX_PER_HOUR=2` : trois commandes de test donnent deux mails envoyés et une commande `pending` / `rate_limited`.
- Le compte reste juste lorsque deux requêtes arrivent en même temps (test avec deux appels parallèles en local).

## Risques

- La base MySQL mutualisée OVH doit autoriser `GET_LOCK` : à tester dès le déploiement de M6 (un appel suffit).
