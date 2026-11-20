-- =====================================================================
-- FRCALC — Migration 001
-- 1) Tüm tabloları MyISAM'dan InnoDB'ye çevirir (transaction desteği:
--    proforma kaydı yarıda kalırsa geri alınabilsin).
-- 2) Karakter setini latin1'den utf8mb4'e çevirir (₦, Türkçe karakterler, emoji).
--    Mevcut veriniz kontrol edildi: temiz latin1, güvenle dönüştürülür.
-- 3) Sık kullanılan sorgular için eksik indeksleri ekler.
-- 4) Wizard'ın kur=1 ile kaydettiği NGN proformaları düzeltir.
--
-- ÇALIŞTIRMADAN ÖNCE phpMyAdmin > Export ile tam yedek alın.
-- phpMyAdmin > flacudfi_calc veritabanı > SQL sekmesine yapıştırıp çalıştırın.
-- =====================================================================

SET NAMES utf8mb4;

ALTER DATABASE `flacudfi_calc` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- 1+2: Motor ve karakter seti ----------
ALTER TABLE `additional_services`      ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `categories`               ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `customers`                ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `customer_types`           ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `orders`                   ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `production_orders`        ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `products`                 ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `product_packages`         ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `product_recipes`          ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `product_recipe_items`     ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `product_unit_conversions` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `proformas`                ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `proforma_items`           ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `proforma_services`        ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `projects`                 ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `rate_exchange_history`    ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `shipment_air_settings`    ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `shipment_methods`         ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `shipment_sea_containers`  ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `stock_movements`          ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `system_settings`          ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `units`                    ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `unit_types`               ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `users`                    ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `user_categories`          ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `wizard_steps`             ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `wizard_templates`         ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- 3: İndeksler ----------
-- Her fiyat hesabında son kur okunur
ALTER TABLE `rate_exchange_history` ADD INDEX `idx_currency_created` (`currency_code`, `created_at`);
-- Birim dönüşümü araması (product_id + unit_id birlikte)
ALTER TABLE `product_unit_conversions` ADD INDEX `idx_product_unit` (`product_id`, `unit_id`);
-- Reçete kalemleri sıralı okunur
ALTER TABLE `product_recipe_items` ADD INDEX `idx_recipe_item` (`recipe_id`, `item_id`);
-- Proforma listesi / dashboard
ALTER TABLE `proformas` ADD INDEX `idx_created` (`created_at`);
ALTER TABLE `proformas` ADD INDEX `idx_status` (`status`);
-- Ürün listesi kategori filtresi + sıralama
ALTER TABLE `products` ADD INDEX `idx_category_code` (`category_id`, `stock_code`);
ALTER TABLE `projects` ADD INDEX `idx_status` (`project_status`);

-- ---------- 4: Veri düzeltmesi ----------
-- Wizard ile oluşturulup kuru 1 kaydedilen NGN proformalar: o günkü güncel satış kuru yazılır
UPDATE `proformas`
SET `ngn_rate` = (SELECT `sale_rate` FROM `rate_exchange_history` WHERE `currency_code` = 'NGN' ORDER BY `created_at` DESC LIMIT 1)
WHERE `currency` = 'NGN' AND `ngn_rate` <= 1;

-- İstatistikleri güncelle
ANALYZE TABLE `products`, `product_recipes`, `product_recipe_items`, `proformas`, `proforma_items`, `rate_exchange_history`;
