-- Create embed token management and audit tables
CREATE TABLE IF NOT EXISTS sf_embed_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jti VARCHAR(64) UNIQUE NOT NULL,
  label VARCHAR(120),
  view_type VARCHAR(32) NOT NULL,
  site_id INT NULL,
  allowed_origin VARCHAR(255) NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  last_used_at DATETIME NULL,
  use_count INT DEFAULT 0,
  INDEX idx_jti (jti),
  INDEX idx_expires (expires_at),
  INDEX idx_created_by (created_by)
);

CREATE TABLE IF NOT EXISTS sf_public_views_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jti VARCHAR(64) NULL,
  view_type VARCHAR(32) NOT NULL,
  site_id INT NULL,
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  status INT NOT NULL,
  viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_viewed_at (viewed_at),
  INDEX idx_jti (jti)
);

CREATE TABLE IF NOT EXISTS sf_public_rate_limit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_value VARCHAR(128) NOT NULL,
  key_type ENUM('ip','jti') NOT NULL,
  window_start DATETIME NOT NULL,
  window_seconds INT NOT NULL,
  request_count INT DEFAULT 1,
  UNIQUE KEY uq_rl (key_value, key_type, window_start, window_seconds),
  INDEX idx_window (window_start)
);
