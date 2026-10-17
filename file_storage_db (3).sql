-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： 127.0.0.1
-- 產生時間： 2026-09-28 15:00:14
-- 伺服器版本： 10.4.32-MariaDB
-- PHP 版本： 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 資料庫： `file_storage_db`
--

-- --------------------------------------------------------

--
-- 資料表結構 `preview_tokens`
--

CREATE TABLE IF NOT EXISTS `preview_tokens` (
  `token` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token`),
  KEY `idx_preview_tokens_file` (`file_id`),
  KEY `idx_preview_tokens_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `announcements`
--

CREATE TABLE `announcements` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `recipient_id` int(10) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `sender_id`, `recipient_id`, `message`, `sent_at`) VALUES
(1, 3, 2, 'Hi', '2026-03-02 03:33:58'),
(2, 3, 2, '1', '2026-09-28 19:56:29'),
(3, 3, 2, 'test', '2026-09-28 20:38:57'),
(4, 3, 2, '1', '2026-09-28 20:41:02'),
(5, 2, 3, 'ㄅ', '2026-09-28 20:51:30'),
(6, 2, 3, '1', '2026-09-28 20:51:35'),
(7, 2, 3, '1', '2026-09-28 20:51:36'),
(8, 2, 3, '11', '2026-09-28 20:51:36'),
(9, 2, 3, 'test', '2026-09-28 20:51:52'),
(10, 2, 3, '1', '2026-09-28 20:52:09'),
(11, 2, 3, '2', '2026-09-28 20:52:17'),
(12, 2, 3, '1', '2026-09-28 20:52:25'),
(13, 3, 2, '1232', '2026-09-28 20:56:43');

-- --------------------------------------------------------

--
-- 資料表結構 `files`
--

CREATE TABLE `files` (
  `file_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `folder_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `files`
--

INSERT INTO `files` (`file_id`, `user_id`, `folder_id`, `original_filename`, `stored_filename`, `file_size`, `created_at`) VALUES
(3, 3, 0, 'admin.php', 'admin.php', 47496, '2026-03-03 02:59:17'),
(4, 2, 0, 'admin.php', 'admin_1.php', 47496, '2026-03-03 02:59:37');

-- --------------------------------------------------------

--
-- 資料表結構 `file_shares`
--

CREATE TABLE `file_shares` (
  `share_id` int(10) UNSIGNED NOT NULL,
  `file_id` int(10) UNSIGNED NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `share_password` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `file_shares`
--

INSERT INTO `file_shares` (`share_id`, `file_id`, `share_token`, `expires_at`, `share_password`, `created_at`) VALUES
(3, 3, '8dc20fabe01d30b64d96675375984439', '2026-10-05 20:40:57', NULL, '2026-09-28 20:40:57');

-- --------------------------------------------------------

--
-- 資料表結構 `folders`
--

CREATE TABLE `folders` (
  `folder_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `parent_folder` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `folder_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED DEFAULT NULL,
  `recipient_id` int(10) UNSIGNED NOT NULL,
  `type` enum('message','file','system') NOT NULL DEFAULT 'system',
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `notifications`
--

INSERT INTO `notifications` (`id`, `sender_id`, `recipient_id`, `type`, `message`, `created_at`, `is_read`) VALUES
(5, 3, 2, 'message', 'user2 傳送了一則訊息給你', '2026-09-28 19:56:29', 1),
(6, 3, 2, 'file', 'user2 傳送了一個檔案給你: admin.php', '2026-09-28 20:13:53', 1),
(7, 3, 2, 'message', 'user2 傳送了一則訊息給你', '2026-09-28 20:38:57', 1),
(8, 3, 2, 'message', 'user2 傳送了一則訊息給你', '2026-09-28 20:41:02', 1),
(17, 3, 2, 'message', 'user2 傳送了一則訊息給你', '2026-09-28 20:56:43', 1),
(18, 3, 2, 'file', 'user2 傳送了一個檔案給你: 秋奈開幕抽獎券暨抽獎結果_2026-09-11 (2).xlsx', '2026-09-28 20:57:58', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `shared_files`
--

CREATE TABLE `shared_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `recipient_id` int(10) UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `shared_files`
--

INSERT INTO `shared_files` (`id`, `sender_id`, `recipient_id`, `file_path`, `original_filename`, `sent_at`, `is_read`) VALUES
(2, 3, 2, 'uploads/shared/admin.php', 'admin.php', '2026-03-03 02:59:27', 1),
(3, 3, 2, 'uploads/shared/S__174153733.jpg', 'S__174153733.jpg', '2026-03-03 03:10:24', 1),
(4, 3, 2, 'uploads/shared/admin_1.php', 'admin.php', '2026-09-28 20:13:53', 1),
(5, 3, 2, 'uploads/shared/秋奈開幕抽獎券暨抽獎結果_2026-09-11 (2).xlsx', '秋奈開幕抽獎券暨抽獎結果_2026-09-11 (2).xlsx', '2026-09-28 20:57:58', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('online','idle','away','hidden','offline') NOT NULL DEFAULT 'offline',
  `status_mode` enum('auto','idle','dnd','invisible') NOT NULL DEFAULT 'auto',
  `last_active` datetime DEFAULT NULL,
  `last_interaction` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `role`, `is_admin`, `status`, `status_mode`, `last_active`, `last_interaction`, `created_at`) VALUES
(2, 'user1', '123', 'user1@local.register', 'user', 0, 'away', 'dnd', '2026-09-28 21:00:09', '2026-09-28 21:00:06', '2026-03-01 19:32:40'),
(3, 'user2', '123', 'user2@local.register', 'user', 0, 'idle', 'idle', '2026-09-28 20:58:51', '2026-09-28 20:58:49', '2026-03-01 19:33:37');

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_announcements_active_date` (`is_active`,`start_date`,`end_date`,`created_at`);

--
-- 資料表索引 `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_pair_time` (`sender_id`,`recipient_id`,`sent_at`),
  ADD KEY `idx_chat_recipient` (`recipient_id`);

--
-- 資料表索引 `files`
--
ALTER TABLE `files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `idx_files_user_folder_created` (`user_id`,`folder_id`,`created_at`),
  ADD KEY `idx_files_folder_id` (`folder_id`);

--
-- 資料表索引 `file_shares`
--
ALTER TABLE `file_shares`
  ADD PRIMARY KEY (`share_id`),
  ADD UNIQUE KEY `uk_file_shares_token` (`share_token`),
  ADD KEY `idx_file_shares_file` (`file_id`),
  ADD KEY `idx_file_shares_expires` (`expires_at`);

--
-- 資料表索引 `folders`
--
ALTER TABLE `folders`
  ADD PRIMARY KEY (`folder_id`),
  ADD KEY `idx_folders_user_parent` (`user_id`,`parent_folder`);

--
-- 資料表索引 `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_recipient_read_created` (`recipient_id`,`is_read`,`created_at`),
  ADD KEY `idx_notifications_sender` (`sender_id`);

--
-- 資料表索引 `shared_files`
--
ALTER TABLE `shared_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shared_files_recipient_read` (`recipient_id`,`is_read`),
  ADD KEY `idx_shared_files_sender` (`sender_id`),
  ADD KEY `idx_shared_files_sent_at` (`sent_at`);

--
-- 資料表索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uk_users_username` (`username`),
  ADD UNIQUE KEY `uk_users_email` (`email`);

--
-- 在傾印的資料表使用自動遞增(AUTO_INCREMENT)
--

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `files`
--
ALTER TABLE `files`
  MODIFY `file_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `file_shares`
--
ALTER TABLE `file_shares`
  MODIFY `share_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `folders`
--
ALTER TABLE `folders`
  MODIFY `folder_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `shared_files`
--
ALTER TABLE `shared_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 已傾印資料表的限制式
--

--
-- 資料表的限制式 `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `fk_chat_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_chat_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 資料表的限制式 `files`
--
ALTER TABLE `files`
  ADD CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 資料表的限制式 `file_shares`
--
ALTER TABLE `file_shares`
  ADD CONSTRAINT `fk_file_shares_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 資料表的限制式 `folders`
--
ALTER TABLE `folders`
  ADD CONSTRAINT `fk_folders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 資料表的限制式 `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notifications_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- 資料表的限制式 `shared_files`
--
ALTER TABLE `shared_files`
  ADD CONSTRAINT `fk_shared_files_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_shared_files_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
