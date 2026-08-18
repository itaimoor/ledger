-- The book itself. Append-only: a wrong entry is corrected by a reconciling entry of
-- the opposite type that points back at it, never by an UPDATE or a DELETE.
--
-- amount_paisa is an unsigned magnitude; direction lives in `type`. Storing signed
-- amounts as well as a type would let the two disagree.

CREATE TABLE entries (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id              BIGINT UNSIGNED NOT NULL,
    project_id          BIGINT UNSIGNED NOT NULL,
    type                ENUM('in','out') NOT NULL,
    amount_paisa        BIGINT          NOT NULL,
    category_id         BIGINT UNSIGNED NULL,
    description         VARCHAR(500)    NULL,
    entry_date          DATE            NOT NULL,
    reconciles_entry_id BIGINT UNSIGNED NULL,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- only ever set by a cascade from a soft-deleted project or organization
    deleted_at          DATETIME        NULL,
    PRIMARY KEY (id),
    -- serves the book: org + project + live rows, already ordered for the cursor and
    -- for the running-balance window function
    KEY idx_entries_book (org_id, project_id, deleted_at, entry_date, id),
    KEY idx_entries_org_date (org_id, deleted_at, entry_date),
    KEY idx_entries_category (org_id, category_id),
    KEY idx_entries_reconciles (reconciles_entry_id),
    KEY idx_entries_author (org_id, created_by),
    CONSTRAINT chk_entries_amount_positive CHECK (amount_paisa > 0),
    CONSTRAINT fk_entries_project FOREIGN KEY (org_id, project_id)
        REFERENCES projects (org_id, id),
    CONSTRAINT fk_entries_category FOREIGN KEY (org_id, category_id)
        REFERENCES categories (org_id, id),
    CONSTRAINT fk_entries_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_entries_reconciles FOREIGN KEY (reconciles_entry_id) REFERENCES entries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
