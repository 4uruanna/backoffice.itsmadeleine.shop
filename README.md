# backoffice.itsmadeleine.shop

API du QR code de retrait en boutique : réception des Events Shopify, envoi du mail avec QR code, route de scan. Slim 4, PHP 8.5, MySQL.

- Feuille de route : [`ROADMAP.md`](ROADMAP.md)
- Déploiement OVH : [`docs/deploiement.md`](docs/deploiement.md)
- App Shopify (CLI, abonnement Events) : [`shopify/`](shopify/shopify.app.toml)

## Développement local

Prérequis : Docker.

```bash
cp .env.example .env
```

```bash
docker compose up -d --build
```

```bash
docker compose run --rm php composer install
```

- API : http://localhost:8080/health
- Mailpit (mails envoyés en local) : http://localhost:8025
- MySQL : le schéma de `migrations/` est appliqué à la création du volume. Pour repartir de zéro : `docker compose down -v`.

Tests :

```bash
docker compose run --rm php vendor/bin/phpunit
```

Livraisons reçues par la sonde :

```bash
docker compose exec php ls var/log/deliveries
```
