# M2 — Validation sur la boutique (go/no-go)

> **Décision : GO plan A** (samedi 14/11, 15 h, avancée d'un jour). Forfait Basic, et pourtant l'e-mail client arrive bien dans la charge utile, sans `errors`.
> Preuves : `20261114-140219-7135bed4-….json` (`create`) et `20261114-140219-b1a5a273-….json` (`update`), commande #1094, signatures valides.
> Reste à confirmer (non bloquant pour le go) : livraisons sur paiement différé, produit non tagué, annulation et remboursements (commandes B à E).

**Quand** : dimanche 15/11 au matin. **Décision au plus tard à midi.**
**Dépend de** : M0 (app installée, abonnement déployé), M1 (sonde en ligne).
**Débloque** : M3 (go) ou l'[annexe plan B](annexe-plan-b.md) (no-go).

## Objectif

Lever les points bloquants du §4 du plan v2 avec de vraies livraisons, sur la boutique de production, sans paiement réel.

## Méthode de test sur la production

- Créer un **produit de test** tagué `retrait-qr`, à 0 € ou 1 €, sans suivi de stock, **non publié** sur la boutique en ligne (invisible des clients).
- Passer des commandes depuis l'admin : **Commandes → Créer une commande** (brouillon), client = ta propre adresse, puis :
  - « Marquer comme payée » à la création → cas `create` déjà payé ;
  - « Paiement en attente », puis plus tard « Marquer comme payée » → cas `update`.
- Annuler et archiver ces commandes après les tests (elles apparaissent dans les statistiques).
- Les mails de confirmation Shopify partent vers ta propre adresse : c'est voulu.

## Tâches

- [x] **Commande A** (#1094, 14/11) : produit tagué, payée à la création → **deux** livraisons reçues à la même seconde, `create` **et** `update` (même `Shopify-Triggered-At`), toutes deux `PAID` avec e-mail et `statusPageUrl`, sans `errors`. Le dédoublonnage de M3 (`INSERT IGNORE`) doit tenir face à deux requêtes simultanées.
- [ ] **Commande B** : produit tagué, paiement en attente puis payée → livraison à la création (`PENDING`) **et** au passage à `PAID` ?
- [ ] **Commande C** : produit non tagué, payée → `tags` lisibles et sans le tag ?
- [ ] **Annulation** de la commande A → livraison avec `cancelledAt` rempli ?
- [ ] **Remboursement total** d'une commande payée (créer D) → livraison avec `REFUNDED` ?
- [ ] **Remboursement partiel** (commande E, deux articles) → livraison avec `PARTIALLY_REFUNDED` ?
- [x] Vérifier sur chaque livraison : HMAC valide, en-têtes `Shopify-Webhook-Id` présents, forme exacte de la charge utile (champs d'enveloppe, emplacement de `data`), `statusPageUrl` rempli. Relevé du 14/11 :
  - enveloppe : `topic`, `action`, `handle`, `data`, `fields_changed`, `query_variables` ; la commande est dans `data.order` ;
  - en-têtes (sans préfixe `X-`) : `Shopify-Topic` (`Order`), `Shopify-Action` (`create`/`update`), `Shopify-Handle`, `Shopify-Webhook-Id` (UUID), `Shopify-Triggered-At`, `Shopify-Shop-Domain` (`itsmadeleine-love-2.myshopify.com`), `Shopify-Shop-Id`, `Shopify-Api-Key`, `Shopify-Api-Version` (`2026-10`) ;
  - `fields_changed` pourra servir en M3 à savoir quel déclencheur a joué (à lire dans les fixtures).
- [ ] Lire le délai entre l'action dans l'admin et la livraison.
- [x] Reporter les résultats de `probe.php` (M1), relevés le 14/11 sur OVH : PHP 8.5.12 en `fpm-fcgi`, `fastcgi_finish_request` **oui** (traitement après réponse possible en M7), GD **oui** (PNG), Imagick oui, `pdo_mysql` oui. Fuseau PHP par défaut `Europe/Paris` : sans effet, l'API force UTC.
- [ ] Copier une charge utile réelle de chaque cas (payée, non taguée, annulée, remboursée, avec `errors` si l'occasion se présente) dans `tests/fixtures/`, **e-mail remplacé par une adresse fictive**. Elles serviront aux tests de M3.
- [ ] Supprimer `public/probe.php`.

## Analyse des livraisons

Récupérer par FTP le dossier `~/backoffice/var/log/deliveries/` du serveur dans `var/deliveries-ovh/` du dépôt (ignoré par git : données personnelles), puis :

```bash
php scripts/analyse-livraisons.php var/deliveries-ovh
```

Le script demande le secret client sans l'afficher (Entrée vide : pas de vérification) et résume chaque livraison : signature, en-têtes, enveloppe, chemin de la commande, e-mail présent ou absent (masqué), statut financier, `cancelledAt`, `statusPageUrl`, tags, `errors`.

Pour écrire les fixtures anonymisées (e-mails et `statusPageUrl` remplacés, signature recalculée avec le secret des tests) :

```bash
php scripts/analyse-livraisons.php var/deliveries-ovh --fixtures=tests/fixtures
```

Relire les fixtures avant de les commiter.

## Grille de décision

| Résultat | Décision |
|---|---|
| E-mail présent, livraisons `create` et `update` reçues | **Go plan A** → M3 |
| E-mail présent mais pas de livraison sur annulation ou remboursement | **Go plan A** + repli pour la révocation (voir M3) |
| `email` nul ou dans `errors` (données protégées) | **No-go** → changer de forfait ou [plan B](annexe-plan-b.md) |
| Aucune livraison | Chercher la cause (abonnement, URL, SSL) jusqu'à midi, puis bascule sur le repli webhook classique (§13 du plan v2) ou plan B |
| `fastcgi_finish_request` absent | Go quand même : traitement synchrone en M7 |
| GD absent | Go : passer `chillerlan/php-qrcode` en sortie SVG ou Imagick (à vérifier) |

## Critères de fin

- Décision go/no-go écrite en tête de ce fichier, avec la date et les preuves (noms des fichiers de livraison).
- Fixtures anonymisées enregistrées dans `tests/fixtures/`.
- Commandes de test annulées et archivées.

## Risques

- La commande provisoire marquée payée peut ne pas se comporter comme un vrai paiement en ligne (statut, déclencheurs). Un vrai paiement est testé en recette (M8) si possible.
- Fuite de données personnelles dans le dépôt : ne jamais commiter `var/log/deliveries/`.
