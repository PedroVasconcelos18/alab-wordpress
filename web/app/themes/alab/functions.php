<?php

/**
 * Tema A.lab — filho do Twenty Twenty-Five.
 *
 * Só três coisas acontecem aqui: as fontes do site são enfileiradas, os tokens
 * das partes do tema viram URL, e o parent theme é carregado pelo próprio core
 * (herança de tema filho — não precisa de enqueue manual, o estilo vem todo do
 * theme.json).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Raiz da landing da A.lab.
 *
 * 🔴 Nunca monte esse link com caminho relativo puro. Em produção o blog vive
 * em /blog do mesmo domínio, então `/` funcionaria — mas em desenvolvimento o
 * WordPress É a raiz, e todo link para a landing cairia num 404 do próprio
 * WordPress. `ALAB_APP_URL` deixa os dois ambientes testáveis com o mesmo
 * markup: vazio em produção, apontando para a landing local em dev.
 */
function alab_url_do_app(): string
{
    $url = defined('ALAB_APP_URL') ? (string) ALAB_APP_URL : '';

    return rtrim($url, '/');
}

/**
 * Tokens nas partes do tema.
 *
 * As partes são HTML estático (`parts/*.html`), e HTML estático não chama PHP.
 * O token resolve isso sem transformar o tema num tema PHP inteiro: uma
 * substituição no bloco já renderizado, com a URL vindo de uma fonte só.
 *
 * `str_contains` antes do `strtr` porque este filtro roda em TODO bloco de
 * TODA página — e a esmagadora maioria não tem token nenhum.
 */
add_filter('render_block', function ($html) {
    if (!is_string($html) || !str_contains($html, '{{')) {
        return $html;
    }

    return strtr($html, [
        '{{APP_URL}}' => alab_url_do_app(),
        '{{BLOG_URL}}' => home_url('/'),
        '{{ANO}}' => wp_date('Y'),
    ]);
});

/**
 * Fontes — as mesmas da landing, para o blog não parecer outro site.
 *
 * Vêm do Google Fonts, como na landing. É uma dependência de terceiro no
 * caminho de render: se ela cair, o fallback do theme.json (system-ui e
 * ui-monospace) assume e a página continua legível.
 */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'alab-fontes',
        'https://fonts.googleapis.com/css2'
            . '?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700'
            . '&family=Inter+Tight:wght@400;500;600'
            . '&family=JetBrains+Mono:wght@400;500'
            . '&display=swap',
        [],
        null
    );
});

/**
 * Favicon.
 *
 * O WordPress só emite `<link rel="icon">` quando existe um Site Icon no
 * banco. Sem ele o navegador cai no fallback `/favicon.ico` — que é a RAIZ do
 * domínio, ou seja a landing, que não tem esse arquivo. Resultado: 404 no
 * console de toda página do blog, medido no navegador.
 *
 * Reaproveita o `icon.svg` da landing pela mesma URL declarada do resto do
 * tema, em vez de caminho relativo: em desenvolvimento a raiz é o próprio
 * WordPress e o arquivo não existe lá.
 */
add_action('wp_head', function (): void {
    // Site Icon definido no painel já emite os links dele — não duplicar.
    if (has_site_icon()) {
        return;
    }

    printf(
        '<link rel="icon" href="%s/icon.svg" type="image/svg+xml">' . "\n",
        esc_url(alab_url_do_app())
    );
}, 1);

add_filter('wp_resource_hints', function (array $urls, string $relation): array {
    if ($relation === 'preconnect') {
        $urls[] = 'https://fonts.googleapis.com';
        $urls[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => ''];
    }

    return $urls;
}, 10, 2);
