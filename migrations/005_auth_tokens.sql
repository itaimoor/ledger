-- Refresh tokens, manually-delivered invites, and the rate-limit counter.
--
-- Both token columns hold a SHA-256 hex digest of a 256-bit random secret, never the
-- secret. Argon2id is for low-entropy passwords; for a full-entropy random token a
-- fast digest is the correct choice and keeps refresh cheap.

CREATE TABLE refresh_tokens (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    -- shared by every token descended from one login; reuse revokes the whole family
    family_id  CHAR(32)        NOT NULL,
    token_hash CHAR(64)        NOT NULL,
    expires_at DATETIME        NOT NULL,
    used_at    DATETIME        NULL,
    revoked_at DATETIME        NULL,
    ip         VARCHAR(45)     NULL,
    user_agent VARCHAR(255)    NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_tokens_hash (token_hash),
    KEY idx_refresh_tokens_family (family_id),
    KEY idx_refresh_tokens_user (user_id),
    KEY idx_refresh_tokens_expiry (expires_at),
    CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- There is no mail sending in this phase. An invite is a link the admin copies out of
-- the response and delivers by hand. The role enum omits 'owner' on purpose: ownership
-- is transferred deliberately in org settings, never handed out by a link.
CREATE TABLE invites (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id           BIGINT UNSIGNED NOT NULL,
    email            VARCHAR(255)    NOT NULL,
    role             ENUM('admin','accountant','viewer') NOT NULL,
    token_hash       CHAR(64)        NOT NULL,
    invited_by       BIGINT UNSIGNED NOT NULL,
    expires_at       DATETIME        NOT NULL,
    accepted_at      DATETIME        NULL,
    accepted_user_id BIGINT UNSIGNED NULL,
    revoked_at       DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invites_token (token_hash),
    KEY idx_invites_org_email (org_id, email),
    CONSTRAINT fk_invites_org FOREIGN KEY (org_id) REFERENCES organizations (id),
    CONSTRAINT fk_invites_invited_by FOREIGN KEY (invited_by) REFERENCES users (id),
    CONSTRAINT fk_invites_accepted_user FOREIGN KEY (accepted_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window counters. One upsert per limited request; the limiter prunes expired
-- windows as it goes.
CREATE TABLE rate_limits (
    bucket       VARCHAR(160) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, window_start),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
