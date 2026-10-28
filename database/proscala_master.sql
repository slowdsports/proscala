-- ============================================================
-- Proscala - Base de datos central (multiempresa)
-- Guarda el registro de empresas (identificadas por su RTN en el login)
-- y qué correos pueden entrar a cada una. Los datos de cada empresa,
-- incluidos sus usuarios y contraseñas, viven en su propia base.
--
-- Estructura vacía, para instalar Proscala desde cero. Las empresas se dan de
-- alta después desde Empresas (empresas.php), como super administrador.
-- Local: base "proscala". Producción: la base que indique proscala_config.php.
-- ============================================================

CREATE DATABASE IF NOT EXISTS proscala CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE proscala;

CREATE TABLE IF NOT EXISTS empresas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(150) NOT NULL,
  rtn        VARCHAR(20)  NOT NULL,
  db_nombre  VARCHAR(64)  NOT NULL,
  activo     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_empresas_rtn (rtn),
  UNIQUE KEY db_nombre (db_nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Correos que pueden entrar a cada empresa. Un mismo correo puede estar en
-- varias empresas (con usuario y contraseña propios en cada una).
-- superadmin=1 permite gestionar empresas al entrar con esa empresa.
CREATE TABLE IF NOT EXISTS accesos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(150) NOT NULL,
  empresa_id INT          NOT NULL,
  superadmin TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_accesos_email_empresa (email, empresa_id),
  CONSTRAINT fk_accesos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos de inicio de sesión con un RTN que no corresponde a ninguna empresa
-- (los demás intentos se registran en la bitácora de cada empresa)
CREATE TABLE IF NOT EXISTS log_accesos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  rtn        VARCHAR(30)  NULL,
  email      VARCHAR(150) NULL,
  ip         VARCHAR(45)  NULL,
  motivo     VARCHAR(100) NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enlaces por correo para restablecer la contraseña o activar una cuenta nueva (solo se guarda
-- el hash del enlace). motivo: 'recuperar' (¿olvidó su contraseña?) o 'activar' (usuario nuevo)
CREATE TABLE IF NOT EXISTS recuperaciones (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT          NOT NULL,
  email      VARCHAR(150) NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  motivo     ENUM('recuperar','activar') NOT NULL DEFAULT 'recuperar',
  expira     DATETIME     NOT NULL,
  usado_en   DATETIME     NULL,
  ip         VARCHAR(45)  NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_recuperaciones_token (token_hash),
  KEY idx_recuperaciones_email (email, empresa_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inicios de sesión fallidos por IP: 5 en 15 minutos bloquean la IP 15 minutos (login.php)
CREATE TABLE IF NOT EXISTS intentos_login (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45)  NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_intentos_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Respaldos completos descargados por el super administrador al iniciar sesión (uno cada 7 días)
CREATE TABLE IF NOT EXISTS respaldos_descargas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(150) NOT NULL,
  ip         VARCHAR(45)  NULL,
  tamano     BIGINT       NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_respaldos_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
