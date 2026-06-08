-- schema_chat_roles.sql — staff roles + per-player chat color
--   mysql -u <user> -p <db> < schema_chat_roles.sql   (or run in phpMyAdmin SQL tab)

ALTER TABLE players ADD COLUMN role       VARCHAR(16) NOT NULL DEFAULT 'member';
ALTER TABLE players ADD COLUMN chat_color VARCHAR(7)  NOT NULL DEFAULT '#c9d1e0';

-- Promote staff manually (roles: member | chatmod | moderator | admin | manager):
--   UPDATE players SET role='manager' WHERE username='Ryencoke';
-- Colors are fixed by role: manager=red, admin=yellow, moderator=blue, chatmod=green.
-- Members use the color they pick in Account.
