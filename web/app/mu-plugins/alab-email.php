<?php

/*
Plugin Name:  A.lab — e-mail pela API do Resend
Description:  Substitui o transporte do wp_mail() pela API HTTP do Resend, na 443.
Version:      2.0.0
License:      Proprietary
*/

/**
 * 🔴 Por que API HTTP e não SMTP — medido, não preferência.
 *
 * A primeira versão usava `phpmailer_init` com o SMTP do Resend na 587. Em
 * produção, no Railway, qualquer envio PENDURAVA a requisição: 90 segundos sem
 * resposta, e o `wp_mail_failed` nunca disparava porque o envio não falha, ele
 * trava. Isso é PIOR que não ter e-mail — cadastro e recuperação de senha
 * ficariam pendurados até o `max_execution_time`.
 *
 * As duas pontas foram medidas:
 *   - `smtp.resend.com:587` conecta da máquina de desenvolvimento em 0,22s e
 *     devolve o banner. Host, porta e credencial estão certos.
 *   - Do container, não conecta.
 *
 * Ou seja: o Railway não deixa sair SMTP. Trocar de porta seria chutar; a saída
 * é o transporte que um PaaS nunca bloqueia, que é HTTPS na 443. É também o que
 * o próprio Resend recomenda.
 *
 * `pre_wp_mail` curto-circuita o `wp_mail()` inteiro: o PHPMailer nem é
 * carregado, então não há socket para pendurar.
 */

if (!defined('ABSPATH')) {
    exit;
}

const ALAB_RESEND_ENDPOINT = 'https://api.resend.com/emails';

function alab_email_configurado(): bool
{
    return defined('ALAB_RESEND_CHAVE') && ALAB_RESEND_CHAVE !== ''
        && defined('ALAB_EMAIL_REMETENTE') && ALAB_EMAIL_REMETENTE !== '';
}

/**
 * Normaliza destinatário: o WordPress aceita string com vírgulas ou array.
 *
 * @return string[]
 */
function alab_email_lista($valor): array
{
    if (is_array($valor)) {
        $itens = $valor;
    } else {
        $itens = explode(',', (string) $valor);
    }

    return array_values(array_filter(array_map('trim', $itens), 'strlen'));
}

/**
 * Cabeçalhos do wp_mail viram campos da API.
 *
 * Só o que interessa aos e-mails que este site manda: tipo do conteúdo, cópia
 * e resposta. O resto é descartado de propósito — a API tem campos nomeados, e
 * repassar cabeçalho cru abriria espaço para injeção.
 */
function alab_email_cabecalhos($headers): array
{
    $saida = ['html' => false, 'cc' => [], 'bcc' => [], 'reply_to' => []];

    foreach (alab_email_lista($headers) as $linha) {
        if (!str_contains($linha, ':')) {
            continue;
        }

        [$nome, $valor] = array_map('trim', explode(':', $linha, 2));

        switch (strtolower($nome)) {
            case 'content-type':
                $saida['html'] = str_contains(strtolower($valor), 'text/html');
                break;
            case 'cc':
                $saida['cc'] = array_merge($saida['cc'], alab_email_lista($valor));
                break;
            case 'bcc':
                $saida['bcc'] = array_merge($saida['bcc'], alab_email_lista($valor));
                break;
            case 'reply-to':
                $saida['reply_to'] = array_merge($saida['reply_to'], alab_email_lista($valor));
                break;
        }
    }

    return $saida;
}

add_filter('pre_wp_mail', function ($curto, array $atts) {
    // Sem configuração, devolve null e o WordPress segue o caminho dele — que
    // aqui não tem transporte, mas falhar do jeito conhecido é melhor do que
    // falhar de um jeito novo.
    if (!alab_email_configurado()) {
        return $curto;
    }

    $cabecalhos = alab_email_cabecalhos($atts['headers'] ?? []);
    $mensagem = (string) ($atts['message'] ?? '');

    $corpo = [
        'from' => sprintf('%s <%s>', ALAB_EMAIL_REMETENTE_NOME, ALAB_EMAIL_REMETENTE),
        'to' => alab_email_lista($atts['to'] ?? []),
        'subject' => (string) ($atts['subject'] ?? ''),
    ];

    $corpo[$cabecalhos['html'] ? 'html' : 'text'] = $mensagem;

    foreach (['cc', 'bcc', 'reply_to'] as $campo) {
        if ($cabecalhos[$campo] !== []) {
            $corpo[$campo] = $cabecalhos[$campo];
        }
    }

    // Anexo vira base64. Nenhum e-mail deste site tem anexo hoje, mas descartar
    // em silêncio seria a pior falha possível: a mensagem chega sem o arquivo e
    // ninguém percebe.
    foreach ((array) ($atts['attachments'] ?? []) as $caminho) {
        if (is_readable($caminho)) {
            $corpo['attachments'][] = [
                'filename' => basename($caminho),
                'content' => base64_encode((string) file_get_contents($caminho)),
            ];
        }
    }

    $resposta = wp_remote_post(ALAB_RESEND_ENDPOINT, [
        // Curto de propósito. O bug que este arquivo conserta era exatamente
        // uma espera sem fim; um teto baixo transforma indisponibilidade em
        // erro rápido e registrado.
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . ALAB_RESEND_CHAVE,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($corpo),
    ]);

    if (is_wp_error($resposta)) {
        error_log('alab-email: transporte falhou — ' . $resposta->get_error_message());

        return false;
    }

    $status = wp_remote_retrieve_response_code($resposta);

    if ($status < 200 || $status >= 300) {
        // O corpo do erro do Resend diz a causa real (domínio não verificado,
        // remetente recusado, chave inválida). Sem ele o log seria só "falhou".
        error_log(sprintf(
            'alab-email: Resend devolveu %d — %s',
            $status,
            wp_remote_retrieve_body($resposta)
        ));

        return false;
    }

    return true;
}, 10, 2);

/**
 * Remetente, para o resto do WordPress que consulta esses filtros.
 *
 * O padrão é `wordpress@<host>`, que num domínio verificado é recusado.
 */
add_filter('wp_mail_from', fn ($de) => alab_email_configurado() ? ALAB_EMAIL_REMETENTE : $de);
add_filter('wp_mail_from_name', fn ($nome) => alab_email_configurado() ? ALAB_EMAIL_REMETENTE_NOME : $nome);
