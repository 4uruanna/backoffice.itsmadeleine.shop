# M5b — Envoi par la fonction mail() de l'hébergement

**Quand** : lundi 16/11 (ajouté et résolu le même jour).
**Dépend de** : M5 (mail et gabarits), M6 (bucket).
**Débloque** : M7, M8.
**État** : ✅ envoi rétabli le 16/11 au soir. Gmail : boîte de réception (16/11) ; **Outlook : indésirables**, atténuations en cours. Reste le plafond horaire selon l'offre de l'hébergement.

## Problème

Le 16/11, OVH refuse les envois SMTP (`ssl0.ovh.net`, puis `smtp.mail.ovh.net`) qui partent de l'hébergement web : `550 5.7.0 Mail quota exceeded … 200 messages per hour and per account`, y compris pour une boîte neuve (`retrait@`) qui n'avait rien envoyé, alors que les mêmes comptes envoient normalement depuis le poste. Explication la plus probable : OVH limite aussi le SMTP à 300 mails par heure **par adresse IP**, et l'IP de l'hébergement mutualisé est partagée avec d'autres clients ; le message parle pourtant du compte. Détail des essais dans [M5](M5-mail.md).

## Solution retenue

Envoi par la **fonction `mail()` de PHP**, expéditeur `commandes@itsmadeleine.shop`, c'est-à-dire par le service « Scripts e-mails » de l'hébergement : quota propre à l'hébergement, non partagé avec d'autres clients.

- Code : `PhpMailTransport` (`src/Mail/`), même mail que le SMTP (HTML, texte, QR intégré), expéditeur d'enveloppe `-f` sur le domaine. Choix par `MAIL_TRANSPORT=phpmail` ; `SmtpTransport` conservé pour le local (Mailpit) et en repli.
- **Condition d'OVH** : le relais `mail()` refuse un mail dont l'en-tête `From` n'est pas sur le domaine de l'hébergement (`550 5.7.1 Rejected by policy: From header domain does not align with authenticated domain`). L'hébergement de l'API avait pour domaine principal `amnesic.dev` : un expéditeur `@itsmadeleine.shop` y était refusé. **Résolu le 16/11 en alignant le domaine de l'expéditeur sur le domaine principal de l'hébergement qui envoie.**
- Test préalable sur l'hébergement de `itsmadeleine.shop` (`scripts/test-mail-hebergement.php`) : mail reçu avec `SPF` `PASS` (IP 46.105.60.65, relais de l'hébergement), `DKIM` `PASS` (`d=itsmadeleine.shop`), `DMARC` `PASS`, mais classé en indésirables (contenu de test minimal, réputation de l'IP du relais).
- `/health` affiche la version déployée et le transport (`mail_transport: phpmail`) pour vérifier un déploiement.

Pistes écartées le 16/11 : `smtp.mail.ovh.net` (même refus) ; service transactionnel externe (Mailjet, Brevo), devenu inutile.

## Configuration (`.env` du serveur, 16/11)

```
MAIL_TRANSPORT=phpmail
MAIL_FROM=commandes@itsmadeleine.shop
MAIL_FROM_NAME="It's Madeleine Shop"
MAIL_ALLOWLIST=<ton adresse Gmail>
MAIL_MAX_PER_HOUR=90
MAX_ATTEMPTS=3
MAIL_LOG_RETENTION_DAYS=30
```

- Expéditeur : retour à `commandes@itsmadeleine.shop` (la boîte `retrait@` créée pour les tests n'est plus utilisée).
- `MAIL_MAX_PER_HOUR=90` : sous le quota `mail()` de l'offre Perso (environ 100 par heure d'après les forums OVH). Si l'hébergement qui envoie est une offre supérieure (Pro : environ 200), la valeur peut être relevée.
- `MAIL_ALLOWLIST` : garde-fou jusqu'à la mise en service, **à vider en M8**.
- Les variables `SMTP_*` restent dans le fichier mais ne servent plus en production (repli possible avec `MAIL_TRANSPORT=smtp`).

## Reste à faire

- [x] Configuration finale relevée le 16/11 (ci-dessus).
- [ ] Préciser l'hébergement qui envoie et son offre (pour ajuster `MAIL_MAX_PER_HOUR`).
- [x] **Délivrabilité du vrai mail de retrait** (16/11) : **Gmail : boîte de réception, QR affiché.** **Outlook : indésirables.**
- [x] Outlook, en-têtes relevés le 16/11 : `spf=pass` (IP 46.105.72.194, `smtp.mailfrom=mail-out.cluster100.hosting.ovh.net` : OVH réécrit l'expéditeur d'enveloppe, le `-f` n'est pas conservé), `dkim=pass header.d=itsmadeleine.shop` (sélecteur `ovhmo-selector-1`, en-têtes signés : `From` seulement), `dmarc=pass` (aligné par DKIM), `compauth=pass reason=100`. Score anti-spam sortant d'OVH (`X-VR-SPAMSCORE`) : 10, état `OK`. **L'authentification est complète : le classement en indésirables vient de la réputation de l'IP du relais mutualisé et/ou du contenu**, pas d'un défaut de configuration.
- [ ] Relever aussi `X-Forefront-Antispam-Report` (`SCL`, `SFV`, `CAT`) et `X-Microsoft-Antispam` (`BCL`) pour connaître le motif exact de Microsoft.
- [ ] Atténuations sans changer d'infrastructure : texte du mail un peu plus fourni (moins d'image par rapport au texte), message sur la page de remerciement et dans la confirmation de commande Shopify (« votre QR code arrive de `commandes@itsmadeleine.shop`, pensez à vérifier vos indésirables »), et consigne au staff : sans QR, retrouver la commande par le nom ou le numéro (M8).
- [ ] Après lancement (M9) : selon les rapports DMARC, passer `_dmarc` de `p=none` à `p=quarantine` (Microsoft favorise les domaines à politique DMARC appliquée), une fois vérifié que tous les envois légitimes du domaine (boîtes MX Plan, `mail()` de l'hébergement, Shopify s'il envoie au nom du domaine) passent DKIM ou SPF alignés.
- [ ] Si le vrai mail arrive en indésirables : relire le contenu (objet, texte, lien), marquer « Non spam » sur les tests, et réévaluer (le passage par un service transactionnel reste possible en dernier recours, il n'est pas prévu).
- [ ] Ticket au support OVH sur le refus SMTP (facultatif, pour garder le repli SMTP).
- [ ] Les commandes de test restées en `pending` / `rate_limited` partiront avec le cron (M7).

## Critères de fin

- Deux commandes de test coup sur coup depuis le serveur : les deux en `sent`, aucun avis de non-remise dans les Scripts e-mails.
- Mails reçus, QR affiché dans le corps du mail, en-têtes à `PASS`, de préférence en boîte de réception.
- Le QR scanné depuis le mail ouvre la commande ; le lien de statut fonctionne.
