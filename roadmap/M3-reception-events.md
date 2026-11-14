# M3 — Réception Events

**Quand** : dimanche 15/11, après le go.
**Dépend de** : M2 (go, fixtures réelles).
**Débloque** : M4.

> **État au 14/11** : codé et testé en local (63 tests, dont MySQL et insertions simultanées). Reste : déploiement sur OVH, puis les critères « sur la production » ci-dessous, vérifiés avec les commandes B à E de M2.

## Objectif

Remplacer la sonde par la vraie réception : vérifier, décider, enregistrer, répondre vite. Gérer le paiement, l'annulation, le remboursement total, la reprise après `errors` et les doublons.

## Table de décision

Lue sur `data.order` après vérification du HMAC.

| Cas | Ligne existante ? | Action |
|---|---|---|
| `errors` présent, `data.order` inutilisable | non | Insérer `error` / `payload_errors` (avec `shopify_order_id` tiré de l'enveloppe si présent), alerte |
| `errors` présent | oui | Rien (ne pas écraser une ligne valide) ; journaliser |
| `cancelledAt` rempli **ou** `REFUNDED` | oui | Révoquer : `revoked_at = NOW()`, `revoked_reason` ; si le mail n'est pas encore parti, il ne partira pas |
| `cancelledAt` rempli **ou** `REFUNDED` | non | Rien |
| `PAID` ou `PARTIALLY_REFUNDED`, non annulée, ligne en `error` / `payload_errors` | oui | **Reprise** : mise à jour des données, `status = 'pending'`, `error_code = NULL`, `attempts = 0` |
| `PAID` ou `PARTIALLY_REFUNDED`, non annulée | oui (autre statut) | Doublon : rien |
| `PAID` ou `PARTIALLY_REFUNDED`, non annulée, aucun produit tagué | non | Insérer `ignored` |
| `PAID` ou `PARTIALLY_REFUNDED`, non annulée, tagué, sans e-mail | non | Insérer `error` / `no_email`, alerte |
| `PAID` ou `PARTIALLY_REFUNDED`, non annulée, tagué, avec e-mail | non | Insérer `pending`, programmer le traitement (M4–M7) |
| Autres statuts (`PENDING`, `AUTHORIZED`, `PARTIALLY_PAID`, `VOIDED`…) | — | Rien |

Toujours répondre `200` après un HMAC valide, même quand rien n'est fait : sinon Shopify relivre.

## Tâches

- [x] Parser la charge utile (forme relevée en M2) dans un objet `OrderSnapshot` : id, nom, e-mail, `statusPageUrl`, statut financier, `cancelledAt`, liste des tags (`src/Domain/`). `OrderEvent` ajoute `errors` et l'identifiant tiré de `query_variables` quand `data.order` est inutilisable.
- [x] Contrôle du tag : `PICKUP_TAG` comparé sans tenir compte de la casse ni des espaces ; `product` nul (produit supprimé, article personnalisé) ignoré.
- [x] Service de décision qui applique la table ci-dessus (`src/Service/OrderDecider.php`). Code pur, sans base, testé par PHPUnit sur les fixtures de M2.
- [x] Dépôt `PickupOrderRepository` (`PdoPickupOrderRepository`) :
  - `insertIfAbsent()` : `INSERT … ON DUPLICATE KEY UPDATE id = id` sur `shopify_order_id` (plutôt qu'`INSERT IGNORE`, qui avalerait aussi les autres erreurs), renvoie si la ligne est nouvelle ;
  - `revoke()` : `UPDATE … SET revoked_at, revoked_reason WHERE shopify_order_id = ? AND revoked_at IS NULL` ;
  - `resumeFromPayloadError()` : `UPDATE … WHERE shopify_order_id = ? AND status = 'error' AND error_code = 'payload_errors'`.
- [x] Conserver `Shopify-Webhook-Id` dans `webhook_id` (diagnostic).
- [x] File de tâches en mémoire (`DeferredTasks`) : le contrôleur y ajoute « traiter la commande N » ; exécutée en M7 après la réponse. En attendant, exécution synchrone à la fin de la requête.
- [x] Alertes : à ce stade, une ligne dans `var/log/alerts.log` ; l'envoi par mail arrive en M7.
- [x] Retirer la sonde (l'écriture brute des livraisons peut rester derrière une option `.env`, `DEBUG_DELIVERIES=0` par défaut, avec purge). **Sur le serveur, `DEBUG_DELIVERIES=1` tant que les commandes B à E de M2 ne sont pas faites** ; purge en M7.
- [x] Tests PHPUnit : un test par ligne de la table de décision ; doublon `create` puis `update` ; HMAC invalide → `401` et aucune écriture. En plus : tests MySQL (`tests/Integration`, base jetable `backoffice_test`), dont 8 processus qui insèrent la même commande en même temps ; fixtures réelles de M2 vérifiées si présentes (`RealFixturesTest`).

## Repli si les annulations ou remboursements ne sont pas livrés (constat de M2)

- Ajouter dans `shopify.app.toml` un second abonnement Events sur `Order` avec les déclencheurs acceptés par `shopify app deploy` (par exemple `order.displayFinancialStatus` seul, qui couvre `REFUNDED`), vers la même route.
- Sinon, webhooks classiques `orders/cancelled` et `refunds/create` vers une route dédiée, avec l'en-tête `X-Shopify-Hmac-Sha256`. Seul l'identifiant de commande est utilisé.

## Critères de fin

- [x] Tous les tests passent en local (63 tests, 14/11). De bout en bout via Apache : `create` + `update` simultanés → une ligne, annulation → révoquée, réponse en 50 ms.
- [ ] Sur la production : une commande provisoire taguée et payée crée une ligne `pending` ; non taguée → `ignored` ; annulée ensuite → `revoked_at` rempli ; remboursée en totalité → `revoked_reason = refunded` ; remboursement partiel → toujours `pending`.
- [ ] Une même commande livrée deux fois (`create` + `update`) ne crée qu'une ligne (vérifié en local avec deux requêtes simultanées ; à revoir en production).
- [ ] Le temps de réponse reste sous la seconde (relevé dans les journaux d'accès OVH ; 50 ms en local).

## Risques

- Livraisons dans le désordre : la requête reflète l'état **au moment de son exécution**, donc une livraison `PAID` reçue après l'annulation renvoie déjà `cancelledAt`. La table de décision gère ce cas.
- Commande annulée **avant** la première livraison exploitable : pas de ligne, donc pas de mail. C'est le comportement voulu.
