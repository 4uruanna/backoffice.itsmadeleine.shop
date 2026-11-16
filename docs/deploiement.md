# Déploiement sur OVH (FTP/SFTP manuel)

Hébergement OVH Perso, PHP 8.5, multisite `app.itsmadeleine.shop` → dossier racine `backoffice/public`.

```
~/backoffice/            ← contenu de build/backoffice/ + .env
├── .env                 ← créé à la main sur le serveur, jamais versionné
├── migrations/
├── public/              ← racine web (index.php, .htaccess, .ovhconfig)
├── src/
├── templates/           ← pages de scan (M4), mail (M5)
├── var/log/deliveries/  ← créé par l'app (livraisons de la sonde, données personnelles)
└── vendor/
```

## 1. Préparer la release (sur ton poste)

Commiter d'abord : le script prend le code du commit courant.

```bash
scripts/build-release.sh
```

Le dossier `build/backoffice/` contient le code et `vendor/` sans les dépendances de dev, installées dans l'image Docker du projet (PHP 8.5). Docker seul suffit, Docker Compose n'est pas nécessaire.

Sans Docker, avec Composer installé sur le poste :

```bash
USE_HOST_COMPOSER=1 scripts/build-release.sh
```

## 2. Envoyer les fichiers

Avec FileZilla (ou un autre client SFTP), envoyer **le contenu** de `build/backoffice/` dans `~/backoffice/` sur l'hébergement, en écrasant les fichiers existants. Ne pas toucher à `.env` ni à `var/` sur le serveur.

Fichiers cachés : activer leur affichage dans le client FTP pour que `public/.htaccess` et `public/.ovhconfig` partent bien.

## 3. Premier déploiement uniquement

1. **`.env`** : copier `.env.example` vers `~/backoffice/.env` et remplir les valeurs de production :
   `APP_ENV=production`, `APP_URL=https://app.itsmadeleine.shop`, `DB_*` (base OVH), `SHOPIFY_CLIENT_SECRET` (app `itsmadeleine.app`), `SMTP_HOST=ssl0.ovh.net`, `SMTP_PORT=465`, `SMTP_ENCRYPTION=smtps`, `SMTP_USER=commandes@itsmadeleine.shop`, `SMTP_PASSWORD`, `MAIL_FROM=commandes@itsmadeleine.shop`, `MAIL_FROM_NAME="It's Madeleine"`, `ALERT_EMAIL`, `MAIL_ALLOWLIST` (ton adresse jusqu'à la mise en service, voir M5 et M8).
2. **Base** : dans phpMyAdmin (espace client OVH → Bases de données), exécuter `migrations/001_init.sql`.
3. **Version de PHP** : `public/.ovhconfig` demande PHP 8.5. OVH lit ce fichier à la racine du multisite (`backoffice/public`) ; si la version de PHP n'est pas la bonne, copier aussi `.ovhconfig` à la racine de l'hébergement ou régler la version dans l'espace client (Multisite → modifier).

## 4. Vérifications

```bash
curl -s https://app.itsmadeleine.shop/health
```

Attendu : `{"status":"ok","version":"<commit>","mail_transport":"<transport>"}`, avec le commit affiché à la fin de `scripts/build-release.sh` et le transport du `.env` du serveur (`phpmail` depuis le 16/11). Sinon `503` : identifiants de base dans `.env`. Une version `dev` ou ancienne : la release envoyée n'est pas la bonne.

Fichiers sensibles inaccessibles (attendu : `403` ou `404`) :

```bash
for p in /.env /../.env /.ovhconfig /composer.json; do echo "$p $(curl -s -o /dev/null -w '%{http_code}' --path-as-is https://app.itsmadeleine.shop$p)"; done
```

Sonde : un POST non signé doit être refusé (`401`) :

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://app.itsmadeleine.shop/webhooks/shopify/order-paid -d '{}'
```

Un POST signé avec le vrai secret doit être accepté (`200`). Le secret est lu dans une variable pour ne pas rester dans l'historique du shell :

```bash
read -rs SECRET && B='{"test":true}' && SIG=$(printf '%s' "$B" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64) && curl -s -o /dev/null -w '%{http_code}\n' -X POST -H "Shopify-Hmac-Sha256: $SIG" -H 'Shopify-Webhook-Id: test-manuel' --data-binary "$B" https://app.itsmadeleine.shop/webhooks/shopify/order-paid; unset SECRET
```

Avec `DEBUG_DELIVERIES=1`, le fichier `var/log/deliveries/<date>-test-manuel.json` apparaît alors sur le serveur (à supprimer ensuite). Le journal d'erreurs PHP d'OVH contient aussi une ligne `events: …` par livraison (décision prise, sans donnée personnelle).

## Mise à jour du `.env` sur le serveur

Clés ajoutées depuis le premier déploiement :

- `DEBUG_DELIVERIES=1` (M3) : copie brute de chaque livraison dans `var/log/deliveries/`. À laisser à `1` jusqu'à la fin des tests de M2 (commandes B à E), puis `0`.
- `SHOPIFY_STORE_HANDLE` : identifiant de la boutique dans l'URL de l'admin (M4).

## Fichiers retirés du dépôt

L'envoi FTP n'efface pas les fichiers supprimés du dépôt. À supprimer à la main sur le serveur :

- `public/probe.php` (retiré le 14/11, après M2) ;
- `src/Controller/DeliveryProbeController.php` (remplacé en M3).
