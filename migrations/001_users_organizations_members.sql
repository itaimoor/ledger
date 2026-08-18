-- Tenancy core: people, the organizations they own, and the membership binding them.

CREATE TABLE users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120)    NOT NULL,
    email         VARCHAR(255)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
    -- set when an admin provisions the account with a generated one-time password
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_seen_at  DATETIME        NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- utf8mb4_unicode_ci makes this case-insensitive; the app lowercases on write anyway
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organizations (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120)    NOT NULL,
    slug       VARCHAR(140)    NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organizations_slug (slug),
    CONSTRAINT fk_organizations_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organization_members (
    org_id    BIGINT UNSIGNED NOT NULL,
    user_id   BIGINT UNSIGNED NOT NULL,
    role      ENUM('owner','admin','accountant','viewer') NOT NULL,
    joined_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (org_id, user_id),
    KEY idx_org_members_user (user_id),
    CONSTRAINT fk_org_members_org FOREIGN KEY (org_id) REFERENCES organizations (id),
    CONSTRAINT fk_org_members_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
