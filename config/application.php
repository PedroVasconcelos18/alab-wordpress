<?php

/**
 * Configuração base de produção. Overrides por ambiente vão nos arquivos
 * config/environments/{{WP_ENV}}.php.
 *
 * A política é desviar da produção o mínimo possível: defina aqui tudo que
 * puder ser definido aqui.
 */

use Roots\WPConfig\Config;

use function Env\env;

// CONVERT_* + STRIP_QUOTES + LOCAL_FIRST
Env\Env::$options
    = Env\Env::CONVERT_BOOL
    | Env\Env::CONVERT_NULL
    | Env\Env::CONVERT_INT
    | Env\Env::STRIP_QUOTES
    | Env\Env::LOCAL_FIRST;

/**
 * Diretório com todos os arquivos do site
 *
 * @var string
 */
$root_dir = dirname(__DIR__);

/**
 * Document Root
 *
 * @var non-falsy-string
 */
$webroot_dir = $root_dir . '/web';

/**
 * Dotenv carrega o .env da raiz. .env.local sobrescreve .env, se existir.
 * No Railway não há .env: as variáveis vêm do painel.
 */
if (file_exists($root_dir . '/.env')) {
    $env_files = file_exists($root_dir . '/.env.local')
        ? ['.env', '.env.local']
        : ['.env'];

    $repository = Dotenv\Repository\RepositoryBuilder::createWithNoAdapters()
        ->addAdapter(Dotenv\Repository\Adapter\EnvConstAdapter::class)
        ->addAdapter(Dotenv\Repository\Adapter\PutenvAdapter::class)
        ->immutable()
        ->make();

    $dotenv = Dotenv\Dotenv::create($repository, $root_dir, $env_files, false);
    $dotenv->load();

    $dotenv->required(['WP_HOME', 'WP_SITEURL']);

    // Em SQLite não existe credencial de banco para exigir — o "banco" é um
    // arquivo no volume. Exigir DB_USER aqui faria o boot falhar com uma
    // mensagem que não descreve o problema.
    if (env('DB_ENGINE') !== 'sqlite' && !env('DATABASE_URL')) {
        $dotenv->required(['DB_NAME', 'DB_USER', 'DB_PASSWORD']);
    }
}

/**
 * Constante global de ambiente. Default: production.
 */
define('WP_ENV', env('WP_ENV') ?: 'production');

/**
 * WP_ENVIRONMENT_TYPE, se ainda não definido
 */
if (!defined('WP_ENVIRONMENT_TYPE')) {
    $wp_environment_type = env('WP_ENVIRONMENT_TYPE');

    if ($wp_environment_type) {
        Config::define('WP_ENVIRONMENT_TYPE', $wp_environment_type);
    } elseif (in_array(WP_ENV, ['production', 'staging', 'development', 'local'], true)) {
        Config::define('WP_ENVIRONMENT_TYPE', WP_ENV);
    }
}

/**
 * URLs
 *
 * ⚠️ WP_HOME é o caminho PÚBLICO (https://alabventure.com/blog), não o host da
 * origem no Railway. É o que faz o WordPress emitir links do domínio final.
 * WP_SITEURL termina em /wp porque no Bedrock o core vive em web/wp.
 */
Config::define('WP_HOME', env('WP_HOME'));
Config::define('WP_SITEURL', env('WP_SITEURL'));

/**
 * Raiz da landing da A.lab.
 *
 * O tema linka de volta para o site através do token {{APP_URL}} nas partes
 * do tema (ver web/app/themes/alab/functions.php). Vazio = caminho absoluto
 * de raiz, que é o correto em produção, porque o blog vive em /blog do MESMO
 * domínio.
 *
 * ⚠️ Em desenvolvimento o WordPress É a raiz, então esses links dariam 404 do
 * próprio WordPress. Aponte para a landing rodando local se quiser testá-los.
 */
Config::define('ALAB_APP_URL', env('ALAB_APP_URL') ?: '');

/**
 * Content directory
 */
Config::define('CONTENT_DIR', '/app');
Config::define('WP_CONTENT_DIR', $webroot_dir . Config::get('CONTENT_DIR'));
Config::define('WP_CONTENT_URL', Config::get('WP_HOME') . Config::get('CONTENT_DIR'));

/**
 * Banco
 */
$table_prefix = env('DB_PREFIX') ?: 'wp_';

if (env('DB_ENGINE') === 'sqlite') {
    /**
     * SQLite. O "banco" é um arquivo, entregue pelo drop-in `web/app/db.php`
     * (plugin `sqlite-database-integration`).
     *
     * 🔴 `DB_DIR` PRECISA apontar para dentro do volume persistente. O padrão
     * do plugin é `WP_CONTENT_DIR . '/database/'`, que no container vive na
     * imagem — cada deploy começaria com um site em branco, sem erro nenhum.
     * Em local o padrão serve, porque não há container.
     */
    Config::define('DB_ENGINE', 'sqlite');
    Config::define('DB_DIR', env('DB_DIR') ?: $webroot_dir . '/app/database/');
    Config::define('DB_FILE', env('DB_FILE') ?: '.ht.sqlite');

    // O core e vários plugins leem estas constantes sem checar o engine.
    // Indefinidas, geram notice; com credencial de verdade, confundem.
    Config::define('DB_NAME', 'alab_sqlite');
    Config::define('DB_USER', '');
    Config::define('DB_PASSWORD', '');
    Config::define('DB_HOST', '');
    Config::define('DB_CHARSET', 'utf8mb4');
    Config::define('DB_COLLATE', '');
} else {
    if (env('DB_SSL')) {
        Config::define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL);
    }

    Config::define('DB_NAME', env('DB_NAME'));
    Config::define('DB_USER', env('DB_USER'));
    Config::define('DB_PASSWORD', env('DB_PASSWORD'));
    Config::define('DB_HOST', env('DB_HOST') ?: 'localhost');
    Config::define('DB_CHARSET', 'utf8mb4');
    Config::define('DB_COLLATE', '');

    if (env('DATABASE_URL')) {
        $dsn = (object) parse_url(env('DATABASE_URL'));

        Config::define('DB_NAME', substr($dsn->path, 1));
        Config::define('DB_USER', $dsn->user);
        Config::define('DB_PASSWORD', isset($dsn->pass) ? $dsn->pass : null);
        Config::define('DB_HOST', isset($dsn->port) ? "{$dsn->host}:{$dsn->port}" : $dsn->host);
    }
}

/**
 * Salts
 */
Config::define('AUTH_KEY', env('AUTH_KEY'));
Config::define('SECURE_AUTH_KEY', env('SECURE_AUTH_KEY'));
Config::define('LOGGED_IN_KEY', env('LOGGED_IN_KEY'));
Config::define('NONCE_KEY', env('NONCE_KEY'));
Config::define('AUTH_SALT', env('AUTH_SALT'));
Config::define('SECURE_AUTH_SALT', env('SECURE_AUTH_SALT'));
Config::define('LOGGED_IN_SALT', env('LOGGED_IN_SALT'));
Config::define('NONCE_SALT', env('NONCE_SALT'));

/**
 * Ajustes
 */
Config::define('AUTOMATIC_UPDATER_DISABLED', true);
Config::define('DISABLE_WP_CRON', env('DISABLE_WP_CRON') ?: false);

// Editor de arquivo no admin, desligado.
Config::define('DISALLOW_FILE_EDIT', true);

// Instalação/atualização de plugin e tema pelo admin, desligada: as
// dependências vêm do Composer, versionadas no lock. Instalar pelo painel
// criaria um estado que o próximo deploy apaga sem avisar.
Config::define('DISALLOW_FILE_MODS', true);

Config::define('WP_POST_REVISIONS', env('WP_POST_REVISIONS') ?? true);
Config::define('CONCATENATE_SCRIPTS', false);

// O Rank Math recusa carregar o frontend enquanto a "registration" for
// inválida — e ela é inválida até o site ser conectado a uma conta
// rankmath.com. Sem esta constante o plugin fica ativo e **não emite nada**:
// nem meta description, nem Open Graph, nem JSON-LD. Falha silenciosa, que só
// aparece inspecionando o HTML servido.
//
// `RANK_MATH_REGISTRATION_SKIP` é a saída prevista pelo próprio plugin
// (`Conditional::is_invalid_registration`). Não queremos conta em serviço de
// terceiro para um blog que usa só os módulos locais.
Config::define('RANK_MATH_REGISTRATION_SKIP', true);

// Indexação é decisão de ambiente, nunca dado de banco. Definir aqui garante
// valor em TODO ambiente — sem isso a constante fica indefinida em produção e
// o `blog_public` do banco volta a mandar, que é como um clone de staging
// desindexaria o domínio inteiro. Os arquivos de environments/ sobrescrevem
// para `true`.
Config::define('DISALLOW_INDEXING', false);

/**
 * Debug
 */
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', false);
Config::define('SCRIPT_DEBUG', false);
ini_set('display_errors', '0');

/**
 * Deixa o WordPress detectar HTTPS atrás de proxy reverso — aqui há dois: a
 * Vercel na frente e o Railway terminando o TLS da origem.
 */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

/**
 * A requisição que a origem recebe NÃO é a que o cliente fez.
 *
 * Medido em produção, nas duas pontas:
 *
 *   cliente  →  GET /blog/wp/wp-admin/   Host: alabventure.com
 *   origem   ←  GET /wp/wp-admin/        Host: blog-production-….up.railway.app
 *
 * A Vercel troca o `Host` pelo da origem e **remove o prefixo `/blog`** antes
 * de repassar. Quase tudo no WordPress passa por `WP_HOME` e sai certo — o HTML
 * servido não tem uma única ocorrência do host do Railway.
 *
 * 🔴 O que não passa é o código que lê `$_SERVER` cru. `auth_redirect()` monta
 * o `redirect_to` com `HTTP_HOST . REQUEST_URI`, e vários formulários do
 * wp-admin usam `REQUEST_URI` como action. Com os dois valores errados, quem
 * abre `/blog/wp/wp-admin/` deslogado faz login no domínio certo e é jogado
 * numa URL que não existe.
 *
 * Corrigir só o host troca um defeito por outro: o destino vira
 * `alabventure.com/wp/wp-admin/`, sem o `/blog`. Os dois andam juntos.
 *
 * Reconstruir a requisição pública devolve o WordPress ao caso normal de
 * instalação em subdiretório, que `WP::parse_request()` trata sozinho: ele tira
 * o caminho de `home_url()` do começo de `REQUEST_URI` antes de rotear.
 */
$url_publica = (string) env('WP_HOME');
$host_publico = parse_url($url_publica, PHP_URL_HOST);
$prefixo_publico = rtrim((string) parse_url($url_publica, PHP_URL_PATH), '/');

if ($host_publico) {
    $_SERVER['HTTP_HOST'] = $host_publico;
}

// Idempotente: em desenvolvimento não há prefixo, e um proxy que preserve o
// caminho não deve levar o prefixo duas vezes.
if ($prefixo_publico !== '' && isset($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI'];

    if ($uri !== $prefixo_publico && !str_starts_with($uri, $prefixo_publico . '/')) {
        $_SERVER['REQUEST_URI'] = $prefixo_publico . $uri;
    }
}

$env_config = __DIR__ . '/environments/' . WP_ENV . '.php';

if (file_exists($env_config)) {
    require_once $env_config;
}

Config::apply();

/**
 * Bootstrap do WordPress
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $webroot_dir . '/wp/');
}
