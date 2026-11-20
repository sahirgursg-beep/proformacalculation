# FRCALC: Furniture Republic price calculation and proforma

PHP app for Furniture Republic NG that calculates product costs and sales prices from recipes (BOM) and prepares proformas in Naira.

**Requirements:** PHP 8.0 or later (8.4 tested), MariaDB/MySQL, and the `zip` and `pdo_mysql` extensions.

## Setup
1. Copy `config/db_config.example.php` to `config/db_config.php` and enter the database details.
2. Import the database, then open `kurulum.php` as an admin to run the migrations.
3. Make sure `storage/` and `uploads/` are writable (755).

`config/db_config.php`, backups, uploaded images and database dumps are not kept in this repository.
