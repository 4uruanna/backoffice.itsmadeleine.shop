# M5 — Mail au client

**Quand** : lundi 16/11, après-midi.
**Dépend de** : M4 (QR), M0 (boîte mail, DKIM).
**Débloque** : M6.
**État** : code fait le lundi 16/11 (95 tests verts, dont MySQL ; parcours complet vérifié en local avec Mailpit). Restent le déploiement et l'envoi réel vers Gmail et Outlook.

## Objectif

Envoyer au client un mail simple : texte court, lien vers la page de statut de sa commande (qui indique le point de retrait), QR code en image intégrée, numéro de commande. Le retrait se fait **le jour du concert**.

## Contenu

- **Expéditeur** : `MAIL_FROM` (`commandes@itsmadeleine.shop`), nom `MAIL_FROM_NAME`.
- **Objet** : « Votre QR code de retrait — commande #1234 ».
- **Corps HTML** :
  1. « Bonjour, » puis la phrase validée le 16/11 : **« Présentez ce QR code le jour du concert pour retirer votre commande. »** ;
  2. le QR en image intégrée (CID), avec un texte alternatif ;
  3. « Commande #1234 », en clair, pour une recherche manuelle si le QR est illisible ;
  4. un lien « Voir ma commande et le point de retrait » → `status_page_url`.
- **Version texte** : les mêmes informations, l'URL de statut écrite en entier.
- Pas d'image distante, pas de pixel de suivi.

## Tâches

- [x] Service `SmtpTransport` (`src/Mail/`, PHPMailer) : `SMTP_HOST` (`ssl0.ovh.net`, confirmé le 16/11 par `scripts/test-smtp.php`), port 465, `SMTPSecure = PHPMailer::ENCRYPTION_SMTPS`, authentification avec la boîte dédiée, `CharSet = 'UTF-8'`, délai d'attente de 10 s, EHLO et `Message-ID` au domaine `itsmadeleine.shop`. Mot de passe masqué dans les messages d'erreur.
- [x] En local : Mailpit (`SMTP_HOST=mailpit`, port 1025, sans TLS) via `.env`.
- [x] Gabarits `templates/mail/qr.html.php` et `qr.txt.php`, valeurs échappées ; lien de statut seulement si l'URL est en `https://`.
- [x] Texte final validé le 16/11 (ci-dessus).
- [x] `PickupProcessor` : prise en charge atomique `pending`/`failed` → `processing` (avancée de M7), jeton, mail → `sent`, `sent_at` ; exception SMTP → `failed`, `attempts + 1`, `last_error` (500 caractères, sans mot de passe) ; au 3e échec → `error` / `max_attempts`, alerte dans `var/log/alerts.log`.
- [x] Jamais d'envoi si `revoked_at` est rempli : contrôlé à la prise en charge **et** juste avant l'envoi (annulation reçue pendant le traitement → ligne rendue `pending` / `revoked`, aucun mail).
- [x] Réponse à Shopify avant l'envoi : `fastcgi_finish_request()` dans `public/index.php` (avancé de M7).
- [x] **Garde-fou `MAIL_ALLOWLIST`** (ajouté le 16/11) : si renseigné, seuls ces destinataires reçoivent le mail ; les autres commandes restent `pending` / `not_allowlisted`, sans tentative comptée, et partiront avec le cron (M7) une fois la liste vidée (M8). Évite d'envoyer un mail à un vrai client avant la recette.
- [x] Tests : rendu des gabarits (phrase, lien de statut, numéro, image CID, échappement, aucune image distante), QR du mail relu par le décodeur, envoi unique, échecs successifs, annulation pendant le traitement, liste d'autorisation, requêtes SQL sur MySQL.

## Déploiement et test en production

1. Dans le `.env` du serveur, ajouter (les valeurs SMTP de M0 y sont déjà) :
   ```
   MAIL_FROM=commandes@itsmadeleine.shop
   MAIL_FROM_NAME="It's Madeleine"
   MAIL_ALLOWLIST=<ton adresse Gmail>
   MAX_ATTEMPTS=3
   ```
2. Préparer la release (`scripts/build-release.sh`) et l'envoyer par FTP (nouveaux dossiers `src/Mail/` et `templates/mail/`).
3. Créer une commande taguée avec ton adresse Gmail comme client, la marquer payée : mail reçu en quelques secondes, ligne en `sent`.
4. Refaire avec une adresse Outlook/Hotmail (à ajouter à `MAIL_ALLOWLIST`, séparée par une virgule).
5. En cas de problème : `SELECT order_name, status, attempts, pending_reason, last_error FROM pickup_orders ORDER BY id DESC LIMIT 5;` et le journal d'erreurs PHP d'OVH (lignes `events:`).

**16/11, premier essai en production** : ligne `failed`, `last_error = SMTP Error: data not accepted.` Le serveur d'OVH accepte l'identifiant et le destinataire, puis refuse le contenu (le mail texte de `scripts/test-smtp.php` passait). PHPMailer ne mettait pas la raison du refus dans l'exception : `SmtpTransport` reprend désormais `ErrorInfo` (code SMTP et motif). Diagnostic, avec le vrai mail de retrait et le dialogue SMTP affiché :
```bash
docker compose run --rm php php scripts/test-smtp.php <adresse gmail> --qr --host=ssl0.ovh.net --port=465 --encryption=smtps --user=commandes@itsmadeleine.shop --from=commandes@itsmadeleine.shop "--from-name=It's Madeleine"
```

Résultat (16/11) : depuis le poste, le vrai mail de retrait (commande `#TEST`) est **accepté et reçu** dans Gmail. Le contenu de base n'est donc pas en cause. Restent à départager : les données de la vraie commande (lien `…/authenticate?key=…`, à reproduire avec `--order=` et `--status-url=`), le `.env` du serveur, ou l'envoi depuis l'hébergement. La release avec la raison du refus dans `last_error` doit être déployée pour le savoir.

Le même jour, le mail **de la vraie commande** (même numéro, même lien de statut `…/authenticate?key=…`) est aussi **accepté** depuis le poste. Le contenu est donc hors de cause : le refus vient du serveur OVH. Pistes : valeurs du `.env` du serveur (`MAIL_FROM`, `MAIL_FROM_NAME`, `SMTP_USER`), envois de mails bloqués ou désactivés pour l'hébergement (espace client, Hébergements, section des scripts e-mails), ou politique du proxy SMTP d'OVH pour les envois venant de l'hébergement. La raison exacte viendra de `last_error` une fois la release du 16/11 déployée.

Les lignes déjà en `pending` avant ce déploiement (tests de M3, M4) ne partent pas : seule une nouvelle livraison déclenche l'envoi, le cron de M7 reprendra le reste.

## Critères de fin

- [x] En local : mail reçu dans Mailpit, image affichée, lien de statut correct (16/11).
- [ ] Sur OVH : mail reçu sur Gmail et sur Outlook/Hotmail, **hors spam**, en-têtes `spf=pass` et `dkim=pass`.
- [ ] Le lien ouvre la page de statut de la commande avec le point de retrait.
- [ ] Le QR affiché dans le mail, scanné depuis un autre écran, ouvre la commande.

## Risques

- Délivrabilité de l'IP mutualisée OVH : si les mails arrivent en spam malgré SPF et DKIM, la solution post-lancement est un service transactionnel par API HTTPS (M9). Pour le 19/11, vérifier que DMARC est publié et que le contenu n'a rien de suspect (pas de lien raccourci, pas de pièce jointe).
- Images bloquées par défaut dans certains clients mail : l'image CID est généralement affichée, et le numéro de commande en clair sert de secours.
