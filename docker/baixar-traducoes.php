<?php

/**
 * Baixa os pacotes de tradução no BUILD, para dentro da imagem.
 *
 * Por que aqui e não em runtime:
 *
 * - `DISALLOW_FILE_MODS` desliga o instalador de idioma do WordPress. É de
 *   propósito — é a mesma trava que impede plugin instalado pelo painel de
 *   sumir no próximo deploy — e vale para tradução também.
 * - O diretório de idiomas é `web/app/languages`, que vive na IMAGEM. Baixar
 *   em runtime custaria rede a cada boot e o resultado morreria no deploy
 *   seguinte. Pôr no volume resolveria a persistência, mas o volume é para
 *   DADO (banco e uploads); tradução é dependência, e dependência aqui é
 *   reproduzível a partir do repositório.
 *
 * A versão de cada pacote vem do que está instalado, não de um número escrito
 * à mão: o `composer.lock` manda, e um bump de core sem bump de tradução
 * deixaria strings sem tradução de um jeito difícil de perceber.
 *
 * Uso: php baixar-traducoes.php <locale> <destino> [docroot]
 */

declare(strict_types=1);

const API = 'https://api.wordpress.org/translations';

/** @var string */
$locale = $argv[1] ?? 'pt_BR';
/** @var string */
$destino = rtrim($argv[2] ?? '/app/web/app/languages', '/');
/** @var string O docroot é parâmetro para o script rodar fora do container. */
$raiz = rtrim($argv[3] ?? '/app/web', '/');

/**
 * O core falhando é erro de build. Tema e plugin falhando são aviso: um pacote
 * que ainda não existe para a versão instalada não justifica derrubar o deploy,
 * e o efeito é só string em inglês.
 */
function baixar(string $tipo, string $slug, string $versao, string $locale, string $destino): bool
{
    $query = http_build_query(array_filter([
        'slug' => $slug,
        'version' => $versao,
    ]));

    $resposta = @file_get_contents(API . "/{$tipo}/1.0/?{$query}");

    if ($resposta === false) {
        fwrite(STDERR, "traducoes: API indisponivel para {$tipo}/{$slug}\n");

        return false;
    }

    $dados = json_decode($resposta, true);
    $pacote = null;

    foreach ($dados['translations'] ?? [] as $traducao) {
        if (($traducao['language'] ?? null) === $locale) {
            $pacote = $traducao['package'];
            break;
        }
    }

    if ($pacote === null) {
        fwrite(STDERR, "traducoes: sem {$locale} para {$tipo}/{$slug} {$versao}\n");

        return false;
    }

    $zip = @file_get_contents($pacote);

    if ($zip === false) {
        fwrite(STDERR, "traducoes: download falhou — {$pacote}\n");

        return false;
    }

    $temporario = tempnam(sys_get_temp_dir(), 'traducao');
    file_put_contents($temporario, $zip);

    $arquivo = new ZipArchive();

    if ($arquivo->open($temporario) !== true) {
        unlink($temporario);
        fwrite(STDERR, "traducoes: zip invalido — {$pacote}\n");

        return false;
    }

    // Cada tipo tem seu subdiretório, e é onde `load_textdomain()` procura.
    $pasta = match ($tipo) {
        'core' => $destino,
        'themes' => "{$destino}/themes",
        'plugins' => "{$destino}/plugins",
    };

    if (!is_dir($pasta)) {
        mkdir($pasta, 0755, true);
    }

    $arquivo->extractTo($pasta);
    $arquivo->close();
    unlink($temporario);

    // O runtime lê `.mo` e, desde o 6.5, `.l10n.php`. O `.po` é a fonte que o
    // tradutor edita, é metade do peso do pacote, e nada no container abre.
    foreach ((array) glob("{$pasta}/*.po") as $fonte) {
        unlink($fonte);
    }

    echo "traducoes: {$tipo}/{$slug} {$versao} → {$locale}\n";

    return true;
}

/**
 * Versão declarada no cabeçalho do arquivo principal do tema ou do plugin.
 */
function versao_do_cabecalho(string $arquivo): ?string
{
    $cabecalho = (string) file_get_contents($arquivo, false, null, 0, 8192);

    return preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $cabecalho, $achado)
        ? trim($achado[1])
        : null;
}

// ── Core ─────────────────────────────────────────────────────────────────────

require "{$raiz}/wp/wp-includes/version.php";

/** @var string $wp_version vem do require acima */
if (!baixar('core', '', $wp_version, $locale, $destino)) {
    fwrite(STDERR, "traducoes: core em {$locale} e obrigatorio\n");
    exit(1);
}

// ── Temas ────────────────────────────────────────────────────────────────────
//
// O tema filho não tem tradução no wordpress.org — as strings dele são as do
// pai, mais o pouco que as `parts/*.html` escrevem em português direto.

foreach ((array) glob("{$raiz}/app/themes/*/style.css") as $estilo) {
    $slug = basename(dirname($estilo));
    $versao = versao_do_cabecalho($estilo);

    if ($versao !== null && $slug !== 'alab') {
        baixar('themes', $slug, $versao, $locale, $destino);
    }
}

// ── Plugins ──────────────────────────────────────────────────────────────────

foreach ((array) glob("{$raiz}/app/plugins/*", GLOB_ONLYDIR) as $diretorio) {
    $slug = basename($diretorio);

    // O arquivo principal costuma ter o nome do diretório; quando não tem,
    // vale o primeiro .php da raiz do plugin que declare uma versão.
    $candidatos = array_merge(
        (array) glob("{$diretorio}/{$slug}.php"),
        (array) glob("{$diretorio}/*.php")
    );

    foreach ($candidatos as $candidato) {
        $versao = versao_do_cabecalho($candidato);

        if ($versao !== null) {
            baixar('plugins', $slug, $versao, $locale, $destino);
            break;
        }
    }
}
