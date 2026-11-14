# Déploiement sur OVH (FTP/SFTP manuel)

Hébergement OVH Perso, PHP 8.5, multisite `backoffice.itsmadeleine.shop` → dossier racine `backoffice/public`.

```
~/backoffice/            ← contenu de build/backoffice/ + .env
├── .env                 ← créé à la main sur le serveur, jamais versionné
├── migrations/
├── public/              ← racine web (index.php, .htaccess, .ovhconfig, probe.php)
├── src/
├── var/log/deliveries/  ← créé par l'app (livraisons de la sonde, données personnelles)
└── vendor/
```

## 1. Préparer la release (sur ton poste)

Commiter d'abord : le script prend le code du commit courant.

```bash
scripts/build-release.sh
```

Le dossier `build/backoffice/` contient le code et `vendor/` sans les dépendances de dev, installées dans l'image Docker (PHP 8.5).

## 2. Envoyer les fichiers

Avec FileZilla (ou un autre client SFTP), envoyer **le contenu** de `build/backoffice/` dans `~/backoffice/` sur l'hébergement, en écrasant les fichiers existants. Ne pas toucher à `.env` ni à `var/` sur le serveur.

Fichiers cachés : activer leur affichage dans le client FTP pour que `public/.htaccess` et `public/.ovhconfig` partent bien.

## 3. Premier déploiement uniquement

1. **`.env`** : copier `.env.example` vers `~/backoffice/.env` et remplir les valeurs de production :
   `APP_ENV=production`, `APP_URL=https://backoffice.itsmadeleine.shop`, `DB_*` (base OVH), `SHOPIFY_CLIENT_SECRET` (app `itsmadeleine.app`), `SMTP_HOST=ssl0.ovh.net`, `SMTP_PORT=465`, `SMTP_ENCRYPTION=smtps`, `SMTP_USER=commandes@itsmadeleine.shop`, `SMTP_PASSWORD`, `ALERT_EMAIL`.
2. **Base** : dans phpMyAdmin (espace client OVH → Bases de données), exécuter `migrations/001_init.sql`.
3. **Version de PHP** : `public/.ovhconfig` demande PHP 8.5. OVH lit ce fichier à la racine du multisite (`backoffice/public`) ; si `probe.php` affiche une autre version, copier aussi `.ovhconfig` à la racine de l'hébergement ou régler la version dans l'espace client (Multisite → modifier).

## 4. Vérifications

```bash
curl -s https://backoffice.itsmadeleine.shop/health
```

Attendu : `{"status":"ok"}` (sinon `503` : identifiants de base dans `.env`).

```bash
curl -s https://backoffice.itsmadeleine.shop/probe.php
```

Noter `PHP_VERSION`, `fastcgi_finish_request` et `gd` dans `roadmap/M2-go-no-go.md`.

Fichiers sensibles inaccessibles (attendu : `403` ou `404`) :

```bash
for p in /.env /../.env /.ovhconfig /composer.json; do echo "$p $(curl -s -o /dev/null -w '%{http_code}' --path-as-is https://backoffice.itsmadeleine.shop$p)"; done
```

Sonde : un POST non signé doit être refusé (`401`) :

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://backoffice.itsmadeleine.shop/webhooks/shopify/order-paid -d '{}'
```

Un POST signé avec le vrai secret doit être accepté (`200`). Le secret est lu dans une variable pour ne pas rester dans l'historique du shell :

```bash
read -rs SECRET && B='{"test":true}' && SIG=$(printf '%s' "$B" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64) && curl -s -o /dev/null -w '%{http_code}\n' -X POST -H "Shopify-Hmac-Sha256: $SIG" -H 'Shopify-Webhook-Id: test-manuel' --data-binary "$B" https://backoffice.itsmadeleine.shop/webhooks/shopify/order-paid; unset SECRET
```

Le fichier `var/log/deliveries/<date>-test-manuel.json` apparaît alors sur le serveur (à supprimer ensuite).

## Après M2

Supprimer `public/probe.php` du dépôt **et** du serveur (l'envoi FTP n'efface pas les fichiers supprimés).
