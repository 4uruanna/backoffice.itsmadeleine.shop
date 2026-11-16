-- M7 : alertes groupées. Une ligne `error` est signalée une seule fois par le mail d'alerte
-- du cron ; alerted_at est remis à NULL quand la ligne repasse en `error`.
-- Sur OVH : à lancer une fois dans phpMyAdmin, avant de déclarer le cron.
-- En local : exécuté à la création du volume ; sur un volume existant :
--   docker compose exec -T mysql mysql -ubackoffice -pbackoffice backoffice < migrations/002_alerted_at.sql

ALTER TABLE pickup_orders ADD COLUMN alerted_at DATETIME NULL AFTER sent_at;
