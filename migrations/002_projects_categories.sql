-- Projects and the shared category list. Both are org-owned.
--
-- Each carries a UNIQUE (org_id, id). That key is redundant on its own, but it lets
-- entries declare a composite foreign key on (org_id, project_id), so the database
-- itself refuses to hold an entry pointing at another tenant's project.

CREATE TABLE projects (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id      BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(160)    NOT NULL,
    client_name VARCHAR(160)    NULL,
    description TEXT            NULL,
    status      ENUM('active','completed','archived') NOT NULL DEFAULT 'active',
    created_by  BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_org_id (org_id, id),
    KEY idx_projects_org_listing (org_id, deleted_at, status),
    CONSTRAINT fk_projects_org FOREIGN KEY (org_id) REFERENCES organizations (id),
    CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id      BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(80)     NOT NULL,
    type        ENUM('in','out','both') NOT NULL DEFAULT 'both',
    is_archived TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_org_id (org_id, id),
    UNIQUE KEY uq_categories_org_name (org_id, name),
    CONSTRAINT fk_categories_org FOREIGN KEY (org_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
