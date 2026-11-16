# M5b — Envoi par la fonction mail() de l'hébergement

**Quand** : lundi 16/11 (ajouté et résolu le même jour).
**Dépend de** : M5 (mail et gabarits), M6 (bucket).
**Débloque** : M7, M8.
**État** : ✅ envoi rétabli le 16/11 au soir. Restent la délivrabilité du vrai mail (boîte de réception ou indésirables) et le plafond horaire selon l'offre de l'hébergement.

## Problème

Le 16/11, OVH refuse les envois SMTP (`ssl0.ovh.net`, puis `smtp.mail.ovh.net`) qui partent de l'hébergement web : `550 5.7.0 Mail quota exceeded … 200 messages per hour and per account`, y compris pour une boîte neuve (`retrait@`) qui n'avait rien envoyé, alors que les mêmes comptes envoient normalement depuis le poste. Explication la plus probable : OVH limite aussi le SMTP à 300 mails par heure **par adresse IP**, et l'IP de l'hébergement mutualisé est partagée avec d'autres clients ; le message parle pourtant du compte. Détail des essais dans [M5](M5-mail.md).

## Solution retenue

Envoi par la **fonction `mail()` de PHP**, c'est-à-dire par le service « Scripts e-mails » de l'hébergement : quota propre à l'hébergement, non partagé avec d'autres clients.

- Code : `PhpMailTransport` (`src/Mail/`), même mail que le SMTP (HTML, texte, QR intégré), expéditeur d'enveloppe `-f` sur le domaine. Choix par `MAIL_TRANSPORT=phpmail` ; `SmtpTransport` conservé pour le local (Mailpit) et en repli.
- **Condition d'OVH** : le relais `mail()` refuse un mail dont l'en-tête `From` n'est pas sur le domaine de l'hébergement (`550 5.7.1 Rejected by policy: From header domain does not align with authenticated domain`). L'hébergement de l'API avait pour domaine principal `amnesic.dev` : un expéditeur `@itsmadeleine.shop` y était refusé. **Résolu le 16/11 en alignant le domaine de l'expéditeur sur le domaine principal de l'hébergement qui envoie.**
- Test préalable sur l'hébergement de `itsmadeleine.shop` (`scripts/test-mail-hebergement.php`) : mail reçu avec `SPF` `PASS` (IP 46.105.60.65, relais de l'hébergement), `DKIM` `PASS` (`d=itsmadeleine.shop`), `DMARC` `PASS`, mais classé en indésirables (contenu de test minimal, réputation de l'IP du relais).
- `/health` affiche la version déployée et le transport (`mail_transport: phpmail`) pour vérifier un déploiement.

Pistes écartées le 16/11 : `smtp.mail.ovh.net` (même refus) ; service transactionnel externe (Mailjet, Brevo), devenu inutile.

## Configuration (`.env` du serveur)

```
MAIL_TRANSPORT=phpmail
MAIL_FROM=retrait@itsmadeleine.shop
MAIL_FROM_NAME="It's Madeleine"
MAIL_MAX_PER_HOUR=<sous le quota mail() de l'offre>
```

Quota `mail()` par heure selon l'offre (forums OVH, à vérifier dans les caractéristiques de l'offre) : Perso environ 100, Pro 200, Performance 2 000. `MAIL_MAX_PER_HOUR` est fixé un peu en dessous (ex. 90 pour Perso). Les variables `SMTP_*` ne servent plus en production.

## Reste à faire

- [ ] Préciser ici la configuration finale : hébergement qui envoie, son offre, domaine principal, valeur de `MAIL_MAX_PER_HOUR`.
- [ ] **Délivrabilité du vrai mail de retrait** : deux commandes de test, mails vérifiés dans Gmail **et** Outlook/Hotmail : boîte de réception ou indésirables, QR affiché, `SPF`, `DKIM`, `DMARC` à `PASS`.
- [ ] Si le vrai mail arrive en indésirables : relire le contenu (objet, texte, lien), marquer « Non spam » sur les tests, et réévaluer (le passage par un service transactionnel reste possible en dernier recours, il n'est pas prévu).
- [ ] Ticket au support OVH sur le refus SMTP (facultatif, pour garder le repli SMTP).
- [ ] Les commandes de test restées en `pending` / `rate_limited` partiront avec le cron (M7).

## Critères de fin

- Deux commandes de test coup sur coup depuis le serveur : les deux en `sent`, aucun avis de non-remise dans les Scripts e-mails.
- Mails reçus, QR affiché dans le corps du mail, en-têtes à `PASS`, de préférence en boîte de réception.
- Le QR scanné depuis le mail ouvre la commande ; le lien de statut fonctionne.
