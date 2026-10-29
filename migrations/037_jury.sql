-- 037 风纪委员会：申请、委员名册、众裁案件与投票、月度津贴

CREATE TABLE jury_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    handled_by BIGINT UNSIGNED NULL,
    handle_note VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    handled_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_jury_app_user (user_id),
    KEY idx_jury_app_status (status, id),
    CONSTRAINT fk_jury_app_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jury_members (
    user_id BIGINT UNSIGNED NOT NULL,
    term_start DATE NOT NULL,
    term_end DATE NULL,
    rating ENUM('none','a','b','c') NOT NULL DEFAULT 'none',
    last_stipend_month CHAR(7) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_jury_member_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jury_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_type ENUM('post','comment','confession','user','report') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    reporter_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    snapshot TEXT NULL,
    status ENUM('voting','closed') NOT NULL DEFAULT 'voting',
    verdict ENUM('none','violation','clean') NOT NULL DEFAULT 'none',
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_jury_cases_status (status, id),
    KEY idx_jury_cases_target (target_type, target_id),
    CONSTRAINT fk_jury_case_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jury_votes (
    case_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    vote ENUM('violation','clean') NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (case_id, user_id),
    KEY idx_jury_votes_user (user_id, created_at),
    CONSTRAINT fk_jury_vote_case FOREIGN KEY (case_id) REFERENCES jury_cases (id) ON DELETE CASCADE,
    CONSTRAINT fk_jury_vote_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jury_stipends (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    cases INT NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_jury_stipend (user_id, month),
    CONSTRAINT fk_jury_stipend_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS jury_stipends;
DROP TABLE IF EXISTS jury_votes;
DROP TABLE IF EXISTS jury_cases;
DROP TABLE IF EXISTS jury_members;
DROP TABLE IF EXISTS jury_applications;
