# Roadmap — QR code de retrait en boutique

Établie le 14/11/2026 à partir du plan v2 (« Plan — QR code de retrait en boutique », 14/11/2026) et des décisions prises le même jour.

**Objectif** : quand une commande payée contient un produit tagué `retrait-qr`, le client reçoit un mail avec un QR code. En boutique, le staff le scanne avec son téléphone et arrive sur la commande dans l'admin Shopify.

**Échéance** : en service le **mercredi 18/11 au soir**, donc avant le 19/11. Tout le plan v2 est livré à cette date, y compris le bucket, le cron, les alertes et la purge.

**Point de non-retour** : **dimanche 15/11 à midi** (go/no-go du jalon M2). Si l'e-mail client n'arrive pas dans la charge utile, on bascule sur le [plan B](roadmap/annexe-plan-b.md).

## Planning

| Jour | Jalons | Résultat attendu le soir |
|---|---|---|
| Sam. 14/11 | [M0](roadmap/M0-prerequis.md), [M1](roadmap/M1-socle.md) | App Shopify installée, abonnement déployé, DNS mail en propagation, socle en ligne sur `backoffice.itsmadeleine.shop` avec une sonde qui journalise les livraisons |
| Dim. 15/11 | [M2](roadmap/M2-go-no-go.md) (matin), [M3](roadmap/M3-reception-events.md) | Go/no-go prononcé ; réception Events complète (HMAC, décision, dédoublonnage, révocation) |
| Lun. 16/11 | [M4](roadmap/M4-qr-et-scan.md), [M5](roadmap/M5-mail.md) | QR généré, scan qui redirige ou avertit, mail envoyé de bout en bout |
| Mar. 17/11 | [M6](roadmap/M6-bucket.md), [M7](roadmap/M7-fiabilite-cron.md) | Plafond de 120 mails par heure, traitement après réponse, cron de rattrapage, alertes, purge |
| Mer. 18/11 | [M8](roadmap/M8-recette-mise-en-service.md) | Recette sur la boutique de production, mise en service |
| Après le 19/11 | [M9](roadmap/M9-apres-lancement.md) | Consolidation (CI, supervision, améliorations) |

## Jalons

| # | Jalon | Dépend de | Bloquant pour le 19/11 |
|---|---|---|---|
| M0 | [Prérequis Shopify, OVH et mail](roadmap/M0-prerequis.md) | — | Oui |
| M1 | [Socle de l'API et sonde de livraison](roadmap/M1-socle.md) | M0 | Oui |
| M2 | [Validation sur la boutique (go/no-go)](roadmap/M2-go-no-go.md) | M0, M1 | Oui, décide plan A ou plan B |
| M3 | [Réception Events](roadmap/M3-reception-events.md) | M2 | Oui |
| M4 | [QR code et route de scan](roadmap/M4-qr-et-scan.md) | M3 | Oui |
| M5 | [Mail au client](roadmap/M5-mail.md) | M4 | Oui |
| M6 | [Limite d'envoi (bucket)](roadmap/M6-bucket.md) | M5 | Oui |
| M7 | [Fiabilité : traitement après réponse, cron, alertes, purge](roadmap/M7-fiabilite-cron.md) | M6 | Oui |
| M8 | [Recette et mise en service](roadmap/M8-recette-mise-en-service.md) | M7 | Oui |
| M9 | [Après lancement](roadmap/M9-apres-lancement.md) | M8 | Non |
| — | [Annexe : plan B (QR dans la notification Shopify)](roadmap/annexe-plan-b.md) | M2 en no-go | Seulement en cas de no-go |

Chemin critique : M0 → M1 → M2 → M3 → M4 → M5 → M6 → M7 → M8, sans marge. Seules les tâches DNS de M0 (propagation) et la rédaction du mail (M5) peuvent avancer en parallèle.

## Décisions prises

Plan v2 (§2), plus les décisions du 14/11 :

| Sujet | Décision |
|---|---|
| Périmètre au 19/11 | Tout le plan v2, sans MVP intermédiaire |
| Dépôt | Ce dépôt contient l'API Slim (racine) et la config de l'app Shopify (`shopify/shopify.app.toml`) |
| URL de l'API | `https://backoffice.itsmadeleine.shop` |
| DNS et mail | Zone DNS et mails du domaine chez OVH ; envoi depuis `commandes@itsmadeleine.shop` (MX Plan) |
| Développement local | Docker Compose (PHP à la version OVH, MySQL, Mailpit) + PHPUnit sur les parties critiques |
| Déploiement | FTP/SFTP manuel, `vendor/` compris ; CI reportée en M9 |
| Boutique de test | La boutique de production, avec des commandes provisoires marquées payées (voir M2 et M8) |
| Appareil de scan | Téléphone du staff, appareil photo + app Shopify (ou navigateur connecté à l'admin) |
| Échecs | Nouveau statut `error` (définitif, jamais rejoué, alerte). `failed` reste rejouable ; au 3e échec, `failed` → `error` |
| Livraison valide après `errors` | Elle reprend la commande (`error` dû à `payload_errors` → `pending`) |
| Contenu du mail | Texte simple + lien vers la page de statut de la commande (`statusPageUrl`, qui indique le point de retrait) + QR + numéro de commande |
| Alertes internes | Vers `ALERT_EMAIL` (adresse perso, dans `.env`) |
| Commande annulée ou remboursée en totalité | **QR invalidé** (modifie le §11 du plan v2). Remboursement partiel : QR toujours valide |
| Scan d'un QR invalidé | Page d'avertissement « ne pas remettre », avec lien vers la commande, scan journalisé |
| Conservation | Commandes et scans purgés après 12 mois ; `mail_log` après 30 jours |
| Autres choix du §11 | Validés : pas de nouvel envoi après modification, un QR par panier mixte, 25 lignes examinées, pas de renvoi manuel en v1, français seul, une seule boutique |

## Écarts par rapport au plan v2

Ces changements découlent des décisions ci-dessus ; ils sont détaillés dans les jalons concernés.

1. **Abonnement Events** : déclencheurs `order.displayFinancialStatus` **et** `order.cancelledAt`, **sans `query_filter`**. L'API décide selon l'état renvoyé (payée, annulée, remboursée). Requête enrichie de `statusPageUrl` et `cancelledAt` (M0, M3).
2. **Table `pickup_orders`** : statut `error` ajouté ; nouvelles colonnes `error_code`, `status_page_url`, `revoked_at`, `revoked_reason` (M1).
3. **Table `scans`** : colonne `result` (`redirected` ou `revoked`) (M1, M4).
4. **Éligibilité** : une commande `PARTIALLY_REFUNDED` non annulée est traitée comme payée, au cas où la première livraison reçue arrive après un remboursement partiel (M3).
5. **Dates** : toutes en UTC (`SET time_zone = '+00:00'` à la connexion), pour que le calcul du bucket ne dépende pas du fuseau du serveur (M1).
6. **Recette** : commandes provisoires (brouillons) créées dans l'admin et marquées payées : ni paiement réel ni passerelle de test à activer sur la boutique de production (M2, M8).

## Points encore ouverts

- **Forfait Shopify inconnu** : à lire dans l'admin (Paramètres → Forfait) dès M0. Sur le forfait Basic, le risque de no-go en M2 est élevé.
- Le déclencheur `order.cancelledAt` est supposé exister : il faut le vérifier au `shopify app deploy` (M0) et à la livraison réelle (M2). Repli décrit dans M3.
- Délais de réponse et nouveaux essais d'Events : on suppose les mêmes que pour les webhooks classiques (5 s, 8 essais sur 4 h).

## Hors périmètre v1

Renvoi manuel du mail, mail multilingue, plusieurs boutiques, nouvel envoi après modification de commande, filtrage du tag côté Shopify (`query_filter`), CI de déploiement : voir [M9](roadmap/M9-apres-lancement.md).
