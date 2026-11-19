# M9 — Après lancement

**Quand** : à partir du 19/11.
**Dépend de** : M8.

## Objectif

Consolider ce qui a été sacrifié au délai, puis améliorer le service à partir de l'usage réel. Les éléments ne sont pas ordonnés strictement : à prioriser après une à deux semaines d'utilisation.

## Consolidation (priorité haute)

- [ ] **Bilan à J+7** : volume de commandes taguées, délai moyen d'envoi, saturations du bucket, erreurs, mails en spam signalés, nombre de scans révoqués.
- [ ] **Supervision externe** de `/health` (service de surveillance gratuit, alerte par mail).
- [ ] **Déploiement automatisé** : GitHub Actions sur `main` (`composer install --no-dev`, tests, envoi FTP/SFTP), identifiants FTP en secrets GitHub.
- [ ] **CI des tests** sur chaque push (PHPUnit + MySQL en service).
- [ ] Relire les journaux pour vérifier le délai et les nouveaux essais d'Events, et documenter les valeurs observées.

## Incident du 19/11 : lignes `error` / `payload_errors` « data.order inutilisable »

- **Constat** : deux lignes ajoutées après la mise en service, `order_name` = `#<shopify_order_id>`, aucune commande Shopify manquante en base.
- **Cause probable** : une livraison `Order` dont la requête s'exécute alors que la commande n'existe plus. `order(id:)` renvoie alors `null` **sans** `errors`. L'app n'ayant pas de nom à enregistrer, elle crée une ligne avec `#<id>`. Cas typique : commandes de test annulées puis supprimées dans Shopify, après suppression de leurs lignes en base lors du nettoyage de mise en service. Aucun client à servir.
- **Vérification** : ouvrir `https://admin.shopify.com/store/<handle>/orders/<shopify_order_id>` (introuvable = supprimée) ; requête 8 de `docs/requetes-suivi.sql` (commandes voisines par identifiant, chronologique chez Shopify). Puis requête 9 (suppression) ou, si la commande existe, requête 10 (reprise manuelle).
- [x] Code distinct `order_not_found` (au lieu de `payload_errors`), alerte qui dit quoi faire, reprise par une livraison valide ultérieure.
- [x] Copie brute des livraisons sans commande exploitable dans `var/log/deliveries-anomalies/`, même avec `DEBUG_DELIVERIES=0` (purgée après 30 jours) : la prochaine fois, la cause se lira dans le fichier.
- [ ] Après déploiement : vérifier que le dossier `var/log/deliveries-anomalies/` est bien créé à la première anomalie (droits d'écriture sur `var/log/`).

## Améliorations (priorité moyenne)

- [ ] Filtrer côté Shopify : déplacer le contrôle du tag (et des statuts utiles) dans `query_filter`, pour ne recevoir que les commandes concernées.
- [ ] **Renvoi manuel** du mail (petite page protégée ou script en ligne de commande) : client qui a supprimé le mail.
- [ ] Reprise automatique des `error` / `no_email` si une livraison ultérieure apporte un e-mail.
- [ ] Page de scan intermédiaire « déjà scanné le … » si le staff le juge utile.
- [ ] Service transactionnel par API HTTPS si la délivrabilité de `mail()` se révèle mauvaise (mails en indésirables). Non prévu à ce stade ([M5b](M5b-envoi-hebergement.md)).
- [ ] Selon la réponse éventuelle du support OVH : revenir au SMTP d'OVH (`MAIL_TRANSPORT=smtp`) ou garder `mail()`.

## Hors périmètre, à rediscuter

- Mail multilingue.
- Plusieurs boutiques.
- Nouvel envoi après modification d'une commande payée.
- Commandes de plus de 25 lignes (examen de toutes les lignes).

## Critères de fin

Jalon ouvert : chaque élément est fermé individuellement.

- [ ] Refus différés (17/11, M8 scénario 6) : un mail accepté par `mail()` puis refusé par le relais d'OVH ou la boîte du client reste `sent`. Pistes, dans l'ordre :
  1. **API OVH** : relever par le cron les avis de non-remise des Scripts e-mails de l'hébergement (endpoint à vérifier, du type `/hosting/web/{service}/email/bounces`), passer la commande concernée en `error` (`bounced`) et l'inclure dans l'alerte. Surveiller aussi l'état du service (OVH peut bloquer `mail()` si les rebonds se multiplient).
  2. **Service transactionnel** (Mailjet, Brevo…) avec webhooks de remise et de rebond : vraie confirmation de remise au serveur du destinataire, et sans doute une meilleure délivrabilité sur Outlook. Écarté en M5b faute de besoin ; à reconsidérer selon les rebonds observés.
  Dans tous les cas, ni la boîte de réception ni le dossier des indésirables ne sont observables : la preuve de réception reste le scan en boutique.
