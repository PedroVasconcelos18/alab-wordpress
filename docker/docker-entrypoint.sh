#!/usr/bin/env bash
set -euo pipefail

# ─────────────────────────────────────────────────────────────────────────────
# O container é descartável. Estas duas coisas NÃO podem ser:
#
#   /data/database  → o banco SQLite inteiro
#   /data/uploads   → as imagens dos posts
#
# 🔴 Se o volume do Railway não estiver montado em /data, cada deploy sobe um
# site em branco — sem erro, sem log, sem nada que pareça quebrado. É o modo
# de falha mais perigoso deste arquivo, e por isso ele aborta abaixo em vez de
# seguir bonito.
# ─────────────────────────────────────────────────────────────────────────────

DADOS="${ALAB_DATA_DIR:-/data}"

if [ ! -d "$DADOS" ]; then
    echo "ERRO: $DADOS não existe. O volume do Railway não está montado." >&2
    echo "      Sem ele, banco e uploads morrem a cada deploy." >&2
    exit 1
fi

mkdir -p "$DADOS/database" "$DADOS/uploads"

# Uploads: o WordPress escreve em web/app/uploads por convenção do Bedrock.
# Em vez de reconfigurar o WordPress (que espalharia caminho absoluto pelo
# banco, quebrando na próxima mudança de host), apontamos o caminho para o
# volume. As URLs continuam relativas e portáveis.
rm -rf /app/web/app/uploads
ln -sfn "$DADOS/uploads" /app/web/app/uploads

chown -R www-data:www-data "$DADOS/database" "$DADOS/uploads"

# O Railway injeta PORT e espera que o processo escute nela.
PORTA="${PORT:-8080}"
sed -i "s/PORTA_DO_RAILWAY/${PORTA}/" /etc/apache2/sites-available/000-default.conf
sed -i "s/^Listen .*/Listen ${PORTA}/" /etc/apache2/ports.conf

echo "alab-blog: docroot=/app/web  porta=${PORTA}  dados=${DADOS}"
echo "alab-blog: banco=${DB_DIR:-$DADOS/database/}${DB_FILE:-.ht.sqlite}"

# ─────────────────────────────────────────────────────────────────────────────
# Um MPM, conferido na subida.
#
# O Dockerfile ja desabilita `mpm_event`/`mpm_worker`, e a imagem construida
# local em amd64 sai correta — `apache2ctl -M` lista so `mpm_prefork`. Ainda
# assim o container no Railway subiu com os dois habilitados e o Apache abortou
# com `AH00534: More than one MPM loaded`, em loop de restart.
#
# De onde vem o segundo nunca ficou provado: build e imagem estao certos, o
# runtime nao. Como o custo de conferir aqui e uma listagem de diretorio, e o
# custo de nao conferir e o site fora do ar, a verificacao fica na subida.
# ─────────────────────────────────────────────────────────────────────────────
habilitados=$(ls /etc/apache2/mods-enabled/ 2>/dev/null | grep -c 'mpm_.*\.load' || true)

if [ "$habilitados" -gt 1 ]; then
    echo "alab-blog: ATENCAO — $habilitados MPMs habilitados, corrigindo:" >&2
    ls /etc/apache2/mods-enabled/ | grep 'mpm_' >&2
    a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true
    a2enmod mpm_prefork >/dev/null 2>&1 || true
    echo "alab-blog: agora: $(ls /etc/apache2/mods-enabled/ | grep 'mpm_.*\.load' | tr '\n' ' ')" >&2
fi

# ─────────────────────────────────────────────────────────────────────────────
# Provisionamento inicial — OPT-IN, e de proposito.
#
# Roda so quando ALAB_PROVISIONAR=1. Depois do primeiro boot, remova a
# variavel.
#
# 🔴 Por que nao "instala sozinho se o banco nao existir": porque banco ausente
# tem duas causas — primeiro boot, e perda do volume. Instalar automaticamente
# nas duas trata catastrofe como rotina: o site volta no ar vazio, com 200 em
# tudo, e ninguem e avisado de que o conteudo sumiu. O flag separa as duas.
# ─────────────────────────────────────────────────────────────────────────────
WP="wp --path=/app/web/wp --allow-root"
BANCO="${DB_DIR:-$DADOS/database/}${DB_FILE:-.ht.sqlite}"

if [ "${ALAB_PROVISIONAR:-}" = "1" ]; then
    if $WP core is-installed 2>/dev/null; then
        echo "alab-blog: WordPress ja instalado"
    else
        echo "alab-blog: instalando WordPress..."
        $WP core install \
            --url="${WP_HOME}" \
            --title="${ALAB_TITULO:-A.lab}" \
            --admin_user="${ALAB_ADMIN_USER:?defina ALAB_ADMIN_USER}" \
            --admin_password="${ALAB_ADMIN_SENHA:?defina ALAB_ADMIN_SENHA}" \
            --admin_email="${ALAB_ADMIN_EMAIL:?defina ALAB_ADMIN_EMAIL}" \
            --skip-email
    fi

    # Daqui para baixo e idempotente: ativar o que ja esta ativo e no-op.
    $WP theme activate alab || true

    # ⚠️ UM PLUGIN POR COMANDO. Ativar os dois de uma vez falha silenciosamente
    # — o WP-CLI aborta no meio e o segundo nunca ativa. Aconteceu local e de
    # novo no Railway: o tema subiu, o Rank Math nao, e a unica pista era a
    # ausencia do namespace `rankmath` no /wp-json.
    $WP plugin activate sqlite-database-integration || true
    $WP plugin activate seo-by-rank-math || true

    # ⚠️ Rank Math ANTES do flush: as regras do sitemap sao registradas na
    # ativacao. Invertendo, sitemap_index.xml da 404 sem nada indicar o motivo.
    $WP rewrite structure '/%postname%' --hard || true
    $WP rewrite flush --hard || true

    chown -R www-data:www-data "$DADOS"

    echo "alab-blog: provisionado. Plugins ativos:"
    $WP plugin list --status=active --field=name 2>/dev/null | sed 's/^/  - /'
    echo "alab-blog: REMOVA ALAB_PROVISIONAR das variaveis."
fi

# ─────────────────────────────────────────────────────────────────────────────
# Idioma do site — roda em todo boot, e nao esta no bloco de provisionamento
# de proposito: o site ja existia em ingles quando isto foi escrito.
#
# 🔴 Definir WPLANG no wp-config NAO funciona, e o motivo esta no core:
#
#     $db_locale = get_option( 'WPLANG' );
#     if ( false !== $db_locale ) { $locale = $db_locale; }
#
# E `false !==`, nao `if ( $db_locale )`. A opcao EXISTE desde a instalacao,
# com valor vazio — entao ela sobrescreve a constante com '' e o site volta
# para en_US. A constante so valeria se a linha nao existisse no banco.
#
# Pelo painel tambem nao dava: com DISALLOW_FILE_MODS o dropdown lista so
# idioma ja instalado, e o WordPress nao pode baixar. Os .mo agora vem na
# imagem (ver Dockerfile); falta apontar a opcao, uma vez.
#
# So age quando a opcao esta VAZIA. Trocar o idioma pelo painel continua
# valendo e nao e revertido no proximo deploy.
#
# O teste de arquivo no banco nao e zelo: sem ele, um volume perdido faria o
# wp-cli criar um SQLite vazio aqui, e a catastrofe voltaria ao ar como site em
# branco com 200 em tudo — exatamente o que o resto deste arquivo evita.
# ─────────────────────────────────────────────────────────────────────────────
if [ -n "${ALAB_LOCALE:-}" ] && [ -f "$BANCO" ]; then
    atual=$($WP option get WPLANG 2>/dev/null || true)

    if [ -z "$atual" ]; then
        echo "alab-blog: idioma vazio, definindo ${ALAB_LOCALE}"
        $WP option update WPLANG "${ALAB_LOCALE}" || true
    else
        echo "alab-blog: idioma ja definido (${atual})"
    fi

    # ── Formato de data e hora ───────────────────────────────────────────────
    #
    # Trocar o idioma NAO conserta isto, e o resultado meio-traduzido e pior
    # que o ingles inteiro: "agosto 8, 2026" — mes em portugues, gramatica em
    # ingles.
    #
    # A causa: o instalador grava `__('F j, Y')` e `__('g:i a')` como default,
    # traduzidos pelo locale ATIVO NA HORA DA INSTALACAO. Este site foi
    # instalado em ingles, entao ficaram os formatos ingleses gravados no
    # banco, e nenhuma troca de idioma depois mexe neles.
    #
    # Perguntar ao proprio WordPress qual e o formato do locale e exatamente o
    # que o instalador faria — nao ha nada especifico de pt_BR escrito aqui.
    #
    # So troca o que ainda for o default ingles: formato ajustado no painel e
    # decisao de quem ajustou.
    for par in "date_format:F j, Y" "time_format:g:i a"; do
        chave="${par%%:*}"
        padrao="${par#*:}"

        if [ "$($WP option get "$chave" 2>/dev/null || true)" = "$padrao" ]; then
            traduzido=$($WP eval "echo __('${padrao}');" 2>/dev/null || true)

            if [ -n "$traduzido" ] && [ "$traduzido" != "$padrao" ]; then
                echo "alab-blog: ${chave} -> ${traduzido}"
                $WP option update "$chave" "$traduzido" || true
            fi
        fi
    done

    # ── Categoria padrao ─────────────────────────────────────────────────────
    #
    # Mesma familia dos formatos de data: o instalador cria o termo com
    # `__('Uncategorized')`, traduzido pelo locale da instalacao. Virou texto no
    # banco, entao trocar o idioma nao mexe nele — e ele aparece embaixo do
    # titulo de TODO post ("Escrito por pedro em Uncategorized").
    #
    # So renomeia se ainda estiver exatamente como o instalador ingles deixou.
    # O slug NAO muda: ele esta em URL de arquivo, e renomear quebraria link.
    padrao_id=$($WP option get default_category 2>/dev/null || true)

    if [ -n "$padrao_id" ]; then
        nome=$($WP term get category "$padrao_id" --field=name 2>/dev/null || true)

        if [ "$nome" = "Uncategorized" ]; then
            traduzido=$($WP eval "echo __('Uncategorized');" 2>/dev/null || true)

            if [ -n "$traduzido" ] && [ "$traduzido" != "Uncategorized" ]; then
                echo "alab-blog: categoria padrao -> ${traduzido}"
                $WP term update category "$padrao_id" --name="$traduzido" || true
            fi
        fi
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
# Fuso horario.
#
# Decisao declarada, nao derivada do idioma: pt_BR nao implica Sao Paulo, e o
# default do WordPress e UTC. Sem isto, a hora de publicacao de todo post sai
# tres horas adiantada.
#
# Mesma regra do resto: so age quando esta vazio.
# ─────────────────────────────────────────────────────────────────────────────
if [ -n "${ALAB_TIMEZONE:-}" ] && [ -f "$BANCO" ]; then
    if [ -z "$($WP option get timezone_string 2>/dev/null || true)" ]; then
        echo "alab-blog: fuso vazio, definindo ${ALAB_TIMEZONE}"
        $WP option update timezone_string "${ALAB_TIMEZONE}" || true
    fi
fi

exec "$@"
