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
