-- Online Backup for WordPress 3.0.4, database schema version 12.
-- https://plugins.svn.wordpress.org/wponlinebackup/trunk/wponlinebackup.php
-- The plugin appends ENGINE=MyISAM to this definition when creating the table.
CREATE TABLE `wp_wponlinebackup_items` (
	`bin` INT(10) UNSIGNED NOT NULL,
	`item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`parent_id` INT(10) UNSIGNED NOT NULL,
	`type` SMALLINT(1) UNSIGNED NOT NULL,
	`name` VARBINARY(255) NOT NULL,
	`name_bin` VARBINARY(255) NOT NULL,
	`exists` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`file_size` INT(10) UNSIGNED DEFAULT NULL,
	`mod_time` INT(10) UNSIGNED DEFAULT NULL,
	`backup` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`new_exists` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`new_file_size` INT(10) UNSIGNED DEFAULT NULL,
	`new_mod_time` INT(10) UNSIGNED DEFAULT NULL,
	`activity_id` INT(10) UNSIGNED NOT NULL,
	`counter` INT(10) UNSIGNED NOT NULL,
	`path` BLOB NOT NULL,
	PRIMARY KEY (`bin`,`item_id`),
	UNIQUE `item` (`bin`,`parent_id`,`type`,`name`),
	KEY `browse` (`bin`,`parent_id`,`exists`,`type`,`name`),
	KEY `activity_id` (`activity_id`,`backup`,`bin`,`item_id`),
	KEY `exists` (`bin`,`exists`,`activity_id`)
) ENGINE=MyISAM;
