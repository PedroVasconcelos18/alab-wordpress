<?php

/*
Plugin Name:  A.lab — curtidas
Description:  Curtida por post, presa à conta do leitor, com endpoint REST próprio.
Version:      1.0.0
License:      Proprietary
*/

/**
 * O WordPress não tem curtida. Comentário ele tem — com moderação, spam e
 * lixeira prontos — mas curtida é código nosso.
 *
 * 🔴 Armazenamento: UMA LINHA DE META POR USUÁRIO, não um array serializado.
 *
 * O array serializado é o caminho óbvio e é uma corrida perdida: ler, somar,
 * gravar não é atômico, e duas curtidas no mesmo segundo fazem a segunda
 * apagar a primeira. Com uma linha por usuário, `add`/`delete` são operações
 * independentes e o banco resolve a concorrência. O total vira contagem de
 * linhas, e a unicidade sai de graça.
 */

if (!defined('ABSPATH')) {
    exit;
}

const ALAB_CURTIDA_META = '_alab_curtida';

/**
 * Quem curtiu este post.
 *
 * @return int[]
 */
function alab_curtidas_de(int $post): array
{
    return array_map('intval', get_post_meta($post, ALAB_CURTIDA_META));
}

function alab_curtidas_total(int $post): int
{
    return count(alab_curtidas_de($post));
}

function alab_usuario_curtiu(int $post, int $usuario): bool
{
    return $usuario > 0 && in_array($usuario, alab_curtidas_de($post), true);
}

/**
 * Endpoint.
 *
 * GET  devolve o estado. POST alterna, e exige sessão — é o ponto inteiro de
 * amarrar curtida a conta: sem isso a contagem é chute e dá para inflar.
 *
 * ⚠️ `minha` no GET só é verdadeiro se a chamada mandar o cabeçalho
 * `X-WP-Nonce`. A autenticação por cookie do REST do WordPress depende do
 * nonce; sem ele o cookie é ignorado, `get_current_user_id()` devolve 0 e a
 * resposta sai `minha: false` mesmo com sessão aberta. Medido no navegador, e
 * é armadilha para quem for consumir isto depois: um `curl` simples SEMPRE
 * devolve false. O `total` não depende disso.
 *
 * Nada aqui usa esse GET — o botão é renderizado pelo PHP, que já sabe quem é
 * o usuário. Ele existe para consulta externa.
 */
add_action('rest_api_init', function (): void {
    $rota = '/curtidas/(?P<post>\d+)';

    register_rest_route('alab/v1', $rota, [
        [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function (WP_REST_Request $req) {
                $post = (int) $req['post'];

                return alab_curtidas_resposta($post);
            },
        ],
        [
            'methods' => 'POST',
            // A checagem de nonce do REST já roda antes daqui; isto garante a
            // sessão. Deslogado recebe 401 e o botão vira link de login.
            'permission_callback' => fn () => is_user_logged_in(),
            'callback' => function (WP_REST_Request $req) {
                $post = (int) $req['post'];
                $usuario = get_current_user_id();

                if (get_post_status($post) !== 'publish') {
                    return new WP_Error('alab_post_invalido', 'Post não publicado.', ['status' => 404]);
                }

                if (alab_usuario_curtiu($post, $usuario)) {
                    delete_post_meta($post, ALAB_CURTIDA_META, $usuario);
                } else {
                    add_post_meta($post, ALAB_CURTIDA_META, $usuario);
                }

                return alab_curtidas_resposta($post);
            },
        ],
    ]);
});

function alab_curtidas_resposta(int $post): array
{
    return [
        'post' => $post,
        'total' => alab_curtidas_total($post),
        'minha' => alab_usuario_curtiu($post, get_current_user_id()),
    ];
}

/**
 * O botão, no fim do post.
 *
 * Filtro em `the_content` porque o tema é de blocos e não tem build: registrar
 * um bloco só para isto obrigaria a compilar JS, que é justamente o que o tema
 * evita. Só no post individual, só na consulta principal — sem isso o botão
 * aparece dentro de cada item da listagem e no feed RSS.
 */
add_filter('the_content', function (string $conteudo): string {
    if (!is_singular('post') || !in_the_loop() || !is_main_query() || is_feed()) {
        return $conteudo;
    }

    $post = get_the_ID();
    $total = alab_curtidas_total($post);
    $minha = alab_usuario_curtiu($post, get_current_user_id());

    if (!is_user_logged_in()) {
        // 🔴 Sem cadastro aberto, o botao vira um beco: leva a um formulario de
        // login que o leitor nao tem como preencher, porque nao existe como
        // criar conta. Melhor nao oferecer. Some sozinho quando o cadastro
        // abrir — que e quando o SMTP for configurado.
        if (!get_option('users_can_register')) {
            return $conteudo;
        }

        $login = wp_login_url(get_permalink($post));

        return $conteudo . sprintf(
            '<p class="alab-curtidas"><a class="alab-curtir alab-curtir-deslogado" href="%s">%s Curtir<span class="alab-curtir-total">%d</span></a></p>',
            esc_url($login),
            alab_curtidas_icone(),
            $total
        );
    }

    return $conteudo . sprintf(
        '<p class="alab-curtidas"><button class="alab-curtir%s" type="button" data-post="%d" aria-pressed="%s">%s<span class="alab-curtir-rotulo">%s</span><span class="alab-curtir-total">%d</span></button></p>',
        $minha ? ' alab-curtir-ativo' : '',
        $post,
        $minha ? 'true' : 'false',
        alab_curtidas_icone(),
        $minha ? 'Curtido' : 'Curtir',
        $total
    );
});

function alab_curtidas_icone(): string
{
    return '<svg class="alab-curtir-icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'
        . '<path d="M12 20s-7-4.5-7-9.5A4 4 0 0 1 12 7a4 4 0 0 1 7 3.5C19 15.5 12 20 12 20z" stroke-linejoin="round"/></svg>';
}

/**
 * O script.
 *
 * Handle registrado sem arquivo e `wp_add_inline_script`: o tema não tem build,
 * então um .js solto seria o único asset do projeto — e um a mais para versionar
 * e invalidar. São vinte linhas.
 */
add_action('wp_enqueue_scripts', function (): void {
    if (!is_singular('post') || !is_user_logged_in()) {
        return;
    }

    wp_register_script('alab-curtidas', '', [], null, true);
    wp_enqueue_script('alab-curtidas');

    wp_add_inline_script('alab-curtidas', sprintf(
        'window.alabCurtidas=%s;',
        wp_json_encode([
            'base' => esc_url_raw(rest_url('alab/v1/curtidas/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ])
    ), 'before');

    wp_add_inline_script('alab-curtidas', <<<'JS'
document.addEventListener('click', function (evento) {
    var botao = evento.target.closest('.alab-curtir[data-post]');
    if (!botao || botao.dataset.ocupado === '1') { return; }

    botao.dataset.ocupado = '1';

    fetch(window.alabCurtidas.base + botao.dataset.post, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': window.alabCurtidas.nonce }
    })
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
        .then(function (dados) {
            botao.classList.toggle('alab-curtir-ativo', dados.minha);
            botao.setAttribute('aria-pressed', dados.minha ? 'true' : 'false');
            botao.querySelector('.alab-curtir-rotulo').textContent = dados.minha ? 'Curtido' : 'Curtir';
            botao.querySelector('.alab-curtir-total').textContent = dados.total;
        })
        .catch(function () {
            // O servidor é a fonte da verdade: não mexemos no número quando a
            // chamada falha. Piscar o botão avisa sem inventar contagem.
            botao.classList.add('alab-curtir-erro');
            setTimeout(function () { botao.classList.remove('alab-curtir-erro'); }, 600);
        })
        .finally(function () { botao.dataset.ocupado = ''; });
});
JS);
});
