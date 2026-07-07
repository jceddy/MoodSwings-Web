-- Friend relationships between two users. One row per unordered pair
-- (user_low_id, user_high_id are always sorted ascending by the app layer),
-- so a pair of users can only ever have a single relationship row no
-- matter who initiated it.
--
-- status: 'pending' (invite awaiting a response), 'accepted' (mutual
-- friends), or 'blocked'. action_user_id's meaning depends on status: who
-- sent the pending invite, or who performed the block.
CREATE TABLE IF NOT EXISTS friendships (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_low_id INT UNSIGNED NOT NULL,
    user_high_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted', 'blocked') NOT NULL,
    action_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_friendships_pair (user_low_id, user_high_id),
    KEY idx_friendships_user_high_id (user_high_id),
    CONSTRAINT fk_friendships_user_low FOREIGN KEY (user_low_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_friendships_user_high FOREIGN KEY (user_high_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_friendships_order CHECK (user_low_id < user_high_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
