# M8 — Recette et mise en service

**Quand** : mercredi 18/11. Mise en service le soir au plus tard.
**Dépend de** : M7.
**Débloque** : le service en production le 19/11.

## Objectif

Rejouer tous les scénarios sur la boutique de production, avec le code final déployé, puis ouvrir le service.

## Préparation

- [ ] Déploiement FTP de la version finale (`composer install --no-dev`), migration à jour, `.env` relu (`MAIL_TRANSPORT=phpmail`, `MAIL_FROM` aligné sur le domaine de l'hébergement, `MAIL_MAX_PER_HOUR` sous le quota de l'offre, `DEBUG_DELIVERIES=0`) ; `/health` affiche la bonne `version`.
- [ ] Produit de test de M2 (tagué, non publié) toujours disponible.
- [ ] Commandes passées par brouillon depuis l'admin (voir M2), client = ta propre adresse.

## Scénarios

### Déroulé proposé pour les scénarios liés au cron (7 + 14, puis 6)

Les deux modifient le `.env` et dépendent du bucket : **l'un après l'autre**, jamais en même temps. Compter 3 à 4 passages du cron au total.

**Scénarios 7 et 14 (bucket saturé, annulation avant l'envoi)** — un passage du cron :

1. Requête 5 de `docs/requetes-suivi.sql` : nombre de mails partis sur l'heure glissante (N). Mettre `MAIL_MAX_PER_HOUR` à N + 1 dans le `.env` (1 si aucun mail depuis une heure).
2. Trois brouillons tagués, client = ton adresse, marqués payés, à une minute d'écart : 7a, 7b, 14.
3. Attendu tout de suite : 7a reçu ; 7b et 14 en `pending` / `rate_limited`.
4. Annuler 14 dans Shopify (révocation).
5. Remettre `MAIL_MAX_PER_HOUR=90`, attendre le passage du cron : 7b reçu, 14 reste `pending` révoquée, **aucun mail pour 14**.

Résultat (17/11) : aucun mail sur l'heure glissante, `MAIL_MAX_PER_HOUR=1`. #1126 (7a) reçu ; #1127 (7b) et #1128 (14) en `pending` / `rate_limited` ; #1128 annulée ; `MAIL_MAX_PER_HOUR` remis à 90. En attente du passage du cron (#1127 envoyée, #1128 sans mail).

**Scénario 6 (envoi en panne)** — trois passages du cron :

1. `MAIL_FROM` sur un autre domaine dans le `.env` (refusé par l'hébergement, vu en M5b).
2. Un brouillon tagué marqué payé : 1er échec à la réception (`failed`, 1 essai).
3. Passage du cron : 2e échec (`failed`, 2 essais). Passage suivant : 3e échec → `error` / `max_attempts`. **L'alerte part par le même transport : elle échoue aussi** (« échec d'envoi de l'alerte, nouvel essai au passage suivant »), c'est attendu.
4. Rétablir `MAIL_FROM` : au passage suivant, **une** alerte arrive (la commande y figure) ; la commande reste en `error`, sans mail au client.
5. Raccourci possible si le temps manque : après le 1er échec, `UPDATE pickup_orders SET attempts = 2 WHERE order_name = '#…';` pour passer en `error` dès le passage suivant.

### Liste complète

**Cadence du cron** : il ne passe qu'une fois par heure (sauf lancement à la main par SSH, si l'offre le permet). Le scénario 6 demande trois passages (environ 2 h), le 7 un passage : **les lancer en premier mercredi**, puis dérouler les autres en attendant. Penser à remettre `MAIL_MAX_PER_HOUR` à sa valeur (90) et `MAIL_FROM` après coup.

| # | Scénario | Attendu |
|---|---|---|
| 1 | Commande taguée, payée à la création | Mail reçu en quelques secondes, ligne `sent` |
| 2 | Commande taguée, payée plus tard | Un seul mail, au passage à payée |
| 3 | Commande non taguée | Aucun mail, ligne `ignored` |
| 4 | Panier mixte (tagué + non tagué) | Un mail |
| 5 | Livraison en double (renvoyer depuis le tableau de bord de l'app si possible, sinon `curl` signé avec une fixture) | Aucun second mail |
| 6 | Envoi en panne (`MAIL_FROM` sur un autre domaine dans `.env` → refus d'OVH, cron lancé à la main) | `failed` → `error` au 3e échec, une alerte groupée |
| 7 | Bucket saturé (`MAIL_MAX_PER_HOUR=1`, deux commandes) | Un mail, une commande `pending` / `rate_limited`, envoyée au passage suivant du cron après remise à 90 |
| 8 | Commande sans e-mail client (brouillon sans client) | `error` / `no_email`, alerte |
| 9 | Scan depuis le téléphone du staff connecté à l'admin | La commande s'ouvre (app Shopify ou navigateur) |
| 10 | Scan depuis un téléphone non connecté | Page de connexion Shopify |
| 11 | Commande annulée **après** l'envoi, puis scan | Page d'avertissement « annulée » avec le lien vers la commande |
| 12 | Commande remboursée en totalité, puis scan | Page d'avertissement « remboursée » |
| 13 | Commande remboursée partiellement, puis scan | Redirection normale |
| 14 | Commande annulée **avant** l'envoi (bucket saturé) | Aucun mail ne part |
| 15 | Jeton inventé (`/r/000…`) | `404` neutre |
| 16 | Mail reçu sur Gmail et sur Outlook | Hors spam, QR affiché, lien de statut correct |
| 17 | Si possible, une vraie commande en ligne payée avec un petit montant, puis remboursée | Même résultat que 1, puis 12 |

- [ ] Rétablir `.env` après les scénarios 6 et 7 (`MAIL_FROM`, `MAIL_MAX_PER_HOUR`).

## Mise en service

- [ ] **Vider `MAIL_ALLOWLIST`** dans le `.env` du serveur (garde-fou de M5), puis vérifier les commandes restées en attente : `SELECT order_name, created_at FROM pickup_orders WHERE status = 'pending' AND pending_reason = 'not_allowlisted';` Ce sont de vraies commandes : le cron (M7) leur enverra le mail au passage suivant.
- [ ] Annuler et archiver toutes les commandes de test ; laisser le produit de test non publié (ou l'archiver).
- [ ] Taguer `retrait-qr` sur les vrais produits à retirer en boutique.
- [ ] Brief du staff (5 min) : scanner avec l'appareil photo, rester connecté à l'app Shopify sur le téléphone, marquer la commande comme retirée dans Shopify, ne rien remettre si la page rouge s'affiche. Client sans QR (mail en indésirables, notamment Outlook) : numéro de commande et pièce d'identité de la personne au nom de laquelle la commande a été passée (comme l'indique le mail), commande retrouvée dans Shopify.
- [ ] Vider ou conserver les lignes de test en base (les supprimer pour un suivi propre).
- [ ] Surveillance renforcée jeudi 19 et vendredi 20 : requêtes de suivi matin et soir, boîte d'alertes, journal du cron.

## Critères de fin

- Les 16 premiers scénarios passent (le 17 si possible).
- Les produits concernés sont tagués.
- Le staff sait scanner et réagir à la page d'avertissement.

## Si un scénario bloque mercredi soir

- Bloquant pour le client (pas de mail, mail en spam, QR illisible) : ne pas taguer les produits, le service reste inactif sans effet sur les commandes.
- Non bloquant (alerte mal formée, purge) : mise en service, correction en M9.
