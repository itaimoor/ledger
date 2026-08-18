-- Audit trail. org_id and user_id are nullable because a failed login against an
-- unknown email has neither, and those attempts still have to be recorded.

CREATE TABLE activity_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    org_id      BIGINT UNSIGNED NULL,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(64)     NOT NULL,
    entity_type VARCHAR(32)     NULL,
    entity_id   BIGINT UNSIGNED NULL,
    before_json JSON            NULL,
    after_json  JSON            NULL,
    ip          VARCHAR(45)     NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_org_time (org_id, created_at, id),
    KEY idx_activity_entity (org_id, entity_type, entity_id),
    CONSTRAINT fk_activity_org FOREIGN KEY (org_id) REFERENCES organizations (id),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
