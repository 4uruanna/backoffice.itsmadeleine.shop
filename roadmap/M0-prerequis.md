# M0 — Prérequis Shopify, OVH et mail

**Quand** : samedi 14/11, en premier (le DNS doit commencer à se propager tôt).
**Dépend de** : rien.
**Débloque** : M1 et M2.

## Objectif

Tout ce qui ne demande pas de code et qui prend du temps calendaire : forfait, app Shopify, abonnement Events, boîte mail et DNS, vérifications de l'hébergement.

## Tâches

### Shopify

- [ ] Lire le forfait de la boutique (admin → Paramètres → Forfait) et le noter dans `ROADMAP.md`. Sur Basic, préparer dès maintenant l'[annexe plan B](annexe-plan-b.md).
- [x] Créer l'app dans le Dev Dashboard **de l'organisation du client** (le développeur y a été ajouté comme membre), non intégrée à l'admin (`embedded = false`) : `itsmadeleine.app`. La première app (`itsmadeleine.love`, organisation du développeur) ne pouvait pas s'installer : supprimée.
- [ ] Déclarer l'accès aux données client protégées pour le champ **e-mail** (niveau 2), si le Dev Dashboard le demande pour une app personnalisée.
- [x] Shopify CLI ajoutée au dépôt : `@shopify/cli` 5.0.1 en dépendance de dev dans `shopify/package.json`, gérée avec **pnpm 12.18.0** (champ `packageManager`, via Corepack ; Node ≥ 22.12). Installer avec `cd shopify && pnpm install --frozen-lockfile`, puis l'appeler par `pnpm exec shopify` (les Events demandent au moins la 3.92). Le script d'installation d'`esbuild` est autorisé dans `pnpm-workspace.yaml`. Ne pas taper `pnpm deploy` (commande intégrée de pnpm) : utiliser `pnpm app:deploy`.
- [x] Relier le dossier `shopify/` à l'app (`shopify app config link`). Le link crée un fichier `shopify.app.<nom>.toml` **sans `[events]`** : on n'en garde que le `client_id`, recopié dans `shopify.app.toml`, seul fichier versionné.
- [x] Écrire `shopify/shopify.app.toml` : `client_id`, `application_url = "https://backoffice.itsmadeleine.shop"`, scopes `read_orders,read_products`, et l'abonnement ci-dessous.
- [x] `pnpm app:deploy --config shopify.app.toml` sur la nouvelle app (l'abonnement validé sur l'ancienne, version `itsmadeleine-love-2`, n'a pas été repris par le link). Vérifier ensuite dans le Dev Dashboard que la version active contient l'abonnement `commande-qr-retrait`.
- [x] Installer l'app sur la boutique de production (si ce n'est pas déjà fait), puis vérifier qu'elle apparaît dans Paramètres → Applications et canaux de vente.
- [ ] Noter le **secret client** de l'app `itsmadeleine.app` (servira pour le HMAC, dans `.env`) : Dev Dashboard → app → Paramètres (identifiants), ou `pnpm shopify app env show` depuis `shopify/` (`SHOPIFY_API_SECRET`).

Abonnement (remplace celui du §5 du plan v2) :

```toml
[events]
api_version = "2026-10"

[[events.subscription]]
handle = "commande-qr-retrait"
topic = "Order"
actions = ["create", "update"]
triggers = ["order.displayFinancialStatus", "order.cancellation"]
uri = "https://backoffice.itsmadeleine.shop/webhooks/shopify/order-paid"
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
- [ ] Vérifier l'enregistrement SPF : un seul TXT `v=spf1 include:mx.ovh.com ~all` (fusionner s'il en existe déjà un).
- [ ] Activer le DKIM dans l'espace client MX Plan.
- [ ] Ajouter un DMARC en surveillance : `_dmarc` TXT `v=DMARC1; p=none; rua=mailto:<adresse de rapport>`.
- [ ] Envoyer un mail de test depuis le webmail vers une adresse Gmail et lire l'en-tête : `spf=pass`, `dkim=pass` (à refaire le soir si le DNS n'a pas fini de se propager).

### Hébergement OVH Perso

- [ ] Créer le sous-domaine `backoffice.itsmadeleine.shop` (multisite), dossier racine `backoffice/public`, SSL activé.
- [ ] Choisir la version de PHP disponible la plus récente (8.3 ou 8.4) et la noter : Docker (M1) et `.ovhconfig` utiliseront la même.
- [ ] Vérifier sur `webhosting-infos.hosting.ovh.net` que l'extension **GD** est présente.
- [ ] Créer la base MySQL et noter hôte, nom, utilisateur et mot de passe.
- [ ] Préparer l'accès FTP/SFTP (identifiants, client FTP).

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
curl -sI https://backoffice.itsmadeleine.shop/
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
| Forfait Shopify | | |
| Version Shopify CLI | | |
| `client_id` de l'app | `a977545e9444eb4c8d30c169b1ed4c6d` (`itsmadeleine.app`, organisation du client) | 14/11 |
| Déclencheurs acceptés au deploy | `order.displayFinancialStatus` et `order.cancellation`, un seul abonnement avec `$orderId` (validé sur l'ancienne app, version `itsmadeleine-love-2`) ; redéployé sur `itsmadeleine.app` | 14/11 |
| Message de refus éventuel (exact) | 1er deploy : `order.cancelledAt` inconnu en 2026-10 ; scope `read_orders` non demandé (fichier réécrit par `config link` ?) | 14/11 |
| App installée sur la boutique | Oui (`itsmadeleine.app`). Historique : bloqué d'abord, l'app est dans l'organisation du développeur, la boutique dans celle du client (« Ce lien d'installation ne peut pas être utilisé »). Solution retenue : le client a ajouté le développeur à son organisation, app recréée (`itsmadeleine.app`) | 14/11 |
| Accès données client protégées (e-mail) | | |
| Boîte `commandes@` créée | Oui ; SMTP `ssl0.ovh.net:465` SSL/TLS, identifiant = adresse complète | 14/11 |
| SPF / DKIM / DMARC publiés | | |
| Test Gmail : `spf=` / `dkim=` | | |
| Sous-domaine + SSL | | |
| Version de PHP OVH | | |
| Extension GD | | |
| Base MySQL (hôte, nom) | | |
| Accès FTP/SFTP | | |

## Critères de fin

- Forfait connu et noté.
- App installée sur la boutique ; `shopify app deploy` a accepté l'abonnement (ou le refus est documenté).
- `https://backoffice.itsmadeleine.shop` répond en HTTPS (même avec une page vide).
- Boîte mail créée, DKIM activé, SPF et DMARC publiés.
- Version de PHP, présence de GD et accès base notés.

## Risques

- **Propagation DNS** : jusqu'à quelques heures. À lancer en premier.
- **Création du certificat SSL** sur le sous-domaine : parfois longue chez OVH. Shopify livre uniquement en HTTPS.
- **App et boutique dans deux organisations différentes** (développeur prestataire, boutique du client) : l'installation depuis le Dev Dashboard est refusée. Il faut que l'app appartienne à l'organisation du client, ou passer par une distribution personnalisée.
- **Abonnement refusé** au déploiement : on perd du temps sur M2. Le message d'erreur exact oriente vers le repli.
