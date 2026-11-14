# Annexe — Plan B : QR dans la notification Shopify

> **Non utilisé** : go plan A prononcé le 14/11 (M2), l'e-mail client arrive bien dans la charge utile. Conservé pour mémoire.

**Quand** : seulement si M2 conclut au no-go (e-mail client inaccessible), dimanche 15/11 après-midi.
**Remplace** : M3, M5 et M6 ; M4 et M7 sont allégés.

## Principe

Shopify envoie lui-même le QR, dans sa notification de confirmation de commande (ou « prêt pour le retrait »). Le gabarit Liquid insère une image servie par l'API, seulement si une ligne porte le tag. L'API ne connaît plus l'e-mail du client : plus de SMTP vers les clients, plus de bucket.

## Tâches

- [ ] Vérifier dans l'éditeur de notifications les objets et filtres disponibles : `line_items`, `line.product.tags`, `order.id`, filtre `hmac_sha256`. **À tester avant tout le reste** (aperçu de la notification avec une commande provisoire).
- [ ] Bloc Liquid, affiché seulement si un produit porte `retrait-qr` :
  `<img src="https://app.itsmadeleine.shop/qr/{{ order.id }}-{{ order.id | hmac_sha256: 'SECRET' }}.png" alt="QR code de retrait">`.
  Le secret est visible des comptes staff qui ont accès aux notifications : en utiliser un dédié, différent du secret client de l'app.
- [ ] Route `GET /qr/{id}-{sig}.png` : vérifie la signature, génère le PNG pointant vers `/r/{id}-{sig}`, en-têtes de cache longs.
- [ ] Route `GET /r/{id}-{sig}` : vérifie la signature, journalise le scan, redirige ou affiche la page d'avertissement (M4). Les tables passent de `token` à `shopify_order_id`.
- [ ] Révocation : l'abonnement Events reste possible **sans** le champ `email` (requête réduite à `legacyResourceId`, `displayFinancialStatus`, `cancelledAt`), pour remplir `revoked_at`.
- [ ] Cron réduit à la purge.

## Limites

- Le QR dépend du chargement des images distantes par le client mail (bloquées par défaut chez certains).
- Le gabarit de notification est commun : une erreur de Liquid touche toutes les confirmations de commande. Tester l'aperçu avec et sans produit tagué.
- Le lien de statut de commande est déjà présent dans la notification Shopify.

## Critères de fin

Mêmes scénarios que M8, à l'exception de ceux qui portent sur le SMTP, le bucket ou `no_email`.
