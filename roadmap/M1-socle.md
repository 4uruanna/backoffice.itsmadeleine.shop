# M1 — Socle de l'API et sonde de livraison

**Quand** : samedi 14/11, après M0.
**Dépend de** : M0 (hébergement, base, version de PHP).
**Débloque** : M2 (la sonde reçoit les livraisons de test).

## Objectif

Un squelette Slim déployé sur OVH, avec base, configuration et `/health`. Plus une **sonde** : la route de réception vérifie le HMAC et écrit la charge utile brute dans un fichier, pour mener les tests de M2 avant d'avoir écrit la logique métier.

## Arborescence

```
public/            index.php, .htaccess (racine web)
src/               Controller/, Service/, Repository/, Config.php
cron/              retry.php
migrations/        001_init.sql
templates/         mail/, scan/
shopify/           shopify.app.toml
tests/             PHPUnit (+ fixtures/)
docs/              requetes-suivi.sql
var/log/           journaux (hors racine web, non versionné)
docker-compose.yml, Dockerfile, composer.json, .env.example, .ovhconfig
```

## Tâches

- [x] `composer.json` : `slim/slim` 4, `slim/psr7`, `vlucas/phpdotenv`, `phpmailer/phpmailer`, `chillerlan/php-qrcode` ; en dev : `phpunit/phpunit`. Contrainte `config.platform.php` = version OVH (8.5.0). Versions résolues : Slim 4.15, slim/psr7 1.8, phpdotenv 5.8, PHPMailer 7.2, php-qrcode 6.0, PHPUnit 13.4.
- [x] `Dockerfile` (`php:8.5-apache`, extensions `pdo_mysql`, `gd`, `zip`, racine web `public/`, `var/` dans un volume nommé) + `docker-compose.yml` : `php` (port 8080), `mysql:8.4` (schéma appliqué à la création du volume), `mailpit` (port 8025).
- [x] `.env.example` avec toutes les clés, sans valeur secrète :
  `APP_URL`, `DB_*`, `SHOPIFY_CLIENT_SECRET`, `SHOPIFY_STORE_HANDLE`, `PICKUP_TAG=retrait-qr`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD`, `MAIL_FROM`, `MAIL_FROM_NAME`, `ALERT_EMAIL`, `MAIL_MAX_PER_HOUR=120`, `MAX_ATTEMPTS=3`, `RETENTION_MONTHS=12`, `MAIL_LOG_RETENTION_DAYS=30`.
  Valeurs SMTP connues (M0) : `SMTP_HOST=ssl0.ovh.net`, `SMTP_PORT=465`, `SMTP_USER=commandes@itsmadeleine.shop`, `MAIL_FROM=commandes@itsmadeleine.shop`. Le port 465 est en TLS implicite : côté PHPMailer, `SMTPSecure = PHPMailer::ENCRYPTION_SMTPS` (et non `STARTTLS`). Ajouter `SMTP_ENCRYPTION` (`smtps` en production, vide en local pour Mailpit, port 1025 sans chiffrement).
- [x] `.gitignore` : `.env`, `vendor/`, `var/` (fait en M0).
- [x] Connexion PDO (exceptions activées, `utf8mb4`, `SET time_zone = '+00:00'`).
- [x] `migrations/001_init.sql` (schéma ci-dessous, InnoDB, utf8mb4), lancé en local. **Reste : phpMyAdmin sur OVH.**
- [x] `public/.htaccess` : tout vers `index.php`, fichiers cachés refusés ; `public/.ovhconfig` (racine du multisite) : PHP 8.5, `environment=production`.
- [x] `GET /health` : `SELECT 1` → `200 {"status":"ok"}`, sinon `503`.
- [x] Vérification HMAC (service partagé avec M3, `src/Service/ShopifyHmac.php`) : `base64_encode(hash_hmac('sha256', $rawBody, $secret, true))` comparé à `Shopify-Hmac-Sha256` par `hash_equals`. Signature invalide → `401`. Les deux noms d'en-tête sont acceptés (`Shopify-Hmac-Sha256` et `X-Shopify-Hmac-Sha256`), en attendant de voir lequel les Events envoient (M2).
- [x] **Sonde** : `POST /webhooks/shopify/order-paid` → HMAC, puis écriture du corps brut (à l'octet près) et des en-têtes `Shopify-*` / `X-Shopify-*` dans `var/log/deliveries/<date>-<Shopify-Webhook-Id>.json`, puis `200`. Secret absent → `500` (Shopify réessaiera). Retirée en M3.
- [x] Page de test `public/probe.php` (supprimée après M2) : affiche `function_exists('fastcgi_finish_request')`, `extension_loaded('gd')` et `PHP_VERSION`.
- [x] PHPUnit : test du HMAC (signature valide, invalide, en-tête absent), de `/health` (200, 503) et de la sonde (signée, non signée, mauvaise signature, secret absent). 11 tests, verts sous PHP 8.5.12.
- [x] Premier déploiement (fichiers et `.env` envoyés le 14/11, vérifications ci-dessous à faire) : `scripts/build-release.sh` prépare `build/backoffice/` (code du commit + `vendor/` sans dev), à envoyer par FTP dans `~/backoffice/` ; `.env` créé sur le serveur. Pas à pas et vérifications : [`docs/deploiement.md`](../docs/deploiement.md).

## Schéma

```sql
CREATE TABLE pickup_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shopify_order_id BIGINT UNSIGNED NOT NULL UNIQUE,
  order_name VARCHAR(32) NOT NULL,
  customer_email VARCHAR(255) NULL,
  status_page_url VARCHAR(500) NULL,
  token CHAR(32) NULL UNIQUE,
  status ENUM('pending','processing','ignored','sent','failed','error') NOT NULL DEFAULT 'pending',
  pending_reason VARCHAR(32) NULL,          -- ex. rate_limited
  error_code VARCHAR(32) NULL,              -- payload_errors, no_email, max_attempts
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  webhook_id VARCHAR(64) NULL,
  revoked_at DATETIME NULL,
  revoked_reason ENUM('cancelled','refunded') NULL,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  KEY idx_status (status, created_at)
);

CREATE TABLE scans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pickup_order_id INT UNSIGNED NOT NULL,
  result ENUM('redirected','revoked') NOT NULL,
  scanned_at DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  FOREIGN KEY (pickup_order_id) REFERENCES pickup_orders(id) ON DELETE CASCADE
);

CREATE TABLE mail_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pickup_order_id INT UNSIGNED NULL,
  kind ENUM('qr','alert') NOT NULL,
  sent_at DATETIME NOT NULL,
  KEY idx_sent_at (sent_at)
);
```

Par rapport au plan v2 : statut `error`, colonnes `status_page_url`, `error_code`, `revoked_at`, `revoked_reason`, `updated_at`, `scans.result`, `ON DELETE CASCADE` pour la purge.

## Critères de fin

- [x] `docker compose up` puis `curl localhost:8080/health` → `200` (vérifié le 14/11).
- [x] `https://app.itsmadeleine.shop/health` → `200` sur OVH (14/11).
- [x] En local puis sur OVH (14/11, vrai secret) : un `POST` signé à la main est accepté (`200`) et journalisé ; non signé → `401`.
- [x] `probe.php` a donné ses résultats ; ils sont notés dans M2.
- [x] `.env` absent du dépôt, présent sur le serveur, hors racine web (`/.env` → refusé).

**M1 terminé le 14/11.**

## Risques

- Écart de version PHP entre Docker et OVH → toujours lancer `composer install` dans le conteneur, avec `config.platform.php`.
- Racine web mal réglée → `.env` lisible publiquement. Vérifier que `https://app.itsmadeleine.shop/../.env` et `/.env` renvoient bien `404`.
