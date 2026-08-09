<?php

/*
Plugin Name:  Bedrock Autoloader
Plugin URI:   https://github.com/roots/bedrock/
Description:  Carrega como must-use os plugins instalados em mu-plugins/ como diretório.
Version:      1.0.0
Author:       Roots
Author URI:   https://roots.io/
License:      MIT License
*/

/**
 * 🔴 Sem ESTE arquivo, todo mu-plugin em diretório é ignorado — em silêncio.
 *
 * O WordPress só carrega automaticamente ARQUIVOS soltos em `mu-plugins/`.
 * Diretório ele não olha. E o Composer instala `roots/bedrock-disallow-indexing`
 * como `mu-plugins/bedrock-disallow-indexing/`, um diretório.
 *
 * Resultado medido no container local, com `WP_ENV=development` e
 * `DISALLOW_INDEXING` valendo `true`: a página servia
 * `<meta name="robots" content="index, follow">`. A proteção que impede um
 * clone de staging de competir com o site real pelos mesmos textos nunca
 * existiu, em nenhum ambiente — só ninguém tinha olhado, porque em produção
 * `DISALLOW_INDEXING` é `false` e o comportamento certo e o quebrado são
 * idênticos.
 *
 * A classe vem pelo autoload do Composer, que o `wp-config.php` já carregou
 * antes daqui.
 */

namespace Roots\Bedrock;

if (!is_blog_installed()) {
    return;
}

new Autoloader();
