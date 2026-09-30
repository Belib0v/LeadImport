CREATE TABLE IF NOT EXISTS leads (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_id     VARCHAR(32)  NULL,                  -- NOT unique: the file repeats ids with different data
    created_at      DATETIME     NULL,
    first_name      VARCHAR(100) NULL,
    last_name       VARCHAR(100) NULL,
    phone           VARCHAR(13)  NULL,                  -- +380XXXXXXXXX
    email           VARCHAR(255) NULL,
    city            VARCHAR(100) NULL,
    source          VARCHAR(100) NULL,
    utm_campaign    VARCHAR(100) NULL,
    product         VARCHAR(100) NULL,
    budget_uah      DECIMAL(12,2) NULL,
    status          VARCHAR(32)  NULL,
    manager         VARCHAR(100) NULL,
    comment         TEXT         NULL,
    next_contact_at DATETIME     NULL,
    invalid         JSON         NULL,                  -- original values that failed validation: {"phone": "12345"}
    UNIQUE KEY uq_lead (external_id, created_at),      -- one lead = its id + creation time; a repeat is skipped
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
