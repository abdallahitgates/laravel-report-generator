-- Synthetic pharma sales-force schema for benchmarking the Report Generator.
-- Shaped like a legacy CRM on purpose: most relationships exist only as
-- *_id naming conventions; a few are real foreign keys. That exercises both
-- relationship-inference paths in the schema scanner.
--
-- `lines` is a MySQL reserved word (ProCRM has a table with that name), so
-- it is quoted here and the query compiler must always quote identifiers.
--
-- Data is deterministic (arithmetic on the row number, no RAND()), so two
-- runs with the same size produce identical tables and comparable timings.

CREATE TABLE seq_1m (n INT UNSIGNED NOT NULL PRIMARY KEY);

CREATE TABLE `lines` (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(64) NOT NULL
);

CREATE TABLE specialities (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(64) NOT NULL
);

CREATE TABLE bricks (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  region VARCHAR(32) NOT NULL
);

CREATE TABLE users (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  line_id INT UNSIGNED NOT NULL,
  manager_id INT UNSIGNED NULL,
  password VARCHAR(255) NOT NULL,
  remember_token VARCHAR(100) NULL,
  KEY users_line_id_index (line_id)
);

CREATE TABLE doctors (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  speciality_id INT UNSIGNED NOT NULL,
  brick_id INT UNSIGNED NOT NULL,
  class CHAR(1) NOT NULL,
  CONSTRAINT doctors_speciality_id_foreign FOREIGN KEY (speciality_id) REFERENCES specialities (id),
  KEY doctors_brick_id_index (brick_id)
);

CREATE TABLE products (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  line_id INT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  KEY products_line_id_index (line_id)
);

-- Fact table 1: one row per visit.
CREATE TABLE visits (
  id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NOT NULL,
  visit_date DATE NOT NULL,
  status VARCHAR(16) NOT NULL,
  duration_min SMALLINT UNSIGNED NOT NULL,
  KEY visits_visit_date_index (visit_date),
  KEY visits_user_id_visit_date_index (user_id, visit_date),
  KEY visits_doctor_id_index (doctor_id)
);

-- Fact table 2: one row per sale line.
CREATE TABLE sales (
  id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  brick_id INT UNSIGNED NOT NULL,
  sale_date DATE NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  value DECIMAL(14,2) NOT NULL,
  CONSTRAINT sales_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id),
  KEY sales_sale_date_index (sale_date),
  KEY sales_product_id_sale_date_index (product_id, sale_date),
  KEY sales_brick_id_index (brick_id)
);

DELIMITER //

-- Fills a fact table in 1M-row INSERT ... SELECT batches so each statement
-- stays small enough for the redo log and progress is visible.
CREATE PROCEDURE bench_seed(IN fact_rows BIGINT UNSIGNED)
BEGIN
  DECLARE batch BIGINT UNSIGNED DEFAULT 0;
  DECLARE batches BIGINT UNSIGNED DEFAULT CEIL(fact_rows / 1000000);
  DECLARE n_users INT UNSIGNED DEFAULT 2000;
  DECLARE n_doctors INT UNSIGNED DEFAULT 200000;
  DECLARE n_products INT UNSIGNED DEFAULT 300;
  DECLARE n_bricks INT UNSIGNED DEFAULT 5000;

  SET FOREIGN_KEY_CHECKS = 0;
  TRUNCATE sales; TRUNCATE visits; TRUNCATE products; TRUNCATE doctors;
  TRUNCATE users; TRUNCATE bricks; TRUNCATE specialities; TRUNCATE `lines`; TRUNCATE seq_1m;
  SET FOREIGN_KEY_CHECKS = 1;

  -- 0..999999 from six cross-joined digit sets.
  INSERT INTO seq_1m (n)
  SELECT a.d + b.d*10 + c.d*100 + d.d*1000 + e.d*10000 + f.d*100000
  FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c,
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d,
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) e,
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) f;

  INSERT INTO `lines` SELECT n + 1, CONCAT('Line ', n + 1) FROM seq_1m WHERE n < 20;
  INSERT INTO specialities SELECT n + 1, CONCAT('Speciality ', n + 1) FROM seq_1m WHERE n < 40;
  INSERT INTO bricks SELECT n + 1, CONCAT('Brick ', n + 1), CONCAT('Region ', 1 + n % 25) FROM seq_1m WHERE n < n_bricks;
  INSERT INTO users
    SELECT n + 1, CONCAT('Rep ', n + 1), 1 + n % 20, IF(n < 100, NULL, 1 + n % 100), 'not-a-real-hash', NULL
    FROM seq_1m WHERE n < n_users;
  INSERT INTO doctors
    SELECT n + 1, CONCAT('Dr ', n + 1), 1 + (n * 7) % 40, 1 + (n * 13) % n_bricks, ELT(1 + n % 3, 'A', 'B', 'C')
    FROM seq_1m WHERE n < n_doctors;
  INSERT INTO products
    SELECT n + 1, CONCAT('Product ', n + 1), 1 + n % 20, 10 + (n * 37) % 490
    FROM seq_1m WHERE n < n_products;

  WHILE batch < batches DO
    INSERT INTO visits
      SELECT batch * 1000000 + n + 1,
             1 + ((batch * 1000000 + n) * 7919) % n_users,
             1 + ((batch * 1000000 + n) * 104729) % n_doctors,
             DATE_ADD('2023-01-01', INTERVAL ((batch * 1000000 + n) * 31) % 1095 DAY),
             ELT(1 + (batch * 1000000 + n) % 4, 'done', 'done', 'done', 'missed'),
             5 + ((batch * 1000000 + n) * 3) % 40
      FROM seq_1m WHERE batch * 1000000 + n < fact_rows;

    INSERT INTO sales
      SELECT batch * 1000000 + n + 1,
             1 + ((batch * 1000000 + n) * 211) % n_products,
             1 + ((batch * 1000000 + n) * 6007) % n_bricks,
             DATE_ADD('2023-01-01', INTERVAL ((batch * 1000000 + n) * 17) % 1095 DAY),
             1 + ((batch * 1000000 + n) * 11) % 50,
             (1 + ((batch * 1000000 + n) * 11) % 50) * (10 + (((batch * 1000000 + n) * 211) % n_products * 37) % 490)
      FROM seq_1m WHERE batch * 1000000 + n < fact_rows;

    SET batch = batch + 1;
  END WHILE;

  ANALYZE TABLE visits, sales, doctors, users, products, bricks;
END //

DELIMITER ;
