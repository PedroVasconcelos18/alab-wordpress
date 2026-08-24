<?php

/*
Plugin Name:  A.lab — telas de acesso
Description:  Veste o wp-login.php com a identidade da A.lab: cadastro, login, senha.
Version:      1.0.0
License:      Proprietary
*/

/**
 * O `wp-login.php` NÃO passa pelo tema.
 *
 * Ele é um arquivo do core, com CSS próprio, e ignora o `theme.json` inteiro.
 * Por isso o resto do blog estava com a cara da A.lab e o cadastro continuava
 * cinza, com o "W" do WordPress e botão azul — que é a primeira coisa que um
 * leitor vê quando decide comentar.
 *
 * São quatro telas no mesmo arquivo, e todas herdam daqui: login, cadastro,
 * "perdi a senha" e "definir senha". Vestir uma veste as quatro.
 *
 * Os valores são os mesmos do `lp.css` da landing — copiados, não aproximados.
 * Aqui não dá para usar as variáveis do `theme.json`: elas são emitidas junto
 * com as global styles, que não carregam nesta página.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * O símbolo da A.lab, o mesmo triângulo com o foguete do cabeçalho.
 *
 * Vai como data URI porque o `#login h1 a` do core é um `background-image`, e
 * um arquivo solto seria o único asset do projeto — o tema inteiro é sem build.
 */
function alab_acesso_simbolo(): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none">'
        . '<defs><linearGradient id="m" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="#F1F4F9"/><stop offset="55%" stop-color="#9FA9BC"/>'
        . '<stop offset="100%" stop-color="#C4CDD9"/></linearGradient></defs>'
        . '<path d="M24 4 L44 44 L4 44 Z" stroke="url(#m)" stroke-width="1.8" fill="none" stroke-linejoin="round"/>'
        . '<path d="M24 18 c-2 0 -4 2 -4 5 v6 l-3 3 v3 h14 v-3 l-3 -3 v-6 c0 -3 -2 -5 -4 -5 z" fill="url(#m)"/>'
        . '<circle cx="24" cy="22" r="1.6" fill="#0A0F1C"/></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * As mesmas fontes do resto do site. Sem isto a tela cai em system-ui e
 * destoa de tudo — e é a única página em que o leitor digita a senha, ou seja,
 * a que menos pode parecer de outro site.
 */
add_action('login_enqueue_scripts', function (): void {
    wp_enqueue_style(
        'alab-acesso-fontes',
        'https://fonts.googleapis.com/css2'
            . '?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,700'
            . '&family=Inter+Tight:wght@400;500;600'
            . '&display=swap',
        [],
        null
    );

    $simbolo = alab_acesso_simbolo();

    $css = <<<'CSS'
body.login {
    background: #0A0F1C;
    font-family: 'Inter Tight', system-ui, sans-serif;
    color: #C4CDD9;
}
body.login::before {
    content: "";
    position: fixed;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(91,180,255,0.10), transparent 70%),
        radial-gradient(ellipse 60% 40% at 80% 100%, rgba(91,180,255,0.05), transparent 70%);
}
#login { position: relative; z-index: 1; width: 340px; padding: 7% 0 4%; }

/* O símbolo, no lugar do "W" do WordPress. */
#login h1 a {
    background-size: 56px 56px;
    width: 56px;
    height: 56px;
    margin: 0 auto 14px;
}

/* A marca, em texto — o mesmo wordmark do cabeçalho do blog. */
.alab-acesso-marca {
    text-align: center;
    font-family: 'Bricolage Grotesque', serif;
    font-weight: 700;
    font-size: 22px;
    letter-spacing: -0.02em;
    color: #F1F4F9;
    margin-bottom: 4px;
}
.alab-acesso-marca span { color: #5A6478; font-weight: 400; }
.alab-acesso-linha {
    text-align: center;
    font-size: 14px;
    color: #8B95A8;
    margin: 0 0 22px;
}

/* O formulário como cartão, igual aos da landing. */
.login form {
    background: #0F162A;
    border: 1px solid rgba(196,205,217,0.18);
    border-radius: 12px;
    box-shadow: none;
    padding: 26px 24px;
    margin-top: 0;
}
.login label, .login form .forgetmenot label {
    color: #8B95A8;
    font-size: 13px;
    font-weight: 500;
}
.login form .input,
.login input[type="text"],
.login input[type="password"],
.login input[type="email"] {
    background: #0A0F1C;
    border: 1px solid rgba(196,205,217,0.18);
    border-radius: 6px;
    color: #F1F4F9;
    font-family: 'Inter Tight', system-ui, sans-serif;
    font-size: 15px;
    padding: 10px 12px;
    box-shadow: none;
}
.login form .input:focus,
.login input[type="text"]:focus,
.login input[type="password"]:focus,
.login input[type="email"]:focus {
    border-color: #5BB4FF;
    outline: 2px solid rgba(91,180,255,0.35);
    outline-offset: 1px;
}
.login .button.wp-hide-pw { color: #8B95A8; }
.login .button.wp-hide-pw:hover { color: #F1F4F9; }

/* O botão primário da landing: gradiente prata, texto escuro. */
.login .button-primary {
    background: linear-gradient(180deg, #F1F4F9, #C4CDD9);
    border: none;
    border-radius: 6px;
    color: #0A0F1C;
    font-family: 'Inter Tight', system-ui, sans-serif;
    font-weight: 600;
    font-size: 14px;
    padding: 10px 20px;
    height: auto;
    text-shadow: none;
    box-shadow: 0 1px 0 rgba(255,255,255,0.4) inset, 0 12px 32px -12px rgba(196,205,217,0.4);
    transition: transform .2s, box-shadow .2s;
}
.login .button-primary:hover { transform: translateY(-1px); color: #0A0F1C; }

/* Avisos e erros. */
.login .message, .login .notice, .login #login_error {
    background: #0F162A;
    border: 1px solid rgba(196,205,217,0.18);
    border-left: 3px solid #5BB4FF;
    border-radius: 8px;
    color: #C4CDD9;
    box-shadow: none;
}
.login #login_error { border-left-color: #F2B33D; }

/* Links de rodapé. */
.login #nav, .login #backtoblog { padding: 14px 0 0; text-align: center; }
.login #nav a, .login #backtoblog a { color: #8B95A8; font-size: 13px; }
.login #nav a:hover, .login #backtoblog a:hover { color: #F1F4F9; }
.login .privacy-policy-page-link a { color: #5A6478; }

/* Medidor de força da senha, na paleta. */
.login #pass-strength-result {
    background: #131B33;
    border: 1px solid rgba(196,205,217,0.18);
    border-radius: 6px;
    color: #C4CDD9;
}
.login #pass-strength-result.short  { border-color: #F2B33D; }
.login #pass-strength-result.bad    { border-color: #F2B33D; }
.login #pass-strength-result.good   { border-color: #5BB4FF; }
.login #pass-strength-result.strong { border-color: #6FE3B6; }

/* O "Lembrar-me" fica branco puro sem isto — o único ponto claro da tela. */
.login input[type="checkbox"] {
    accent-color: #5BB4FF;
    background: #0A0F1C;
    border: 1px solid rgba(196,205,217,0.18);
    border-radius: 3px;
    width: 16px;
    height: 16px;
}
.login input[type="checkbox"]:checked::before { content: none; }

/* O seletor de idioma sai: o site é pt_BR, e a escolha aqui só confunde. */
.login .language-switcher { display: none; }
CSS;

    wp_add_inline_style('alab-acesso-fontes', $css);
    wp_add_inline_style(
        'alab-acesso-fontes',
        sprintf('#login h1 a { background-image: url("%s"); }', $simbolo)
    );
});

/**
 * Favicon nas telas de acesso.
 *
 * 🔴 O `wp-login.php` NÃO dispara `wp_head` — ele tem o `login_head`. Então o
 * ícone que o tema declara não vale aqui, e o navegador cai no `/favicon.ico`
 * da raiz do domínio, que é a landing e não tem esse arquivo: 404 no console
 * de toda tela de acesso. É o mesmo bug que já foi corrigido no tema, num
 * gancho que ninguém lembra que existe.
 */
add_action('login_head', function (): void {
    $app = defined('ALAB_APP_URL') ? rtrim((string) ALAB_APP_URL, '/') : '';

    printf('<link rel="icon" href="%s/icon.svg" type="image/svg+xml">' . "\n", esc_url($app));
});

/**
 * O logo aponta para a landing, não para o wp-admin.
 *
 * O padrão do WordPress manda para wordpress.org — link para fora do site, na
 * página em que o leitor está prestes a digitar uma senha.
 */
add_filter('login_headerurl', function () {
    $app = defined('ALAB_APP_URL') && ALAB_APP_URL !== '' ? ALAB_APP_URL : home_url('/');

    return $app;
});

add_filter('login_headertext', fn () => 'A.lab');

/**
 * Marca e uma linha que diz onde a pessoa está.
 *
 * Cada ação tem a sua: quem chega em "definir senha" vindo do e-mail não está
 * fazendo a mesma coisa que quem clicou em "cadastre-se".
 */
add_filter('login_message', function (string $mensagem): string {
    $acao = $_GET['action'] ?? 'login';

    $linhas = [
        'register' => 'Crie sua conta para comentar e curtir.',
        'lostpassword' => 'Informe seu e-mail para receber o link de acesso.',
        'rp' => 'Escolha uma senha para entrar.',
        'resetpass' => 'Escolha uma senha para entrar.',
        'login' => 'Entre para comentar e curtir.',
    ];

    $linha = $linhas[$acao] ?? $linhas['login'];

    // O core mostra "Cadastre-se nesse site" acima do formulário, que é a
    // mesma frase da linha acima em caixa de aviso — dois avisos dizendo o
    // mesmo. Sai só ele; qualquer outra mensagem (como "verifique seu e-mail"
    // depois do cadastro) é informação real e continua.
    //
    // ⚠️ No WordPress 7.0 isto é `<div class="notice notice-info message
    // register">`, não o `<p class="message register">` das versões antigas.
    // Casar as duas formas, e pela classe e não pela posição, porque a
    // marcação de aviso do core já mudou uma vez e vai mudar de novo.
    $mensagem = preg_replace(
        '#<(p|div)[^>]*class="[^"]*\bmessage register\b[^"]*"[^>]*>.*?</\1>\s*#s',
        '',
        $mensagem
    );

    return '<div class="alab-acesso-marca">A.lab<span> /tech</span></div>'
        . '<p class="alab-acesso-linha">' . esc_html($linha) . '</p>'
        . $mensagem;
});

/**
 * Título da aba sem "‹ … — WordPress" pendurado.
 */
add_filter('login_title', fn ($titulo) => 'Acesso — A.lab');

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * O leitor nunca vê o wp-admin.
 *
 * 🔴 O padrão do WordPress joga QUALQUER usuário autenticado no painel. Sem
 * `redirect_to`, um assinante recém-cadastrado cai em `wp-admin/profile.php` —
 * uma tela de administração do WordPress, em cima de um blog que ele só queria
 * comentar. Foi o que aconteceu no primeiro cadastro de verdade.
 *
 * São QUATRO portas, e fechar só a primeira não resolve: o redirecionamento do
 * login, a URL digitada à mão, a barra preta no topo do site, e a tela de
 * perfil. Abaixo, uma por uma.
 *
 * A linha de corte é `edit_posts`: assinante não tem, autor e acima têm. Não
 * uso `is_admin()` como papel nem lista de nomes — capacidade é o que o
 * WordPress usa para decidir tudo o mais.
 * ─────────────────────────────────────────────────────────────────────────────
 */

function alab_acesso_e_leitor(): bool
{
    return is_user_logged_in() && !current_user_can('edit_posts');
}

/**
 * 1. Depois do login, vai para o blog — não para o painel.
 *
 * Um `redirect_to` pedido explicitamente continua valendo: é o que faz o
 * "Curtir" deslogado levar ao login e VOLTAR para o post. Só é descartado
 * quando aponta para dentro do wp-admin, que é o caso que estamos consertando.
 */
add_filter('login_redirect', function ($destino, $pedido, $usuario) {
    if (!$usuario instanceof WP_User || user_can($usuario, 'edit_posts')) {
        return $destino;
    }

    if (is_string($pedido) && $pedido !== '' && !str_contains($pedido, '/wp-admin')) {
        return $pedido;
    }

    return home_url('/');
}, 10, 3);

/**
 * 2. A URL digitada à mão.
 *
 * Sem isto, o item 1 é decoração: basta escrever /wp-admin para entrar.
 *
 * ⚠️ `admin-ajax.php` fica de fora. Ele mora dentro de wp-admin mas é o
 * endpoint que o FRONTEND usa — barrá-lo quebraria funcionalidade de leitor
 * sem que nada indicasse o motivo.
 */
add_action('admin_init', function (): void {
    if (!alab_acesso_e_leitor() || wp_doing_ajax()) {
        return;
    }

    wp_safe_redirect(home_url('/'));
    exit;
});

/**
 * 3. A barra preta do WordPress no topo do site.
 *
 * Para quem não administra nada, ela só entrega que o site é WordPress e
 * oferece atalhos para telas que o item 2 acabou de fechar.
 */
add_filter('show_admin_bar', function ($mostrar) {
    return alab_acesso_e_leitor() ? false : $mostrar;
});

/**
 * 4. O perfil, que é o destino mais provável de um link antigo.
 *
 * ⚠️ Consequência assumida: o leitor não troca a própria senha logado, porque
 * essa tela é a de perfil. O caminho passa a ser "Perdeu a senha?", que manda
 * o link por e-mail e funciona. É a troca que o pedido implica — não mostrar
 * tela de administração a quem não administra.
 */
add_filter('edit_profile_url', function ($url) {
    return alab_acesso_e_leitor() ? home_url('/') : $url;
});

/**
 * 5. "Conectado como X. Edite seu perfil. Sair?" — sai o meio.
 *
 * O core monta essa linha no formulário de comentário com um link para o
 * perfil. Depois do item 4 esse link é um BECO: leva ao `profile.php`, que
 * devolve o leitor para o blog. Oferecer um caminho que não vai a lugar nenhum
 * é pior do que não oferecer.
 *
 * Substituo a linha inteira em vez de recortar pedaço dela: o texto do core é
 * traduzido e muda entre versões, então qualquer tentativa de remover por
 * `str_replace` ou regex quebraria calada numa atualização — foi exatamente o
 * que aconteceu com o aviso do cadastro. Montando o markup aqui, o que aparece
 * é o que está escrito neste arquivo.
 *
 * "Sair" fica: deslogar é coisa legítima de leitor.
 */
add_filter('comment_form_logged_in', function ($padrao, $comentarista, $identidade) {
    if (current_user_can('edit_posts')) {
        return $padrao;
    }

    return sprintf(
        '<p class="logged-in-as">Conectado como <strong>%s</strong>. <a href="%s">Sair</a></p>',
        esc_html($identidade),
        esc_url(wp_logout_url(get_permalink()))
    );
}, 10, 3);
