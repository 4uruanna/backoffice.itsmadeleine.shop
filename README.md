# backoffice.itsmadeleine.shop

API du QR code de retrait en boutique, en ligne sur `https://app.itsmadeleine.shop` : réception des Events Shopify, envoi du mail avec QR code, route de scan. Slim 4, PHP 8.5, MySQL.

- Feuille de route : [`ROADMAP.md`](ROADMAP.md)
- Déploiement OVH : [`docs/deploiement.md`](docs/deploiement.md)
- App Shopify (CLI, abonnement Events) : [`shopify/`](shopify/shopify.app.toml)

## Développement local

Prérequis : Docker avec le plugin Compose v2 (`docker compose version` doit répondre). Sans lui, `docker compose …` échoue avec `unknown flag`. Sur Linux : paquet `docker-compose-plugin`.

Sur Mac avec Homebrew, la formule `docker` n'est que le client : il faut une VM, par exemple Colima.

```bash
brew install colima docker-compose docker-buildx
```

Ajouter `"cliPluginsExtraDirs": ["/opt/homebrew/lib/docker/cli-plugins"]` dans `~/.docker/config.json`, puis démarrer la VM (après chaque redémarrage du Mac) :

```bash
colima start
```

Sans Docker, la release peut aussi se préparer avec Composer installé sur le poste (`brew install composer`) : `USE_HOST_COMPOSER=1 scripts/build-release.sh`.

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

Tests (unitaires, et MySQL sur une base jetable `backoffice_test`) :

```bash
docker compose run --rm php vendor/bin/phpunit
```

Livraisons reçues par la sonde :

```bash
docker compose exec php ls var/log/deliveries
```
