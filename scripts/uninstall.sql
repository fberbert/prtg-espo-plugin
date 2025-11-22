-- Remove entities and data created by the PRTG extension
DELETE FROM entity_manager WHERE name IN ('Prtg', 'PrtgConfig');
DELETE FROM entity_manager WHERE label IN ('Prtg', 'PrtgConfig');

DELETE FROM acl WHERE scope IN ('Prtg', 'PrtgConfig');
DELETE FROM acl_role_scope WHERE scope IN ('Prtg', 'PrtgConfig');

-- Drop data tables
DROP TABLE IF EXISTS prtg;
DROP TABLE IF EXISTS prtg_config;
