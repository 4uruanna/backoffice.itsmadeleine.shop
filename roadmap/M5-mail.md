# M5 — Mail au client

**Quand** : lundi 16/11, après-midi.
**Dépend de** : M4 (QR), M0 (boîte mail, DKIM).
**Débloque** : M6.

## Objectif

Envoyer au client un mail simple : texte court, lien vers la page de statut de sa commande (qui indique le point de retrait), QR code en image intégrée, numéro de commande.

## Contenu

- **Expéditeur** : `MAIL_FROM` (`commandes@itsmadeleine.shop`), nom `MAIL_FROM_NAME`.
- **Objet** : « Votre QR code de retrait — commande #1234 ».
- **Corps HTML** :
  1. une phrase : présentez ce QR code en boutique pour retirer votre commande ;
  2. le QR en image intégrée (CID), avec un texte alternatif ;
  3. « Commande #1234 », en clair, pour une recherche manuelle si le QR est illisible ;
  4. un lien « Voir ma commande et le point de retrait » → `status_page_url`.
- **Version texte** : les mêmes informations, l'URL de statut écrite en entier.
- Pas d'image distante, pas de pixel de suivi.

## Tâches

- [ ] Service `Mailer` (PHPMailer) : `SMTP_HOST` (`ssl0.ovh.net`, confirmé le 16/11 par `scripts/test-smtp.php`), port 465, `SMTPSecure = PHPMailer::ENCRYPTION_SMTPS`, authentification avec la boîte dédiée, `CharSet = 'UTF-8'`, délai d'attente court (10 s).
- [ ] En local : Mailpit (`SMTP_HOST=mailpit`, port 1025, sans TLS) via `.env`.
- [ ] Gabarits `templates/mail/qr.html.php` et `qr.txt.php`, valeurs échappées.
- [ ] Rédiger le texte final (une ou deux phrases), relu par toi.
- [ ] Envoi d'une commande : `processing` → mail envoyé → `sent`, `sent_at` ; exception SMTP → `failed`, `attempts + 1`, `last_error` (tronqué, sans mot de passe).
- [ ] Ne jamais envoyer si `revoked_at` est rempli (contrôle juste avant l'envoi).
- [ ] Test : rendu du gabarit avec une commande fictive (présence du lien de statut, du numéro, de l'image CID).

## Critères de fin

- En local : mail reçu dans Mailpit, image affichée, lien de statut correct.
- Sur OVH : mail reçu sur Gmail et sur Outlook/Hotmail, **hors spam**, en-têtes `spf=pass` et `dkim=pass`.
- Le lien ouvre la page de statut de la commande avec le point de retrait.
- Le QR affiché dans le mail, scanné depuis un autre écran, ouvre la commande.

## Risques

- Délivrabilité de l'IP mutualisée OVH : si les mails arrivent en spam malgré SPF et DKIM, la solution post-lancement est un service transactionnel par API HTTPS (M9). Pour le 19/11, vérifier que DMARC est publié et que le contenu n'a rien de suspect (pas de lien raccourci, pas de pièce jointe).
- Images bloquées par défaut dans certains clients mail : l'image CID est généralement affichée, et le numéro de commande en clair sert de secours.
