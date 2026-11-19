-- Requêtes de suivi (M7), à coller dans phpMyAdmin (une à la fois).
-- Toutes les dates sont en UTC : ajouter 1 h pour l'heure de Paris (2 h en été).
-- La session phpMyAdmin n'est pas forcément en UTC : on compare avec UTC_TIMESTAMP(), jamais NOW().

-- 1. Commandes du jour (UTC) par statut
SELECT status, pending_reason, error_code, COUNT(*) AS commandes
  FROM pickup_orders
 WHERE created_at >= UTC_DATE()
 GROUP BY status, pending_reason, error_code
 ORDER BY status;

-- 2. File du cron : pending et failed, les plus anciennes d'abord (révoquées comprises, signalées)
SELECT id, order_name, status, pending_reason, attempts, last_error,
       revoked_at IS NOT NULL AS revoquee, created_at, updated_at
  FROM pickup_orders
 WHERE status IN ('pending', 'failed', 'processing')
 ORDER BY created_at, id;

-- 3. Commandes en erreur (définitif, jamais rejoué), avec la date de l'alerte
SELECT id, order_name, shopify_order_id, error_code, attempts, last_error, created_at, alerted_at
  FROM pickup_orders
 WHERE status = 'error'
 ORDER BY created_at DESC
 LIMIT 50;

-- 4. Scans du jour (UTC) : redirigés et QR invalidés
SELECT s.scanned_at, p.order_name, s.result, p.revoked_reason
  FROM scans s
  JOIN pickup_orders p ON p.id = s.pickup_order_id
 WHERE s.scanned_at >= UTC_DATE()
 ORDER BY s.scanned_at DESC;

-- 5. Bucket : mails partis sur les 60 dernières minutes (à comparer à MAIL_MAX_PER_HOUR)
SELECT kind, COUNT(*) AS mails, MIN(sent_at) AS plus_ancien
  FROM mail_log
 WHERE sent_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR
 GROUP BY kind WITH ROLLUP;

-- 6. Traitements bloqués (processing depuis plus de 15 min : le cron les débloquera)
SELECT id, order_name, attempts, locked_at
  FROM pickup_orders
 WHERE status = 'processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE;

-- 7. Retrouver une commande par son numéro (remplacer #1094)
SELECT id, order_name, status, pending_reason, error_code, attempts, last_error,
       revoked_at, revoked_reason, created_at, sent_at, alerted_at,
       (SELECT COUNT(*) FROM scans WHERE pickup_order_id = p.id) AS scans
  FROM pickup_orders p
 WHERE order_name = '#1094';

-- 8. Lignes sans commande lisible (payload_errors, order_not_found) : order_name = '#<id>' quand
--    Shopify n'a pas renvoyé la commande. Les identifiants Shopify sont chronologiques : les
--    commandes voisines situent la date de création. Ouvrir ensuite le lien admin :
--    https://admin.shopify.com/store/<SHOPIFY_STORE_HANDLE>/orders/<shopify_order_id>
--    introuvable = commande supprimée (souvent une commande de test) → requête 9 ;
--    commande existante, payée, avec un produit tagué → requête 10.
SELECT p.id, p.shopify_order_id, p.order_name, p.error_code, p.last_error, p.webhook_id, p.created_at,
       (SELECT q.order_name FROM pickup_orders q
         WHERE q.shopify_order_id < p.shopify_order_id
           AND COALESCE(q.error_code, '') NOT IN ('payload_errors', 'order_not_found')
         ORDER BY q.shopify_order_id DESC LIMIT 1) AS commande_precedente,
       (SELECT q.order_name FROM pickup_orders q
         WHERE q.shopify_order_id > p.shopify_order_id
           AND COALESCE(q.error_code, '') NOT IN ('payload_errors', 'order_not_found')
         ORDER BY q.shopify_order_id LIMIT 1) AS commande_suivante
  FROM pickup_orders p
 WHERE p.status = 'error' AND p.error_code IN ('payload_errors', 'order_not_found')
 ORDER BY p.created_at DESC;

-- 9. Supprimer une ligne de commande supprimée dans Shopify (vérifiée avec la requête 8).
--    Remplacer l'identifiant ; ne touche ni une ligne valide ni une ligne qui a un QR.
DELETE FROM pickup_orders
 WHERE shopify_order_id = 1234567890123
   AND status = 'error' AND error_code IN ('payload_errors', 'order_not_found') AND token IS NULL;

-- 10. Reprise manuelle d'une vraie commande (existante dans Shopify, payée, non annulée, produit
--     tagué) : renseigner le numéro et l'e-mail lus dans l'admin. Le cron envoie le QR au passage
--     suivant (une fois par heure). Sans lien de statut, le mail part sans ce lien.
UPDATE pickup_orders
   SET order_name = '#1150', customer_email = 'client@example.com', status_page_url = NULL,
       status = 'pending', pending_reason = NULL, error_code = NULL, attempts = 0,
       last_error = NULL, alerted_at = NULL, updated_at = UTC_TIMESTAMP()
 WHERE shopify_order_id = 1234567890123
   AND status = 'error' AND error_code IN ('payload_errors', 'order_not_found');
