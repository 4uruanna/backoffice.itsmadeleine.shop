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
