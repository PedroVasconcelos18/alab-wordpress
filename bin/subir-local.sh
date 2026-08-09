#!/usr/bin/env bash
#
# Sobe o blog em container local, o mais parecido possível com o Railway.
#
#   bin/subir-local.sh          # builda e sobe em http://localhost:8080
#   bin/subir-local.sh --limpo  # apaga o volume antes: volta ao primeiro boot
#
# Por que container e não `php -S`: os bugs mais caros desta imagem não
# aparecem no servidor embutido do PHP. Os dois MPMs do Apache, o Composer
# desabilitando plugins como root, o volume que não está montado, o pacote de
# idioma baixado no build — nada disso existe fora do container. `php -S` testa
# o tema; o container testa o deploy.
#
# ⚠️ As salts aqui são de DESENVOLVIMENTO e estão no arquivo de propósito, para
# o comando ser copiável. Nada nesta lista pode aparecer no Railway.

set -euo pipefail

IMAGEM=alab-blog:local
VOLUME=alab-blog-dados
PORTA=8080
raiz=$(cd "$(dirname "$0")/.." && pwd)

if [ "${1:-}" = "--limpo" ]; then
    echo "removendo o volume $VOLUME — o banco vai junto"
    docker rm -f alab-blog >/dev/null 2>&1 || true
    docker volume rm "$VOLUME" >/dev/null 2>&1 || true
fi

echo "buildando ${IMAGEM}..."
docker build -q -t "$IMAGEM" "$raiz" >/dev/null

docker rm -f alab-blog >/dev/null 2>&1 || true

# O volume é nomeado, não bind mount: é o que faz o teste de persistência valer
# alguma coisa. Derrubar o container e subir de novo tem que preservar o banco,
# igual a um redeploy no Railway.
docker volume create "$VOLUME" >/dev/null

echo "subindo…"
docker run -d --name alab-blog \
    -p "${PORTA}:${PORTA}" \
    -v "${VOLUME}:/data" \
    -e PORT="$PORTA" \
    -e DB_ENGINE=sqlite \
    -e DB_DIR=/data/database/ \
    -e WP_ENV=development \
    -e WP_HOME="http://localhost:${PORTA}" \
    -e WP_SITEURL="http://localhost:${PORTA}/wp" \
    -e ALAB_LOCALE=pt_BR \
    -e ALAB_TIMEZONE=America/Sao_Paulo \
    -e ALAB_PROVISIONAR=1 \
    -e ALAB_TITULO='A.lab (local)' \
    -e ALAB_ADMIN_USER=pedro \
    -e ALAB_ADMIN_SENHA=local-so-para-desenvolvimento \
    -e ALAB_ADMIN_EMAIL=dev@localhost \
    -e AUTH_KEY=dev-auth-key \
    -e SECURE_AUTH_KEY=dev-secure-auth-key \
    -e LOGGED_IN_KEY=dev-logged-in-key \
    -e NONCE_KEY=dev-nonce-key \
    -e AUTH_SALT=dev-auth-salt \
    -e SECURE_AUTH_SALT=dev-secure-auth-salt \
    -e LOGGED_IN_SALT=dev-logged-in-salt \
    -e NONCE_SALT=dev-nonce-salt \
    "$IMAGEM" >/dev/null

printf 'esperando responder'
for _ in $(seq 1 60); do
    if [ "$(curl -sI --max-time 3 -o /dev/null -w '%{http_code}' "http://localhost:${PORTA}/" || true)" = "200" ]; then
        echo
        echo
        echo "  http://localhost:${PORTA}/            o blog"
        echo "  http://localhost:${PORTA}/wp/wp-admin  painel (pedro / local-so-para-desenvolvimento)"
        echo
        echo "  bin/verificar-blog.sh http://localhost:${PORTA}"
        echo "  docker logs -f alab-blog"
        echo
        exit 0
    fi
    printf '.'
    sleep 2
done

echo
echo "não respondeu. Log:"
docker logs --tail 30 alab-blog
exit 1
