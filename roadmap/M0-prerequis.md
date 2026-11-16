# M0 — Prérequis Shopify, OVH et mail

**Quand** : samedi 14/11, en premier (le DNS doit commencer à se propager tôt).
**Dépend de** : rien.
**Débloque** : M1 et M2.

> **État au 16/11** : ✅ terminé. Mail de test reçu dans Gmail (onglet Principal) avec SPF, DKIM et DMARC à `PASS`.

## Objectif

Tout ce qui ne demande pas de code et qui prend du temps calendaire : forfait, app Shopify, abonnement Events, boîte mail et DNS, vérifications de l'hébergement.

## Tâches

### Shopify

- [x] Lire le forfait de la boutique (admin → Paramètres → Forfait) et le noter dans `ROADMAP.md` : **Basic**. Préparer dès maintenant l'[annexe plan B](annexe-plan-b.md), et avancer le test de la commande A (M2) au 14/11 au soir.
- [x] Créer l'app dans le Dev Dashboard **de l'organisation du client** (le développeur y a été ajouté comme membre), non intégrée à l'admin (`embedded = false`) : `itsmadeleine.app`. La première app (`itsmadeleine.love`, organisation du développeur) ne pouvait pas s'installer : supprimée.
- [x] Déclarer l'accès aux données client protégées pour le champ **e-mail** (niveau 2), si le Dev Dashboard le demande : aucune section de ce type dans le Dev Dashboard de `itsmadeleine.app` (14/11). Seule une vraie livraison dira si l'e-mail est accessible (M2, commande A).
- [x] Shopify CLI ajoutée au dépôt : `@shopify/cli` 5.0.1 en dépendance de dev dans `shopify/package.json`, gérée avec **pnpm 12.18.0** (champ `packageManager`, via Corepack ; Node ≥ 22.12). Installer avec `cd shopify && pnpm install --frozen-lockfile`, puis l'appeler par `pnpm exec shopify` (les Events demandent au moins la 3.92). Le script d'installation d'`esbuild` est autorisé dans `pnpm-workspace.yaml`. Ne pas taper `pnpm deploy` (commande intégrée de pnpm) : utiliser `pnpm app:deploy`.
- [x] Relier le dossier `shopify/` à l'app (`shopify app config link`). Le link crée un fichier `shopify.app.<nom>.toml` **sans `[events]`** : on n'en garde que le `client_id`, recopié dans `shopify.app.toml`, seul fichier versionné.
- [x] Écrire `shopify/shopify.app.toml` : `client_id`, `application_url = "https://app.itsmadeleine.shop"`, scopes `read_orders,read_products`, et l'abonnement ci-dessous.
- [x] `pnpm app:deploy --config shopify.app.toml` sur la nouvelle app (l'abonnement validé sur l'ancienne, version `itsmadeleine-love-2`, n'a pas été repris par le link). Vérifier ensuite dans le Dev Dashboard que la version active contient l'abonnement `commande-qr-retrait`.
- [x] Installer l'app sur la boutique de production (si ce n'est pas déjà fait), puis vérifier qu'elle apparaît dans Paramètres → Applications et canaux de vente.
- [x] Noter le **secret client** de l'app `itsmadeleine.app` (servira pour le HMAC, dans `.env`) : Dev Dashboard → app → Paramètres (identifiants), ou `pnpm shopify app env show` depuis `shopify/` (`SHOPIFY_API_SECRET`).

Abonnement (remplace celui du §5 du plan v2) :

```toml
[events]
api_version = "2026-10"

[[events.subscription]]
handle = "commande-qr-retrait"
topic = "Order"
actions = ["create", "update"]
triggers = ["order.displayFinancialStatus", "order.cancellation"]
uri = "https://app.itsmadeleine.shop/webhooks/shopify/order-paid"
query = """
query commande_qr($orderId: ID!) {
  order(id: $orderId) {
    legacyResourceId
    name
    email
    statusPageUrl
    displayFinancialStatus
    cancelledAt
    lineItems(first: 25) {
      nodes { product { tags } }
    }
  }
}
"""
```

- Pas de `query_filter` : l'API reçoit les passages à « payée », les annulations et les remboursements, et décide elle-même (M3).
- **Déclencheurs** (corrigé le 14/11 après le premier deploy) : `order.cancelledAt` n'existe pas, l'annulation se déclenche par `order.cancellation`. `order.displayFinancialStatus` est accepté et couvre le passage à payée ainsi que les remboursements (`REFUNDED`, `PARTIALLY_REFUNDED`) : pas besoin de `order.totalRefundedSet.*`. Le champ `cancelledAt` reste lu dans la requête. Dernier recours si une livraison manque en M2 : `triggers = ["order.*"]` (plus de volume, l'API trie).
- Si `shopify app deploy` refuse la complexité de la requête (limite de 100 points) ou la variable `$orderId` pour un déclencheur, noter le message exact. Pour la variable, Shopify demande de scinder en deux abonnements `Order` (un par groupe de variables disponibles).

### Mail (OVH MX Plan)

- [x] Créer la boîte `commandes@itsmadeleine.shop` dans l'espace client et noter son mot de passe (`.env` uniquement). SMTP : `ssl0.ovh.net`, port 465, SSL/TLS implicite.
- [x] Vérifier l'enregistrement SPF : un seul TXT `v=spf1 include:mx.ovh.com ~all` (fusionner s'il en existe déjà un).
- [x] Activer le DKIM dans l'espace client MX Plan (14/11, propagation en cours).
- [x] Ajouter un DMARC en surveillance : `_dmarc` TXT `v=DMARC1; p=none; rua=mailto:<adresse de rapport>` (14/11). Adresse de rapport du domaine conseillée (redirection `dmarc@` vers une boîte perso).
- [x] Envoyer un mail de test vers une adresse Gmail et lire l'en-tête : `spf=pass`, `dkim=pass` (16/11, envoyé par `scripts/test-smtp.php` via `ssl0.ovh.net:465`, le webmail et l'envoi depuis Mail sur macOS n'ayant pas marché).

### Hébergement OVH Perso

- [x] Créer le sous-domaine `app.itsmadeleine.shop` (multisite), dossier racine `backoffice/public`, SSL activé (domaine changé le 14/11, `backoffice.` abandonné).
- [x] Choisir la version de PHP disponible la plus récente et la noter : **PHP 8.5**. : Docker (M1) et `.ovhconfig` utiliseront la même.
- [x] Vérifier que l'extension **GD** est présente : oui (vu par `probe.php`).
- [x] Créer la base MySQL et noter hôte, nom, utilisateur et mot de passe.
- [x] Préparer l'accès FTP/SFTP (identifiants, client FTP).

## Vérifications depuis ton poste

À lancer depuis un terminal local (le conteneur de développement n'a pas accès au domaine).

SPF, DMARC, DKIM :

```bash
dig +short TXT itsmadeleine.shop
```

```bash
dig +short TXT _dmarc.itsmadeleine.shop
```

```bash
dig +short CNAME ovhmo1._domainkey.itsmadeleine.shop
```

(le sélecteur DKIM exact est affiché dans l'espace client MX Plan une fois le DKIM activé)

Sous-domaine et HTTPS :

```bash
curl -sI https://app.itsmadeleine.shop/
```

Abonnement et version de la CLI :

```bash
cd shopify && pnpm install --frozen-lockfile && pnpm exec shopify version
```

```bash
cd shopify && pnpm app:link && pnpm app:deploy
```

## Résultats

À remplir au fil de la journée (pas de secret ici : mots de passe et secret client vont uniquement dans `.env`).

| Élément | Valeur | Date |
|---|---|---|
| Forfait Shopify | **Basic** | 14/11 |
| Version Shopify CLI | | |
| `client_id` de l'app | `a977545e9444eb4c8d30c169b1ed4c6d` (`itsmadeleine.app`, organisation du client) | 14/11 |
| Déclencheurs acceptés au deploy | `order.displayFinancialStatus` et `order.cancellation`, un seul abonnement avec `$orderId` (validé sur l'ancienne app, version `itsmadeleine-love-2`) ; redéployé sur `itsmadeleine.app` ; URI de livraison passée à `https://app.itsmadeleine.shop/webhooks/shopify/order-paid` (version active vérifiée dans le Dev Dashboard) | 14/11 |
| Message de refus éventuel (exact) | 1er deploy : `order.cancelledAt` inconnu en 2026-10 ; scope `read_orders` non demandé (fichier réécrit par `config link` ?) | 14/11 |
| App installée sur la boutique | Oui (`itsmadeleine.app`). Historique : bloqué d'abord, l'app est dans l'organisation du développeur, la boutique dans celle du client (« Ce lien d'installation ne peut pas être utilisé »). Solution retenue : le client a ajouté le développeur à son organisation, app recréée (`itsmadeleine.app`) | 14/11 |
| Accès données client protégées (e-mail) | | |
| Boîte `commandes@` créée | Oui ; SMTP `ssl0.ovh.net:465` SSL/TLS, identifiant = adresse complète | 14/11 |
| SPF / DKIM / DMARC publiés | DKIM activé, DMARC ajouté, SPF unique vérifié (14/11) | 14/11 |
| Test Gmail : `spf=` / `dkim=` | Reçu dans l'onglet Principal ; SPF `PASS` (IP 188.165.34.173), DKIM `PASS` (domaine itsmadeleine.shop), DMARC `PASS` | 16/11 |
| Sous-domaine + SSL | `app.itsmadeleine.shop` créé, HTTPS OK (`/health` → `ok`) | 14/11 |
| Version de PHP OVH | 8.5 | 14/11 |
| Extension GD | Oui (PNG pris en charge) ; Imagick aussi présent | 14/11 |
| Base MySQL (hôte, nom) | Créée, connexion OK depuis l'API (valeurs dans `.env` uniquement) | 14/11 |
| Accès FTP/SFTP | | |

## Critères de fin

- [x] Forfait connu et noté (Basic).
- [x] App installée sur la boutique ; `shopify app deploy` a accepté l'abonnement.
- [x] `https://app.itsmadeleine.shop` répond en HTTPS.
- [x] Boîte mail créée, DKIM activé, SPF et DMARC publiés.
- [x] Version de PHP, présence de GD et accès base notés.
- [x] Mail de test vers Gmail : `spf=pass`, `dkim=pass` (16/11).

## Risques

- **Propagation DNS** : jusqu'à quelques heures. À lancer en premier.
- **Création du certificat SSL** sur le sous-domaine : parfois longue chez OVH. Shopify livre uniquement en HTTPS.
- **App et boutique dans deux organisations différentes** (développeur prestataire, boutique du client) : l'installation depuis le Dev Dashboard est refusée. Il faut que l'app appartienne à l'organisation du client, ou passer par une distribution personnalisée.
- **Abonnement refusé** au déploiement : on perd du temps sur M2. Le message d'erreur exact oriente vers le repli.
