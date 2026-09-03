<?php

/**
 * Overrides para WP_ENV === 'development'
 */

use Roots\WPConfig\Config;

use function Env\env;

Config::define('SAVEQUERIES', true);
Config::define('WP_DEBUG', true);
Config::define('WP_DEBUG_DISPLAY', true);
Config::define('WP_DEBUG_LOG', env('WP_DEBUG_LOG') ?? true);
Config::define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
Config::define('SCRIPT_DEBUG', true);
Config::define('DISALLOW_INDEXING', true);

ini_set('display_errors', '1');

// Produção também instala pelo painel desde que a trava saiu, então isto
// deixou de ser um override e virou garantia: se alguém definir
// `DISALLOW_FILE_MODS=true` nas variáveis para endurecer produção, local
// continua aberto para experimentar.
Config::define('DISALLOW_FILE_MODS', false);
Config::define('DISALLOW_FILE_EDIT', false);
