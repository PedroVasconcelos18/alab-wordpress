<?php

/**
 * Overrides para WP_ENV === 'staging'
 *
 * Mantenha staging o mais perto possível de produção. O que não pode ser igual
 * é a indexação: um clone indexado compete com o site real pelos mesmos textos.
 */

use Roots\WPConfig\Config;

Config::define('DISALLOW_INDEXING', true);
