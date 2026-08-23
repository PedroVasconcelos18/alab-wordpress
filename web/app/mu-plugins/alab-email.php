<?php

/*
Plugin Name:  A.lab — e-mail por SMTP
Description:  Manda o wp_mail() pelo SMTP do Resend, com a credencial vindo do ambiente.
Version:      1.0.0
License:      Proprietary
*/

/**
 * 🔴 Sem isto, `wp_mail()` devolve `false` calado.
 *
 * A imagem não tem MTA e o `sendmail_path` do PHP aponta para um binário que
 * não existe. O WordPress não avisa: o cadastro "funciona", o leitor nunca
 * recebe a senha, e a moderação nunca chega.
 *
 * Vinte linhas em vez de um plugin de SMTP, porque plugin de SMTP guarda
 * credencial no BANCO por um assistente no painel — e este projeto trata
 * credencial como ambiente (ver `config/application.php`).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configurado só quando há senha E remetente. Faltando um dos dois, não
 * assumimos o transporte: melhor o PHP falhar do jeito conhecido do que este
 * arquivo falhar de um jeito novo.
 */
function alab_smtp_configurado(): bool
{
    return defined('ALAB_SMTP_SENHA') && ALAB_SMTP_SENHA !== ''
        && defined('ALAB_EMAIL_REMETENTE') && ALAB_EMAIL_REMETENTE !== '';
}

add_action('phpmailer_init', function ($phpmailer): void {
    if (!alab_smtp_configurado()) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host = ALAB_SMTP_HOST;
    $phpmailer->Port = ALAB_SMTP_PORTA;
    $phpmailer->SMTPAuth = true;
    $phpmailer->Username = ALAB_SMTP_USUARIO;
    $phpmailer->Password = ALAB_SMTP_SENHA;

    // 465 é SMTPS (TLS desde o primeiro byte); 587 é STARTTLS. Trocar a porta
    // sem trocar isto dá timeout, não erro de autenticação — e timeout é bem
    // mais difícil de diagnosticar.
    $phpmailer->SMTPSecure = ALAB_SMTP_PORTA === 465 ? 'ssl' : ALAB_SMTP_SEGURANCA;
});

/**
 * Remetente.
 *
 * O padrão do WordPress é `wordpress@<host>`, que num domínio verificado no
 * Resend é recusado — e a recusa acontece no ENVIO, não na configuração.
 */
add_filter('wp_mail_from', function ($remetente) {
    return alab_smtp_configurado() ? ALAB_EMAIL_REMETENTE : $remetente;
});

add_filter('wp_mail_from_name', function ($nome) {
    return alab_smtp_configurado() ? ALAB_EMAIL_REMETENTE_NOME : $nome;
});

/**
 * Falha de envio no log do container.
 *
 * `wp_mail()` devolve `false` e segue a vida — quem chamou raramente checa. Sem
 * este gancho, "o e-mail não chegou" não deixa rastro em lugar nenhum, e é o
 * tipo de bug que se descobre por reclamação de usuário.
 */
add_action('wp_mail_failed', function ($erro): void {
    if (is_wp_error($erro)) {
        error_log('alab-email: envio falhou — ' . $erro->get_error_message());
    }
});
