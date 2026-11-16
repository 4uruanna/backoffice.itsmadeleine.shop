# Roadmap — QR code de retrait en boutique

Établie le 14/11/2026 à partir du plan v2 (« Plan — QR code de retrait en boutique », 14/11/2026) et des décisions prises le même jour.

**Objectif** : quand une commande payée contient un produit tagué `retrait-qr`, le client reçoit un mail avec un QR code. En boutique, le staff le scanne avec son téléphone et arrive sur la commande dans l'admin Shopify.

**Échéance** : en service le **mercredi 18/11 au soir**, donc avant le 19/11. Tout le plan v2 est livré à cette date, y compris le bucket, le cron, les alertes et la purge.

**Point de non-retour** : **dimanche 15/11 à midi** (go/no-go du jalon M2). ✅ Franchi le samedi 14/11 : **go plan A**, l'e-mail client arrive bien dans la charge utile malgré le forfait Basic. Le [plan B](roadmap/annexe-plan-b.md) n'est plus nécessaire.

## État au lundi 16/11 au soir

**Environ un jour d'avance**, conservé malgré le blocage SMTP d'OVH. M0 à M4 sont terminés et validés en production. M5 (mail), M6 (bucket) et M5b (envoi par la fonction `mail()` de l'hébergement) sont en production : le mail de retrait arrive en boîte de réception sur Gmail, en indésirables sur Outlook (authentification complète, `SCL 5`). **M7** (cron de rattrapage, alertes groupées, purge) : code fait et testé en local le 16/11 au soir ; **prochaine étape : le déployer sur OVH** (migration `002`, `ALERT_EMAIL`, release, tâche planifiée), voir l'état en tête de [M7](roadmap/M7-fiabilite-cron.md). Reliquats non bloquants au début de [M4](roadmap/M4-qr-et-scan.md#reprise-au-démarrage--reliquats-de-m0-à-m3).

| Jalon | État | Reste à faire |
|---|---|---|
| M0 | ✅ Terminé (16/11) | — |
| M1 | ✅ Terminé | — |
| M2 | ✅ Go plan A confirmé (14/11) | Restent, non bloquants : paiement différé (B), commandes de test à annuler |
| M3 | ✅ Validé en production (14/11) | Relever le temps de réponse dans les journaux OVH (non bloquant) |
| M4 | ✅ Validé en production (16/11) | Reliquats de la section « Reprise au démarrage » (commande B, nettoyage), non bloquants |
| M5 | Mail et gabarits faits (16/11) | OVH bloque l'envoi SMTP depuis l'hébergement → envoi par `mail()` ([M5b](roadmap/M5b-envoi-hebergement.md)) |
| M5b | ✅ Envoi rétabli par `mail()` de l'hébergement (16/11) ; Gmail : boîte de réception | **Outlook : indésirables** → en-têtes à relever, atténuations (texte, message aux clients, consigne au staff) |
| M6 | Code fait (16/11), testé en local ; `GET_LOCK` OK sur OVH | Déployer, commande de test |
| M7 | Code fait (16/11), testé en local (tests MySQL, passage complet du cron) | Migration `002` dans phpMyAdmin, `ALERT_EMAIL`, déployer, déclarer la tâche planifiée OVH, lire le premier journal |
| M8 | À faire | — |

## Planning

Planning initial, avec ce qui a été réalisé. Le jour d'avance pris le 14/11 sert de marge : M4 peut démarrer dès le dimanche après-midi.

| Jour | Jalons | Résultat attendu le soir | Réalisé |
|---|---|---|---|
| Sam. 14/11 | [M0](roadmap/M0-prerequis.md), [M1](roadmap/M1-socle.md) | App Shopify installée, abonnement déployé, DNS mail en propagation, socle en ligne sur `app.itsmadeleine.shop` avec une sonde qui journalise les livraisons | ✅ Tout, plus le go de M2 et le code de M3 |
| Dim. 15/11 | [M2](roadmap/M2-go-no-go.md) (matin), [M3](roadmap/M3-reception-events.md) | Go/no-go prononcé ; réception Events complète (HMAC, décision, dédoublonnage, révocation) | Fait dès le 14/11 ; code de M4 fait le 15/11 (QR, route de scan, page d'avertissement) |
| Lun. 16/11 | [M4](roadmap/M4-qr-et-scan.md), [M5](roadmap/M5-mail.md) | QR généré, scan qui redirige ou avertit, mail envoyé de bout en bout | M4 validé en production ; M0 terminé ; code de M5 fait (prise en charge atomique et réponse avant envoi avancées de M7) |
| Mar. 17/11 | [M6](roadmap/M6-bucket.md), [M7](roadmap/M7-fiabilite-cron.md) | Plafond de mails par heure, cron de rattrapage, alertes, purge | Code de M6 fait dès le 16/11 ; envoi rétabli le 16/11 au soir par `mail()` ([M5b](roadmap/M5b-envoi-hebergement.md)) ; code de M7 fait le 16/11 au soir |
| Mer. 18/11 | [M8](roadmap/M8-recette-mise-en-service.md) | Recette sur la boutique de production, mise en service | |
| Après le 19/11 | [M9](roadmap/M9-apres-lancement.md) | Consolidation (CI, supervision, améliorations) | |

## Jalons

| # | Jalon | Dépend de | Bloquant pour le 19/11 |
|---|---|---|---|
| M0 | [Prérequis Shopify, OVH et mail](roadmap/M0-prerequis.md) ✅ 16/11 | — | Oui |
| M1 | [Socle de l'API et sonde de livraison](roadmap/M1-socle.md) ✅ 14/11 | M0 | Oui |
| M2 | [Validation sur la boutique (go/no-go)](roadmap/M2-go-no-go.md) ✅ **go plan A** le 14/11 — reste les commandes B à E | M0, M1 | Oui, décide plan A ou plan B |
| M3 | [Réception Events](roadmap/M3-reception-events.md) ✅ 14/11 | M2 | Oui |
| M4 | [QR code et route de scan](roadmap/M4-qr-et-scan.md) ✅ 16/11 | M3 | Oui |
| M5 | [Mail au client](roadmap/M5-mail.md) — code fait le 16/11, reste le test en production | M4 | Oui |
| M5b | [Envoi par la fonction mail() de l'hébergement](roadmap/M5b-envoi-hebergement.md) ✅ 16/11 | M5, M6 | Oui : sans lui, aucun mail ne part du serveur |
| M6 | [Limite d'envoi (bucket)](roadmap/M6-bucket.md) — code fait le 16/11 | M5 | Oui |
| M7 | [Fiabilité : traitement après réponse, cron, alertes, purge](roadmap/M7-fiabilite-cron.md) — code fait le 16/11, reste le déploiement | M6 | Oui |
| M8 | [Recette et mise en service](roadmap/M8-recette-mise-en-service.md) | M7 | Oui |
| M9 | [Après lancement](roadmap/M9-apres-lancement.md) | M8 | Non |
| — | [Annexe : plan B (QR dans la notification Shopify)](roadmap/annexe-plan-b.md) — non utilisé | M2 en no-go | Seulement en cas de no-go |

Chemin critique : M0 → M1 → M2 → M3 → M4 → M5 → M6 → M5b → M7 → M8. Le blocage SMTP d'OVH (M5b) a été levé le 16/11 au soir, sans consommer la marge d'un jour.

## Décisions prises

Plan v2 (§2), plus les décisions du 14/11 :

| Sujet | Décision |
|---|---|
| Périmètre au 19/11 | Tout le plan v2, sans MVP intermédiaire |
| Dépôt | Ce dépôt contient l'API Slim (racine) et la config de l'app Shopify (`shopify/shopify.app.toml`) |
| App Shopify | `itsmadeleine.app` (`client_id` `a977545e…`), créée dans le Dev Dashboard **de l'organisation du client** (une app de l'organisation du développeur ne s'installe pas sur sa boutique) ; config dans `shopify/shopify.app.toml`, Shopify CLI 5 avec pnpm dans `shopify/` |
| Forfait Shopify | Basic : l'e-mail client est bien accessible à l'app (vérifié en M2), pas de changement de forfait |
| URL de l'API | `https://app.itsmadeleine.shop` (au lieu de `backoffice.` prévu au départ, changé le 14/11) ; dossier sur l'hébergement inchangé : `backoffice/public` |
| DNS et mail | Zone DNS et boîtes mail du domaine chez OVH (MX Plan). Envoi depuis `commandes@itsmadeleine.shop` par la fonction `mail()` de l'hébergement depuis le 16/11 (SMTP OVH bloqué depuis l'hébergement) |
| Développement local | Docker Compose (PHP 8.5 comme OVH, MySQL 8.4, Mailpit) + PHPUnit sur les parties critiques |
| Déploiement | FTP/SFTP manuel, `vendor/` compris, release préparée par `scripts/build-release.sh` (Docker, ou Composer du poste avec `USE_HOST_COMPOSER=1`) ; CI reportée en M9. Pas à pas : [`docs/deploiement.md`](docs/deploiement.md) |
| Hébergement | OVH Perso, PHP 8.5 (FPM, `fastcgi_finish_request` et GD disponibles), multisite `app.itsmadeleine.shop` → `backoffice/public` |
| Boutique de test | La boutique de production, avec des commandes provisoires marquées payées (voir M2 et M8) |
| Appareil de scan | Téléphone du staff, appareil photo + app Shopify (ou navigateur connecté à l'admin) |
| Échecs | Nouveau statut `error` (définitif, jamais rejoué, alerte). `failed` reste rejouable ; au 3e échec, `failed` → `error` |
| Livraison valide après `errors` | Elle reprend la commande (`error` dû à `payload_errors` → `pending`) |
| Contenu du mail | Texte simple + lien vers la page de statut de la commande (`statusPageUrl`, qui indique le point de retrait) + QR + numéro de commande. Phrase validée le 16/11 : « Présentez ce QR code le jour du concert pour retirer votre commande. » |
| Garde-fou avant mise en service | `MAIL_ALLOWLIST` (16/11) : tant qu'elle est renseignée, seuls ses destinataires reçoivent le mail ; vidée en M8 |
| Envoi des mails | **Fonction `mail()` de l'hébergement** (16/11), `MAIL_TRANSPORT=phpmail`, expéditeur `commandes@itsmadeleine.shop` aligné sur le domaine principal de l'hébergement : OVH refuse les envois SMTP depuis l'IP mutualisée. Pas de service transactionnel externe. SMTP conservé pour le local (Mailpit) et en repli |
| Plafond d'envoi | `MAIL_MAX_PER_HOUR=90`, sous le quota `mail()` de l'offre de l'hébergement (M5b) |
| Alertes internes | Vers `ALERT_EMAIL` (adresse perso, dans `.env`) |
| Commande annulée ou remboursée en totalité | **QR invalidé** (modifie le §11 du plan v2). Remboursement partiel : QR toujours valide |
| Scan d'un QR invalidé | Page d'avertissement « ne pas remettre », avec lien vers la commande, scan journalisé |
| Conservation | Commandes et scans purgés après 12 mois ; `mail_log` après 30 jours |
| Autres choix du §11 | Validés : pas de nouvel envoi après modification, un QR par panier mixte, 25 lignes examinées, pas de renvoi manuel en v1, français seul, une seule boutique |

## Écarts par rapport au plan v2

Ces changements découlent des décisions ci-dessus ; ils sont détaillés dans les jalons concernés.

1. **Abonnement Events** : déclencheurs `order.displayFinancialStatus` **et** `order.cancellation`, **sans `query_filter`**. L'API décide selon l'état renvoyé (payée, annulée, remboursée). Requête enrichie de `statusPageUrl` et `cancelledAt` (M0, M3).
2. **Table `pickup_orders`** : statut `error` ajouté ; nouvelles colonnes `error_code`, `status_page_url`, `revoked_at`, `revoked_reason` (M1).
3. **Table `scans`** : colonne `result` (`redirected` ou `revoked`) (M1, M4).
4. **Éligibilité** : une commande `PARTIALLY_REFUNDED` non annulée est traitée comme payée, au cas où la première livraison reçue arrive après un remboursement partiel (M3).
5. **Dates** : toutes en UTC (`SET time_zone = '+00:00'` à la connexion), pour que le calcul du bucket ne dépende pas du fuseau du serveur (M1).
6. **Recette** : commandes provisoires (brouillons) créées dans l'admin et marquées payées : ni paiement réel ni passerelle de test à activer sur la boutique de production (M2, M8).
7. **Insertion idempotente** : `INSERT … ON DUPLICATE KEY UPDATE id = id` au lieu d'`INSERT IGNORE`, qui avalerait aussi les vraies erreurs (M3).
8. **Sonde** : remplacée par la réception Events en M3 ; la copie brute des livraisons reste disponible derrière `DEBUG_DELIVERIES=1` (purge en M7).
9. **Envoi des mails** : par la fonction `mail()` de l'hébergement au lieu du SMTP d'OVH (M5b), après le blocage SMTP de l'hébergement le 16/11.

## Points encore ouverts

- ~~**Envoi SMTP depuis l'hébergement OVH bloqué**~~ (16/11) : contourné le soir même par la fonction `mail()` de l'hébergement, expéditeur aligné sur le domaine de l'hébergement ([M5b](roadmap/M5b-envoi-hebergement.md)). Gmail : boîte de réception ; **Outlook : indésirables** (atténuations dans M5b).
- **Volume** : environ 3 000 mails par mois (16/11). Le plafond horaire (`MAIL_MAX_PER_HOUR`, sous le quota `mail()` de l'offre) étale les pics ; les commandes en attente partent avec le cron (M7).

- ~~E-mail absent sur 6 commandes de test sur 9~~ : expliqué le 14/11, ces commandes n'avaient pas de client rattaché (cas `no_email` prévu). Go confirmé.
- **Paiement différé** (commande B de M2 : en attente puis payée) : pas encore testé. Non bloquant, la table de décision ignore `PENDING` et traite le passage à `PAID`.
- ~~Livraisons sur annulation et remboursement~~ : **confirmées le 14/11** (`order.cancellation` et `displayFinancialStatus` → `REFUNDED` / `PARTIALLY_REFUNDED`). Le repli de M3 n'est pas nécessaire.
- **Nouveaux essais d'Events** : on suppose les mêmes que pour les webhooks classiques (5 s, 8 essais sur 4 h). Délai de livraison observé : moins d'une seconde (commande #1094).
- **Produit réel tagué** : `LightStick#2027` porte `retrait-qr` et est en vente, sans être affiché sur la boutique. Une commande réelle avant la mise en service créerait une ligne `pending`. Depuis M5, `MAIL_ALLOWLIST` la retient (`pending` / `not_allowlisted`) jusqu'à la mise en service, où le cron lui enverra le mail (M8).
- ~~Forfait Shopify Basic, risque de no-go~~ : levé le 14/11, l'e-mail client arrive bien (commande A, M2).

## Hors périmètre v1

Renvoi manuel du mail, mail multilingue, plusieurs boutiques, nouvel envoi après modification de commande, filtrage du tag côté Shopify (`query_filter`), CI de déploiement : voir [M9](roadmap/M9-apres-lancement.md).
