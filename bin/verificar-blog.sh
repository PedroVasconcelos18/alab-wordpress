#!/usr/bin/env bash
#
# Verifica um blog WordPress servido em subdiretório atrás de um proxy.
#
#   bin/verificar-blog.sh                                  # produção da A.lab
#   bin/verificar-blog.sh https://clama.me/blog            # o outro blog
#   bin/verificar-blog.sh http://localhost:8080 pt-BR      # o container local
#
# 🔴 SOMENTE LEITURA. Só GET e HEAD, nada de POST, nada de formulário. É por
# isso que pode rodar contra produção a qualquer hora — inclusive antes e
# depois de um deploy, que é quando vale mais.
#
# Cada teste aqui existe porque a coisa QUEBROU. Nenhum é preventivo genérico:
# a referência entre parênteses é a seção do playbook ou o incidente que o
# gerou. Se um teste começar a parecer bobo, confira o histórico antes de
# apagar.
#
# ⚠️ O que este script NÃO testa, e ninguém deveria fingir que testa:
# navegação por clique (§4.5 — `curl` fica verde enquanto o botão não sai do
# lugar) e qualquer coisa que dependa de layout. Isso é navegador. Ver a seção
# "Testar" do README.

set -uo pipefail

BASE="${1:-https://www.alabventure.com/blog}"
LOCALE="${2:-pt-BR}"

BASE="${BASE%/}"
# O prefixo público é o que o proxy remove antes de chegar na origem. É a peça
# que mais quebrou: quase todo bug desta lista nasceu de alguém montar uma URL
# sem ele.
PREFIXO=$(printf '%s' "$BASE" | sed -E 's#^https?://[^/]+##')

falhas=0
total=0

verde()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
vermelho(){ printf '  \033[31m✗\033[0m %s\n' "$1"; printf '      esperado: %s\n      obtido:   %s\n' "$2" "$3"; }

# checar <descrição> <esperado> <obtido>
checar() {
    total=$((total + 1))
    if [ "$2" = "$3" ]; then verde "$1"; else vermelho "$1" "$2" "$3"; falhas=$((falhas + 1)); fi
}

# conter <descrição> <agulha> <palheiro-descrito> <palheiro>
conter() {
    total=$((total + 1))
    case "$4" in
        *"$2"*) verde "$1" ;;
        *) vermelho "$1" "conter '$2'" "$3"; falhas=$((falhas + 1)) ;;
    esac
}

codigo()  { curl -sI --max-time 20 -o /dev/null -w '%{http_code}' "$1"; }
corpo()   { curl -s  --max-time 20 "$1"; }
destino() { curl -sI --max-time 20 -o /dev/null -w '%{redirect_url}' "$1"; }

echo
echo "verificando $BASE"
echo

# ── Roteamento ───────────────────────────────────────────────────────────────
# §4.3: a regra da borda casava sem barra e não casava com barra. O cliente
# manda das duas formas.
echo "roteamento"
checar "raiz sem barra"        200 "$(codigo "$BASE")"
checar "raiz com barra"        200 "$(codigo "$BASE/")"
checar "sitemap"               200 "$(codigo "$BASE/sitemap_index.xml")"
checar "REST"                  200 "$(codigo "$BASE/wp-json")"
checar "feed"                  200 "$(codigo "$BASE/feed")"
# Um 404 de verdade prova que o WordPress está roteando. Um 200 aqui significa
# que alguma coisa está servindo catch-all por cima dele.
checar "rota inexistente é 404" 404 "$(codigo "$BASE/rota-que-nao-existe-$$/")"

# §4.4: arquivo estático do build tem precedência sobre rewrite, e o sintoma é
# o proxy servir HTML que parece certo. O header `link: <…/wp-json/>` só existe
# se quem respondeu foi o WordPress.
cabecalhos=$(curl -sI --max-time 20 "$BASE/")
conter "quem responde é o WordPress, não um arquivo estático (§4.4)" \
       "wp-json" "cabeçalhos de $BASE/" "$cabecalhos"

# ── Identidade pública ───────────────────────────────────────────────────────
# A origem nunca deve se apresentar como origem: WP_HOME é o caminho público.
echo
echo "identidade pública"
html=$(corpo "$BASE/")

total=$((total + 1))
if printf '%s' "$html" | grep -q 'railway\.app'; then
    vermelho "sem host da origem no HTML" "nenhuma ocorrência de railway.app" \
             "$(printf '%s' "$html" | grep -o 'railway\.app' | wc -l | tr -d ' ') ocorrência(s)"
    falhas=$((falhas + 1))
else
    verde "sem host da origem no HTML"
fi

canonical=$(printf '%s' "$html" | grep -o '<link rel="canonical" href="[^"]*"' | head -1)
conter "canonical aponta para o caminho público" "$PREFIXO" "$canonical" "$canonical"

# Achado de hoje: `auth_redirect()` monta o destino com HTTP_HOST + REQUEST_URI
# crus, e o proxy entrega os dois errados — host da origem e caminho sem o
# prefixo. Login funcionava e mandava para o lugar errado depois.
# 🔴 Este teste tem que DECODIFICAR antes de julgar, e a primeira versão não
# decodificava: procurava `%2Fblog` na URL crua e passava contra o clama, onde
# o destino era `…%2F%2Fblog-production-1356.up.railway.app%2F…`. O host da
# origem começa com "blog-", então a agulha casava dentro do próprio bug.
#
# A asserção certa é uma só, e é absoluta: o destino final, decodificado,
# precisa começar pela URL pública. Isso cobre host errado e caminho sem
# prefixo de uma vez.
#
# E a régua não é a URL que se digitou na linha de comando: é o que o próprio
# WordPress declara como `home` no /wp-json. Comparar com o argumento reprova
# quem testa por `www` um site cujo WP_HOME é o apex — destino correto, host
# diferente. Isso é assunto do aviso lá embaixo, não deste teste.
home=$(corpo "$BASE/wp-json" | sed -n 's/.*"home":"\([^"]*\)".*/\1/p' | sed 's#\\/#/#g')
admin=$(destino "$BASE/wp/wp-admin/")
alvo=$(printf '%s' "$admin" | sed -n 's/.*[?&]redirect_to=\([^&]*\).*/\1/p')
alvo=$(printf '%b' "${alvo//%/\\x}")

total=$((total + 1))
case "$alvo" in
    "$home"/*) verde "redirect_to do wp-admin é uma URL pública" ;;
    *) vermelho "redirect_to do wp-admin é uma URL pública" "começar com $home/" "${alvo:-(sem redirect_to)}"; falhas=$((falhas + 1)) ;;
esac

# Aviso, não falha: o site funciona: só custa um redirect em todo link interno
# que o WordPress emite, e manda sinal ambíguo para busca — que é justamente o
# que a escolha por subdiretório existe para evitar.
anfitriao_testado=$(printf '%s' "$BASE" | sed -E 's#^https?://([^/]+).*#\1#')
anfitriao_home=$(printf '%s' "$home"   | sed -E 's#^https?://([^/]+).*#\1#')

if [ -n "$anfitriao_home" ] && [ "$anfitriao_testado" != "$anfitriao_home" ]; then
    printf '  \033[33m!\033[0m %s\n' "host testado ($anfitriao_testado) não é o que o WordPress declara como home ($anfitriao_home)"
    printf '      todo link interno vai custar um redirect\n'
fi

# ── SEO ──────────────────────────────────────────────────────────────────────
# O subdiretório existe por SEO (§1). Se estas quebram, o motivo do desenho
# inteiro foi embora.
echo
echo "SEO"
# §4.9: `wp plugin activate a b` aborta no meio, e a ausência do namespace era
# a única pista de que o Rank Math não subiu.
conter "Rank Math ativo (§4.9)" "rankmath" "namespaces do /wp-json" "$(corpo "$BASE/wp-json")"

robots=$(printf '%s' "$html" | grep -io '<meta name="robots" content="[^"]*"' | head -1)
conter "indexável (WP_ENV=production)" "index" "$robots" "$robots"

# Mesma armadilha do teste de token: "não contém noindex" é verdade numa
# página sem meta robots nenhuma. Exige a tag antes de julgar o conteúdo dela.
total=$((total + 1))
if [ -z "$robots" ]; then
    vermelho "sem noindex em produção" "uma meta robots para julgar" "nenhuma meta robots na página"
    falhas=$((falhas + 1))
else
    case "$robots" in
        *noindex*) vermelho "sem noindex em produção" "sem noindex" "$robots"; falhas=$((falhas + 1)) ;;
        *) verde "sem noindex em produção" ;;
    esac
fi

# ── Idioma ───────────────────────────────────────────────────────────────────
# A opção WPLANG do banco vence a constante do wp-config, e o formato de data
# ficou gravado em inglês na instalação. Os dois falham em silêncio.
echo
echo "idioma"
lang=$(printf '%s' "$html" | grep -o '<html lang="[^"]*"' | head -1)
conter "locale do documento" "$LOCALE" "$lang" "$lang"

# ── CSS ──────────────────────────────────────────────────────────────────────
echo
echo "css"
# Achado de hoje: o tema filho substituiu a paleta do pai, e o CSS do pai
# continuou pedindo os slugs antigos. `var()` que não resolve NÃO é erro — a
# propriedade cai para o valor herdado, calada. Deu borda branca em campo de
# formulário, hover de botão morto e anel de foco invisível.
pagina=$(corpo "$BASE/")
usados=$(printf '%s' "$pagina"  | grep -o 'var(--wp--preset--color--[a-z0-9-]*' | sed 's/.*(//' | sort -u)
definidos=$(printf '%s' "$pagina" | grep -o -- '--wp--preset--color--[a-z0-9-]*:' | sed 's/:$//' | sort -u)
mortos=$(comm -23 <(printf '%s\n' "$usados") <(printf '%s\n' "$definidos") | grep -v '^$' || true)

# 🔴 Sem esta guarda o teste passa numa página vazia — zero tokens usados são
# zero tokens mortos. Um teste que não consegue falhar mente, e este mentiu
# quando foi apontado para uma URL errada de propósito.
total=$((total + 1))
if [ -z "$usados" ]; then
    vermelho "folha de estilo do tema carregou" "tokens de cor no HTML" "nenhum — o tema não carregou"
    falhas=$((falhas + 1))
elif [ -n "$mortos" ]; then
    vermelho "nenhum token de cor morto" "0 tokens" "$(printf '%s' "$mortos" | tr '\n' ' ')"
    falhas=$((falhas + 1))
else
    verde "nenhum token de cor morto ($(printf '%s\n' "$usados" | wc -l | tr -d ' ') conferidos)"
fi

# Achado de hoje: sem Site Icon o navegador busca /favicon.ico na RAIZ do
# domínio, que é a landing. 404 no console de toda página, invisível no curl —
# a menos que se procure o link.
conter "favicon declarado" 'rel="icon"' "<head> de $BASE/" "$html"

# ── Resultado ────────────────────────────────────────────────────────────────
echo
if [ "$falhas" -eq 0 ]; then
    printf '\033[32m%s de %s passaram.\033[0m\n' "$total" "$total"
    echo
    echo "Falta o que curl não vê: abra o menu e CLIQUE (§4.5)."
    exit 0
fi

printf '\033[31m%s de %s falharam.\033[0m\n' "$falhas" "$total"
exit 1
