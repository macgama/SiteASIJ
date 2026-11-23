-- Schéma compatible MySQL/MariaDB et SQLite.
-- Les types sont volontairement simples; bin/install.php adapte AUTO_INCREMENT.

CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'member',      -- 'admin' | 'member'
  is_active INTEGER NOT NULL DEFAULT 1,
  totp_secret VARCHAR(64) NULL,
  failed_logins INTEGER NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE user_groups (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  description TEXT NULL
);

CREATE TABLE user_group_members (
  user_id INTEGER NOT NULL,
  group_id INTEGER NOT NULL,
  PRIMARY KEY (user_id, group_id)
);

CREATE TABLE pages (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  parent_id INTEGER NULL,
  slug VARCHAR(190) NOT NULL,
  path VARCHAR(500) NOT NULL UNIQUE,               -- chemin complet calculé, ex. /presentation/statuts
  title VARCHAR(255) NOT NULL,
  menu_title VARCHAR(255) NULL,
  meta_description VARCHAR(500) NULL,
  blocks TEXT NOT NULL,                            -- JSON: liste de blocs
  status VARCHAR(20) NOT NULL DEFAULT 'draft',     -- 'draft' | 'published'
  show_in_menu INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  legacy_url VARCHAR(500) NULL,                    -- ancienne URL Jimdo (redirection 301)
  updated_at DATETIME NOT NULL,
  updated_by INTEGER NULL
);

CREATE TABLE page_revisions (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  page_id INTEGER NOT NULL,
  title VARCHAR(255) NOT NULL,
  blocks TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  created_by INTEGER NULL
);

CREATE TABLE media (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  stored_name VARCHAR(255) NOT NULL UNIQUE,        -- nom sur disque dans storage/media
  original_name VARCHAR(255) NOT NULL,
  title VARCHAR(255) NULL,
  mime VARCHAR(100) NOT NULL,
  size INTEGER NOT NULL,
  kind VARCHAR(20) NOT NULL,                       -- 'image' | 'file'
  alt VARCHAR(255) NULL,
  legacy_url VARCHAR(700) NULL,
  created_at DATETIME NOT NULL,
  created_by INTEGER NULL
);

CREATE TABLE private_folders (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  parent_id INTEGER NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
);

-- Un dossier est visible par les groupes listés ici (héritage vers les sous-dossiers).
CREATE TABLE private_folder_groups (
  folder_id INTEGER NOT NULL,
  group_id INTEGER NOT NULL,
  PRIMARY KEY (folder_id, group_id)
);

CREATE TABLE private_files (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  folder_id INTEGER NOT NULL,
  stored_name VARCHAR(255) NOT NULL UNIQUE,        -- nom aléatoire dans storage/private
  original_name VARCHAR(255) NOT NULL,
  title VARCHAR(255) NULL,
  mime VARCHAR(100) NOT NULL,
  size INTEGER NOT NULL,
  sha256 VARCHAR(64) NOT NULL,
  uploaded_by INTEGER NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE settings (
  name VARCHAR(100) PRIMARY KEY,
  value TEXT NULL
);

CREATE TABLE audit_log (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INTEGER NULL,
  action VARCHAR(100) NOT NULL,
  target VARCHAR(255) NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE login_attempts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  ip VARCHAR(64) NOT NULL,
  email VARCHAR(190) NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE password_resets (
  token_hash VARCHAR(64) PRIMARY KEY,
  user_id INTEGER NOT NULL,
  expires_at DATETIME NOT NULL
);

CREATE TABLE contact_messages (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL
);
