# Blog da A.lab — WordPress (Bedrock) sobre SQLite, para o Railway.
#
# Duas coisas neste arquivo não são convenção e sim requisito:
#
# 1. O docroot é `web/`, não a raiz. É assim que o Bedrock isola o core em
#    `web/wp` e mantém `vendor/`, `config/` e `.env` FORA do alcance do
#    servidor. Apontar o docroot para a raiz publicaria o `.env` com as salts.
# 2. Uploads e banco vivem no volume, nunca na imagem. Ver `docker-entrypoint.sh`.

FROM php:8.3-apache

# ext-gd: redimensionamento de imagem do WordPress (sem ela, upload de capa
#   entra sem thumbnail e sem aviso).
# ext-intl: usado pelo Rank Math na normalização de URL.
# ext-zip: instalação/atualização de plugin e tema pelo painel.
# pdo_sqlite e sqlite3 já vêm compilados na imagem oficial — é o nosso banco.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libicu-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl zip exif opcache \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# 🔴 Um MPM, explicitamente.
#
# O `php:8.3-apache` vem com `mpm_prefork` — que é o exigido pelo mod_php. Mas a
# instalação acima resolve dependências de forma diferente por arquitetura, e no
# build amd64 do Railway o `mpm_event` entrou junto. Resultado: o Apache aborta
# na subida com `AH00534: More than one MPM loaded`, e o deploy fica em loop de
# restart. **Local em arm64 o mesmo Dockerfile subia normal** — o erro só existia
# na arquitetura de produção, que é o pior tipo de diferença para descobrir tarde.
#
# Desabilitar explicitamente é determinístico: não depende de qual MPM o apt
# decidiu ativar nesta arquitetura, neste dia.
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true; \
    a2enmod mpm_prefork rewrite headers expires

# O teto que morde primeiro não é o proxy, é o PHP da origem: uma capa realista
# de 2400×1350 pesa ~9 MB, e o padrão de 2M rejeitaria o upload aqui antes de o
# proxy ver qualquer coisa.
RUN { \
      echo 'upload_max_filesize = 16M'; \
      echo 'post_max_size = 20M'; \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 120'; \
      echo 'expose_php = Off'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.validate_timestamps = 1'; \
      echo 'opcache.revalidate_freq = 2'; \
    } > /usr/local/etc/php/conf.d/alab.ini

# 🔴 `validate_timestamps = 0` era correto enquanto o código só mudava por
# deploy: container novo, opcache novo, nada a revalidar.
#
# Com a instalação pelo painel ligada o código passa a mudar EM RUNTIME, e o
# zero vira um bug difícil: atualizar um plugin pelo admin grava os arquivos
# novos no volume, a tela diz "Atualizado com sucesso", e o PHP continua
# executando a versão antiga — indefinidamente, porque nada mais reinicia o
# container. Uma atualização de segurança aplicada e não aplicada ao mesmo
# tempo, sem sintoma.
#
# Instalar um plugin NOVO funcionaria mesmo com zero (arquivo inédito não está
# no cache). É só a atualização que quebra, que é exatamente o caso em que
# ninguém desconfia.
#
# `revalidate_freq = 2` é o padrão do PHP, e a janela curta importa: durante ela
# o processo roda uma MISTURA de código velho (em cache) e novo (arquivos que
# ainda não estavam no cache). Dois segundos disso é um piscar; sessenta seriam
# tempo suficiente para um fatal error de assinatura mudada aparecer para um
# leitor de verdade.

# WP-CLI é como se opera isto no Railway: instalar, atualizar core e plugin,
# limpar cache de sitemap. Sem ele, a única via é o wp-admin pelo navegador —
# e a instalação inicial precisa acontecer antes de o wp-admin existir.
ADD --chmod=755 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar /usr/local/bin/wp

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 🔴 Sem isto o WordPress não existe, e o erro não fala em WordPress.
#
# O Composer roda como root no build de container. Nessa condição ele
# **desabilita plugins silenciosamente** ("Composer plugins have been disabled
# for safety in this non-interactive session"), e o `roots/wordpress-core-installer`
# — que é um plugin — é justamente quem move o core para `web/wp` conforme o
# `extra.wordpress-install-dir`.
#
# Resultado sem a variável: o build passa, a imagem sobe, o Apache atende, e a
# primeira requisição devolve `require(/app/web/wp/wp-blog-header.php): Failed to
# open stream`. O core foi baixado — para `vendor/roots/wordpress`, onde ninguém
# procura.
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Camada de dependências separada do código: mudar um arquivo do tema não
# reinstala o WordPress inteiro.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# Tradução continua vindo na imagem, mesmo com o painel podendo baixar.
#
# Isto foi escrito quando `DISALLOW_FILE_MODS` estava ligado e o dropdown de
# *Configurações › Geral* não tinha como oferecer pt_BR. A trava saiu, então o
# painel HOJE conseguiria instalar idioma — o entrypoint aponta
# `web/app/languages` para o volume e o download persistiria.
#
# Continua no build assim mesmo, por dois motivos que a trava não criava: o
# site precisa subir em pt_BR na PRIMEIRA requisição, antes de existir admin
# para clicar em nada; e a versão de cada pacote sai do `composer.lock`, o que
# mantém core e tradução no mesmo passo. Ver `docker/baixar-traducoes.php`.
#
# O que o painel instalar por cima vive no volume e sobrevive ao deploy — só
# não vale para os pacotes que este passo já traz, que a imagem reescreve.
ARG ALAB_LOCALE=pt_BR
RUN php docker/baixar-traducoes.php "${ALAB_LOCALE}" /app/web/app/languages /app/web

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data /app

COPY docker/apache-alab.conf /etc/apache2/sites-available/000-default.conf
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
