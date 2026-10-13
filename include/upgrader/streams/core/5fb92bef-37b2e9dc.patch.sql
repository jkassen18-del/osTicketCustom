/**
 * @version v1.18.4-custom
 * @signature 37b2e9dcd457c62446ed0fd2d247906b
 * @title Connectors, incident monitoring, SLA escalation and API scopes
 *
 * Adds the connector framework (Linear / Microsoft Teams / webhooks) with
 * per-department channel routing, the incident monitoring inbox, SLA
 * response targets with escalation rules and per-ticket adjustments, and
 * additional API key scopes (read / reply / report incidents).
 */

ALTER TABLE `%TABLE_PREFIX%sla`
  ADD `response_period` int(10) unsigned NOT NULL default '0' AFTER `grace_period`,
  ADD `warn_percent` tinyint(3) unsigned NOT NULL default '0' AFTER `response_period`,
  ADD `escalation_rules` text AFTER `warn_percent`;

ALTER TABLE `%TABLE_PREFIX%api_key`
  ADD `can_read_tickets` TINYINT( 1 ) UNSIGNED NOT NULL DEFAULT '0' AFTER `can_exec_cron`,
  ADD `can_reply_tickets` TINYINT( 1 ) UNSIGNED NOT NULL DEFAULT '0' AFTER `can_read_tickets`,
  ADD `can_report_incidents` TINYINT( 1 ) UNSIGNED NOT NULL DEFAULT '0' AFTER `can_reply_tickets`;

ALTER TABLE `%TABLE_PREFIX%ticket`
  CHANGE `source` `source` enum('Web','Email','Phone','API','Other','Linear','Teams','Monitor') NOT NULL default 'Other';

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%connector` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` varchar(32) NOT NULL default '',
  `name` varchar(128) NOT NULL default '',
  `flags` int(10) unsigned NOT NULL default '0',
  `config` text,
  `notes` text,
  `last_run` datetime default NULL,
  `last_error` text,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY  (`id`),
  KEY `type` (`type`)
) DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%connector_channel` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `connector_id` int(11) unsigned NOT NULL default '0',
  `dept_id` int(11) unsigned NOT NULL default '0',
  `channel_id` varchar(255) NOT NULL default '',
  `name` varchar(128) NOT NULL default '',
  `flags` int(10) unsigned NOT NULL default '0',
  `extra` text,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY  (`id`),
  KEY `connector_id` (`connector_id`),
  KEY `dept_id` (`dept_id`),
  KEY `channel_id` (`channel_id`(191))
) DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%connector_link` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `connector_id` int(11) unsigned NOT NULL default '0',
  `channel_id` int(11) unsigned NOT NULL default '0',
  `object_type` char(1) NOT NULL default 'T',
  `object_id` int(11) unsigned NOT NULL default '0',
  `external_id` varchar(255) NOT NULL default '',
  `external_key` varchar(64) default NULL,
  `external_url` varchar(512) default NULL,
  `extra` text,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `object` (`connector_id`,`object_type`,`object_id`),
  KEY `external` (`connector_id`,`external_id`(191))
) DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%connector_event` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `connector_id` int(11) unsigned NOT NULL default '0',
  `channel_id` int(11) unsigned NOT NULL default '0',
  `direction` char(3) NOT NULL default 'out',
  `event` varchar(64) NOT NULL default '',
  `object_type` char(1) default NULL,
  `object_id` int(11) unsigned default NULL,
  `external_id` varchar(255) default NULL,
  `status` varchar(16) NOT NULL default 'ok',
  `detail` text,
  `created` datetime NOT NULL,
  PRIMARY KEY  (`id`),
  KEY `connector_id` (`connector_id`,`created`),
  KEY `status` (`status`)
) DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%connector_job` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `connector_id` int(11) unsigned NOT NULL default '0',
  `channel_id` int(11) unsigned NOT NULL default '0',
  `action` varchar(64) NOT NULL default '',
  `object_type` char(1) default NULL,
  `object_id` int(11) unsigned default NULL,
  `payload` text,
  `status` varchar(16) NOT NULL default 'pending',
  `attempts` int(10) unsigned NOT NULL default '0',
  `next_attempt` datetime default NULL,
  `last_error` text,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY  (`id`),
  KEY `status` (`status`,`next_attempt`),
  KEY `connector_id` (`connector_id`)
) DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%incident` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `source` varchar(128) NOT NULL default '',
  `external_id` varchar(255) NOT NULL default '',
  `severity` varchar(16) NOT NULL default 'warning',
  `status` varchar(16) NOT NULL default 'open',
  `title` varchar(255) NOT NULL default '',
  `detail` text,
  `url` varchar(512) default NULL,
  `dept_id` int(11) unsigned NOT NULL default '0',
  `ticket_id` int(11) unsigned NOT NULL default '0',
  `api_key_id` int(10) unsigned NOT NULL default '0',
  `occurrences` int(10) unsigned NOT NULL default '1',
  `extra` text,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `acknowledged` datetime default NULL,
  `resolved` datetime default NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `source_key` (`source`,`external_id`(150)),
  KEY `status` (`status`,`severity`),
  KEY `dept_id` (`dept_id`),
  KEY `ticket_id` (`ticket_id`)
) DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%sla_event` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `ticket_id` int(11) unsigned NOT NULL default '0',
  `sla_id` int(11) unsigned NOT NULL default '0',
  `type` varchar(16) NOT NULL default '',
  `step` int(10) unsigned NOT NULL default '0',
  `staff_id` int(10) unsigned NOT NULL default '0',
  `detail` text,
  `created` datetime NOT NULL,
  PRIMARY KEY  (`id`),
  KEY `ticket_id` (`ticket_id`,`type`),
  KEY `sla_id` (`sla_id`)
) DEFAULT CHARSET=utf8;

-- Queue column: SLA plan (optional column agents can add to any queue)
INSERT IGNORE INTO `%TABLE_PREFIX%queue_column`
  (`id`, `flags`, `name`, `primary`, `secondary`, `filter`, `truncate`, `annotations`, `conditions`)
  VALUES (15, 0, 'SLA Plan', 'sla_id', NULL, NULL, 'wrap', '[{"c":"SlaDueDecoration","p":"a"}]', '[]');

-- Finished with patch
UPDATE `%TABLE_PREFIX%config`
    SET `value` = '37b2e9dcd457c62446ed0fd2d247906b'
    WHERE `key` = 'schema_signature' AND `namespace` = 'core';
