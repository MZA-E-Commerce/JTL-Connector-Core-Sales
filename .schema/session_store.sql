CREATE TABLE `session_store` (
     `session_id` VARCHAR(255) NOT NULL COLLATE 'utf8mb4_0900_ai_ci',
     `expires_at` DATETIME NOT NULL,
     `session_data` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_0900_ai_ci',
     PRIMARY KEY (`session_id`) USING BTREE
)
    COLLATE='utf8mb4_0900_ai_ci'
    ENGINE=InnoDB
;