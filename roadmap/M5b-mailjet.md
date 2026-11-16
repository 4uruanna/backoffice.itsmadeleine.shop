# M5b — Envoi par Mailjet (API HTTPS)

**Quand** : mardi 17/11, matin. Les démarches côté Mailjet et DNS (compte, domaine, propagation) commencent dès le 16/11 au soir.
**Dépend de** : M5 (mail et gabarits), M6 (bucket).
**Débloque** : M7, M8.
**Pourquoi** : le 16/11, OVH refuse les envois SMTP qui partent de l'hébergement web (« Mail quota exceeded … 200 messages per hour and per account »), y compris pour une boîte neuve qui n'avait rien envoyé, alors que les mêmes comptes envoient normalement depuis le poste (voir [M5](M5-mail.md)). Le service transactionnel prévu en M9 est avancé.

## Pistes pour rester chez OVH (16/11)

Explication la plus probable du blocage : OVH limite le SMTP à 200 mails par heure **par compte** et à 300 par heure **par adresse IP**. L'IP de l'hébergement mutualisé est partagée avec de nombreux autres clients, qui peuvent épuiser la limite par IP ; le message renvoyé parle pourtant du compte. Cela colle avec les observations : boîte neuve refusée depuis le serveur, mêmes comptes acceptés depuis le poste.

À tester avant ou en parallèle de Mailjet, du plus simple au plus long :

1. [x] ~~**`SMTP_HOST=smtp.mail.ovh.net`**~~ (16/11 : même refus de quota, piste écartée) (port 465, `smtps`) dans le `.env` du serveur : c'est l'hôte indiqué par la documentation récente d'OVH pour les envois depuis un hébergement. Aucun code à changer ; deux commandes de test. Peut échouer de la même façon si la limite par IP s'applique aussi.
2. [ ] **Fonction `mail()` de PHP** — **code fait le 16/11** (`PhpMailTransport`, `MAIL_TRANSPORT=phpmail`), test en production en cours — (envoi des « scripts e-mails » de l'hébergement, état « Envoi actif ») : quota propre à l'hébergement, et non partagé avec d'autres clients. D'après les forums d'OVH : environ 100 mails par heure pour l'offre Perso, 200 pour Pro, 2 000 pour Performance (à vérifier dans les spécifications de l'offre). Petit changement de code : `MAIL_TRANSPORT=phpmail` (PHPMailer en mode `mail()`, même mail avec le QR intégré), `MAIL_MAX_PER_HOUR` sous le quota. À vérifier sur un mail reçu : SPF, DKIM et DMARC (signature DKIM des envois de l'hébergement non garantie).
3. [ ] **Ticket au support OVH**, avec les heures et messages de [M5](M5-mail.md).

### Test de `mail()` en production

1. `.env` du serveur :
   ```
   MAIL_TRANSPORT=phpmail
   MAIL_FROM=retrait@itsmadeleine.shop
   MAIL_FROM_NAME="It's Madeleine"
   MAIL_MAX_PER_HOUR=90
   ```
   (90 : sous le quota `mail()` de l'offre Perso, environ 100 par heure d'après les forums ; à ajuster selon l'offre.) Les variables `SMTP_*` peuvent rester, elles ne servent plus.
2. Release (`scripts/build-release.sh`) et envoi FTP.
3. Deux commandes de test coup sur coup : les deux lignes doivent passer en `sent`.
4. Dans Gmail, pour chaque mail : arrivé en boîte de réception ou en spam, QR affiché, puis « Afficher l'original » → `SPF`, `DKIM`, `DMARC`.

Si la piste 1 ou 2 fonctionne (envois répétés acceptés, en-têtes à `PASS`), Mailjet devient un repli et non plus un prérequis.

## Objectif

Envoyer le mail de retrait par l'API HTTPS de Mailjet, avec l'expéditeur `retrait@itsmadeleine.shop`, sans rien changer au reste : même contenu, QR toujours intégré au mail, mêmes statuts, même bucket, même cron.

## Choix

| Sujet | Décision |
|---|---|
| Service | Mailjet. Offre gratuite : 6 000 mails par mois, **200 par jour**, 1 000 contacts : insuffisante pour ~3 000 mails par mois → **offre payante d'entrée** à prévoir (voir Risques) |
| Pourquoi pas Brevo | 300 mails par jour gratuits, mais pas d'image intégrée (CID) : QR en pièce jointe ou en image distante, moins fiable au comptoir |
| Expéditeur | `retrait@itsmadeleine.shop`, nom `It's Madeleine`. La boîte reste chez OVH (MX inchangés) : les réponses des clients y arrivent |
| Appel | `POST https://api.mailjet.com/v3.1/send`, authentification Basic avec la clé publique et la clé secrète |
| QR | `InlinedAttachments` (`ContentType`, `Filename`, `ContentID`, `Base64Content`), référencé par `cid:` dans le HTML. La documentation de Mailjet est ambiguë sur la valeur attendue après `cid:` (ContentID ou nom de fichier) : à vérifier par un envoi réel |
| SMTP | `SmtpTransport` conservé (local avec Mailpit, et repli si OVH débloque l'hébergement) ; choix par `MAIL_TRANSPORT` |

## Démarches (côté compte et DNS)

- [ ] Créer le compte Mailjet (offre gratuite), récupérer la **clé publique** et la **clé secrète** (API Key Management). Elles vont uniquement dans le `.env` du serveur, jamais dans le dépôt ni dans un message.
- [ ] Déclarer le domaine `itsmadeleine.shop` dans Mailjet (« Senders & Domains ») et le valider (enregistrement TXT de propriété, ou validation de l'adresse `retrait@` par le mail d'activation que Mailjet y envoie).
- [ ] **DKIM** : ajouter chez OVH l'enregistrement TXT `mailjet._domainkey.itsmadeleine.shop` donné par Mailjet (clé publique). C'est lui qui fait passer DMARC (signature `d=itsmadeleine.shop`).
- [ ] **SPF** : garder **un seul** enregistrement SPF et y ajouter Mailjet, sans retirer OVH :
  ```
  v=spf1 include:mx.ovh.com include:spf.mailjet.com ~all
  ```
  (partir de l'enregistrement actuel, `dig +short TXT itsmadeleine.shop`, et n'y ajouter que `include:spf.mailjet.com`).
- [ ] DMARC : rien à changer (`_dmarc` déjà publié en M0). Mailjet utilise son propre domaine de retour, l'alignement passe par DKIM.
- [ ] Vérifier dans Mailjet que SPF et DKIM sont au vert (bouton de vérification, ou appel `POST /v3/REST/dns/{domaine}/check`).
- [ ] Ouvrir en parallèle un ticket au support OVH sur le blocage SMTP de l'hébergement (heures et messages dans [M5](M5-mail.md)), pour garder le repli.

## Tâches (code)

- [ ] `MailjetTransport` (`src/Mail/`) implémentant `MailTransport` : construit le JSON v3.1 depuis `MailMessage` (`From`, `To`, `Subject`, `TextPart`, `HTMLPart`, `InlinedAttachments`), appel HTTPS avec cURL, délai d'attente court (10 s).
- [ ] Réponses : `200` avec `Status: success` → envoyé ; `429` ou refus de quota → `RateLimitedException` (attente sans essai compté, comme le quota OVH) ; `401`/`403` (clés) et autres erreurs → `RuntimeException` avec le message de Mailjet (sans les clés) → `failed`, puis `error` au 3e échec.
- [ ] `MAIL_TRANSPORT=mailjet|smtp` (défaut `smtp`), `MAILJET_API_KEY`, `MAILJET_API_SECRET` ; expéditeur toujours lu dans `MAIL_FROM` et `MAIL_FROM_NAME`.
- [ ] **Plafond journalier** dans le bucket : `MAIL_MAX_PER_DAY=200` en plus de `MAIL_MAX_PER_HOUR`, pour que la commande reste honnêtement `pending` / `rate_limited` au lieu d'être marquée `sent` alors que Mailjet la garderait jusqu'au lendemain.
- [ ] `scripts/test-smtp.php` → `scripts/test-mail.php` (ou option `--transport=mailjet`) : envoi du vrai mail de retrait par Mailjet depuis le poste, réponse de l'API affichée, clés masquées.
- [ ] Tests : JSON produit (champs, base64 du QR, `cid:`), réponses `200`, `429`, `401`, `500` simulées (sans appel réseau), plafond journalier sur MySQL.
- [ ] Documentation : `.env.example`, [déploiement](../docs/deploiement.md), M8 (scénarios « SMTP en panne » → « clé Mailjet invalide »).

## Déploiement et test en production

1. `.env` du serveur : `MAIL_TRANSPORT=mailjet`, `MAILJET_API_KEY`, `MAILJET_API_SECRET`, `MAIL_FROM=retrait@itsmadeleine.shop`, `MAIL_FROM_NAME="It's Madeleine"`, `MAIL_MAX_PER_DAY=200`. `MAIL_ALLOWLIST` reste renseignée jusqu'à M8.
2. Release et envoi FTP.
3. Deux commandes de test coup sur coup : deux mails reçus, lignes `sent`.

## Critères de fin

- Mail reçu dans Gmail et Outlook/Hotmail, **hors spam**, QR affiché dans le corps du mail (pas en pièce jointe).
- En-têtes : `SPF`, `DKIM` (`d=itsmadeleine.shop`) et `DMARC` à `PASS`.
- Deux envois coup sur coup depuis le serveur : les deux partent (le blocage d'OVH est contourné).
- Le QR scanné depuis le mail ouvre la commande ; le lien de statut fonctionne.
- Clé invalide : `failed` avec un message lisible, sans clé dans `last_error` ni dans les journaux.

## Risques

- **Volume** (précisé le 16/11) : environ **3 000 mails par mois**, donc environ 3 000 contacts (un contact = une adresse destinataire, un client qui commande deux fois compte une fois). L'offre gratuite plafonne à 200 mails par jour, et Mailjet limite les comptes gratuits à **1 000 contacts** depuis le 06/10/2025 (au-delà : envois suspendus ; sa documentation ne dit pas si les destinataires de l'API comptent, sa page API annonce des contacts illimités). **Prévoir l'offre payante d'entrée** (environ 15 $ par mois, 15 000 mails), à confirmer sur la page tarifaire de Mailjet ou auprès de son support : plafond par jour et nombre de contacts. `MAIL_MAX_PER_DAY` sera alors relevé selon l'offre.
- **Propagation DNS** : quelques minutes à quelques heures chez OVH. Ajouter les enregistrements dès que le domaine est déclaré dans Mailjet.
- **Nouveau compte Mailjet** : un compte neuf peut être soumis à une vérification avant de pouvoir envoyer ; créer le compte au plus tôt.
- **Sortie HTTPS depuis l'hébergement** : à confirmer par le premier envoi (cURL vers `api.mailjet.com`).
