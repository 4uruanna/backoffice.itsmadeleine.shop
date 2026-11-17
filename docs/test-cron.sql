-- Test du cron de rattrapage en production (M7), sans passer de commande Shopify.
-- 1. Remplacer ADRESSE@ICI par l'adresse de MAIL_ALLOWLIST (sinon rien ne part : not_allowlisted).
-- 2. Exécuter le bloc INSERT dans phpMyAdmin, attendre le passage suivant du cron.
-- 3. Vérifier avec la requête SELECT, puis nettoyer avec le DELETE.
-- Les identifiants Shopify sont fictifs : le lien vers l'admin (QR, alerte) ouvrira une commande introuvable.
-- Bucket : 3 mails de retrait + 1 alerte.

INSERT INTO pickup_orders
  (shopify_order_id, order_name, customer_email, status, attempts, last_error, error_code,
   revoked_at, revoked_reason, locked_at, created_at, updated_at)
VALUES
  -- A : en attente → envoyée
  (900000000001, '#TEST-CRON-A', 'ADRESSE@ICI', 'pending', 0, NULL, NULL,
   NULL, NULL, NULL, UTC_TIMESTAMP() - INTERVAL 50 MINUTE, UTC_TIMESTAMP()),
  -- B : en échec après 1 essai → rejouée, envoyée
  (900000000002, '#TEST-CRON-B', 'ADRESSE@ICI', 'failed', 1, 'test : échec simulé', NULL,
   NULL, NULL, NULL, UTC_TIMESTAMP() - INTERVAL 40 MINUTE, UTC_TIMESTAMP()),
  -- C : en attente mais annulée → jamais envoyée, reste pending
  (900000000003, '#TEST-CRON-C', 'ADRESSE@ICI', 'pending', 0, NULL, NULL,
   UTC_TIMESTAMP(), 'cancelled', NULL, UTC_TIMESTAMP() - INTERVAL 30 MINUTE, UTC_TIMESTAMP()),
  -- D : en erreur sans e-mail → listée dans l'alerte groupée
  (900000000004, '#TEST-CRON-D', NULL, 'error', 0, NULL, 'no_email',
   NULL, NULL, NULL, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, UTC_TIMESTAMP()),
  -- E : traitement abandonné depuis 20 min → débloquée (1 essai compté), envoyée
  (900000000005, '#TEST-CRON-E', 'ADRESSE@ICI', 'processing', 0, NULL, NULL,
   NULL, NULL, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, UTC_TIMESTAMP() - INTERVAL 10 MINUTE, UTC_TIMESTAMP());

-- Vérification après le passage du cron. Attendu :
--   A sent (0 essai), B sent (1 essai), C pending (annulée), D error signalée, E sent (1 essai).
SELECT order_name, status, pending_reason, error_code, attempts, last_error,
       revoked_at IS NOT NULL AS annulee, sent_at, alerted_at
  FROM pickup_orders
 WHERE order_name LIKE '#TEST-CRON-%'
 ORDER BY order_name;

-- Nettoyage (les scans éventuels suivent par cascade ; mail_log est gardé : ce sont de vrais envois).
DELETE FROM pickup_orders WHERE order_name LIKE '#TEST-CRON-%';
