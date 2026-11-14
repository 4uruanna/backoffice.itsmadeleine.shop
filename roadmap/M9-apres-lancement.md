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

## Améliorations (priorité moyenne)

- [ ] Filtrer côté Shopify : déplacer le contrôle du tag (et des statuts utiles) dans `query_filter`, pour ne recevoir que les commandes concernées.
- [ ] **Renvoi manuel** du mail (petite page protégée ou script en ligne de commande) : client qui a supprimé le mail.
- [ ] Reprise automatique des `error` / `no_email` si une livraison ultérieure apporte un e-mail.
- [ ] Page de scan intermédiaire « déjà scanné le … » si le staff le juge utile.
- [ ] Service transactionnel par API HTTPS si la délivrabilité OVH se révèle mauvaise.

## Hors périmètre, à rediscuter

- Mail multilingue.
- Plusieurs boutiques.
- Nouvel envoi après modification d'une commande payée.
- Commandes de plus de 25 lignes (examen de toutes les lignes).

## Critères de fin

Jalon ouvert : chaque élément est fermé individuellement.
