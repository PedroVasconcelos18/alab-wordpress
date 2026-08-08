# alab-wordpress

Blog da A.lab: WordPress sobre [Bedrock](https://roots.io/bedrock/), banco SQLite
em arquivo, rodando no Railway e servido em `alabventure.com/blog` por um rewrite
da Vercel.

O desenho e as armadilhas estão no playbook, que fica no repo da landing:
`alab-lp/playbook-produto-em-wordpress.md`. Este README é a operação.

---

## No ar

| | |
| --- | --- |
| Projeto Railway | `alab` |
| Serviço | `blog`, buildando de `PedroVasconcelos18/alab-wordpress@main` |
| Origem | `blog-production-b190.up.railway.app` |
| Volume | `blog-volume` em `/data`, 5 GB |
| Réplicas | 1 (limite do SQLite, ver abaixo) |

O provisionamento rodou em 2026-08-08 e as variáveis `ALAB_PROVISIONAR`,
`ALAB_ADMIN_*` e `ALAB_TITULO` já foram removidas. Um redeploy depois disso
confirmou que o banco sobrevive: o WordPress voltou instalado e o bloco de
provisionamento não rodou de novo.

## Topologia

```text
alabventure.com/*        → landing estática (Vercel, repo alab-lp)
alabventure.com/blog/*   → este serviço (Railway)
```

O par `WP_HOME` / `WP_SITEURL` aponta para o caminho **público**, e é isso que faz
todo link, canonical, Open Graph e sitemap saírem com `alabventure.com/blog` —
mesmo quando a requisição chega pelo host do Railway.

> O host do Railway continua **acessível e servindo 200** para quem souber o
> endereço; medido aqui e confirmado na origem do clama, que está em produção. O
> que impede conteúdo duplicado é o canonical apontando para o destino, não um
> bloqueio.
>
> Se um dia isso incomodar, a saída **não** é `X-Robots-Tag` por host — e agora
> está medido por quê, não suposto. A Vercel manda no rewrite o `Host` da
> **origem**, o mesmo que uma visita direta manda. Os dois casos chegam
> idênticos: não há host para distinguir, e uma regra por host ou não pega nada
> ou desindexa o blog inteiro.

### A requisição que chega não é a que o cliente fez

Medido nas duas pontas, e é o que o `config/application.php` reconstrói:

```text
cliente  →  GET /blog/wp/wp-admin/   Host: alabventure.com
origem   ←  GET /wp/wp-admin/        Host: blog-production-….up.railway.app
```

A Vercel troca o host **e remove o prefixo `/blog`**. Tudo que passa por
`WP_HOME` sai certo sozinho; o que lê `$_SERVER` cru — `auth_redirect()`, e os
formulários do wp-admin que usam `REQUEST_URI` como action — sai errado. Os dois
valores precisam ser corrigidos juntos: consertar só o host troca o host errado
por um caminho errado.

| | |
| --- | --- |
| Core, plugins e tema-pai | Composer (`composer.json` + lock), instalados no build |
| Core em | `web/wp` (fora do repo — `wordpress-install-dir`) |
| Banco | SQLite, arquivo único em `/data/database/.ht.sqlite` |
| Uploads | `/data/uploads`, symlink de `web/app/uploads` |
| Docroot | `web/` — nunca a raiz, senão `.env`, `config/` e `vendor/` viram públicos |

---

## Rodar local

```bash
composer install
cp .env.example .env        # já existe um .env com salts de desenvolvimento
php -S localhost:8080 -t web
```

Na primeira vez, instale o WordPress e ligue tema e plugins — **um plugin por
comando**, e o Rank Math antes do flush:

```bash
wp core install --url=http://localhost:8080 --title='A.lab' \
  --admin_user=pedro --admin_password=... --admin_email=... --skip-email
wp theme activate alab
wp plugin activate sqlite-database-integration
wp plugin activate seo-by-rank-math
wp rewrite structure '/%postname%' --hard && wp rewrite flush --hard
```

Para conferir os links do tema que voltam para a landing, suba a landing junto
(`python3 -m http.server 8000 --directory ../alab-lp/public`) — o `.env` de
desenvolvimento já aponta `ALAB_APP_URL` para ela. Sem isso, esses links caem no
404 do próprio WordPress, porque em local o WordPress é a raiz.

---

## Subir no Railway (primeira vez)

A ordem importa. O passo 3 é o que não pode ser esquecido.

1. **Repo no GitHub** — `git init && git add -A && git commit`, empurra.
2. **Serviço no Railway** a partir do repo. Ele lê o `railway.json` e builda pelo
   `Dockerfile`.
3. **🔴 VOLUME montado em `/data`**, antes do primeiro deploy. Sem ele o
   container **aborta de propósito** (`docker/docker-entrypoint.sh`) — falha
   visível é melhor que site em branco a cada deploy.
4. **Variáveis**: cole `.env.railway` inteiro no *RAW Editor* do painel. Ele já
   vem com as salts de produção geradas, distintas das de desenvolvimento, e com
   `ALAB_PROVISIONAR=1`.

   Pela CLI é uma por comando (`railway variables --set 'K=V' --skip-deploys`),
   e ela **rejeita valor vazio**: `ALAB_APP_URL=` falha com erro de argumento.
   Simplesmente não defina — `config/application.php` faz
   `env('ALAB_APP_URL') ?: ''`, e ausente dá o mesmo resultado que vazio, que é
   o correto em produção.
5. **Deploy.** O entrypoint instala o WordPress, ativa tema e plugins e escreve
   os permalinks. O log termina em `alab-blog: provisionado.`
6. **Remova `ALAB_PROVISIONAR`, `ALAB_ADMIN_SENHA`, `ALAB_ADMIN_EMAIL`,
   `ALAB_ADMIN_USER` e `ALAB_TITULO`** das variáveis. Elas já cumpriram o papel;
   a senha fica no seu gerenciador, não no painel.
7. **Aponte o `/blog`**: no `alab-lp/vercel.json`, troque o host das duas regras
   pelo domínio real do serviço. Deploy da Vercel.

⚠️ **Não configure `healthcheckPath`.** O healthcheck do Railway bate em
`http://<host-interno>:$PORT/`, **com a porta explícita**, e o
`redirect_canonical` do WordPress responde **301 para a mesma URL sem a porta**.
O healthcheck trata 3xx como falha e derruba o deploy, em loop. Confira saúde por
`railway logs` (o Apache registra `AH00163` ao subir) ou pelo domínio público
depois do cutover.

> Medido nesta imagem: `http://localhost:8081/` → `301 http://localhost/`; a mesma
> requisição com `Host` sem porta → `200`, para qualquer host. O playbook atribui
> a queda do healthcheck ao `WP_HOME` público devolvendo 302 — não é o que
> acontece: a origem **serve 200** e só declara o destino no
> `<link rel="canonical">`. A regra continua valendo; a causa é a porta.

⚠️ **Uma réplica só.** O banco é um arquivo em disco local: duas réplicas
gravando corrompem. Escalar exige trocar para MariaDB antes (`DB_ENGINE=mysql`,
o `config/application.php` mantém os dois caminhos vivos).

### Depois: conferir

```bash
curl -sI https://alabventure.com/blog/            # 200, e sem host do Railway no HTML
curl -s  https://alabventure.com/blog/ | grep -o 'railway.app'   # não pode achar nada
curl -sI https://alabventure.com/blog             # sem barra, também 200
curl -s  https://alabventure.com/blog/wp-json | head -c 200      # namespaces, com rankmath
curl -sI https://alabventure.com/blog/sitemap_index.xml          # 200
```

E clique no menu, não confie só no `curl`: navegação é a coisa que passa verde no
terminal e falha no navegador.

### O que o cutover mediu

Tudo acima passou. Três coisas que só apareceram fazendo:

- **O `railway.json` aceita chave desconhecida.** As chaves-comentário `_leia`,
  `_semHealthcheck` e `_numReplicas` foram lidas e ignoradas — o deploy trouxe
  `configFile: /railway.json` e `builder: DOCKERFILE`. É o oposto do
  `vercel.json`, que falha na validação (§4.1 do playbook). A regra "comentário
  vai para a doc" não se transfere de um para o outro.
- **O conserto de MPM do entrypoint disparou.** Serviço novo, build novo, e
  ainda assim `mpm_event` e `mpm_prefork` subiram os dois. Sem a verificação na
  subida, o primeiro deploy teria entrado em loop de restart.
- **404 em `/favicon.ico` em toda página do blog**, achado no navegador e
  invisível no `curl`. O WordPress só emite `<link rel="icon">` com Site Icon no
  banco; sem ele o navegador busca na raiz do domínio, que é a landing.
  Resolvido no `functions.php`, apontando para o `icon.svg` da landing.
- **O host do Railway vazava no `redirect_to` do wp-admin** — o único lugar em
  todo o HTML servido. Foi o fio que levou à seção acima: a origem recebe host e
  caminho diferentes dos que o cliente mandou. Resolvido no
  `config/application.php`.

---

## Pendências

Nenhuma impede o blog de servir. As duas primeiras são as que importam.

### 🔴 O push no GitHub não dispara deploy

O Railway builda deste repo, mas **não existe gatilho**: `git push` não sobe
nada. O GitHub App do Railway não enxerga `alab-wordpress` — a CLI só conseguiu
conectar a origem passando `--branch` explícito, e recusa com `Unauthorized`
sem ele, que é justamente o sintoma de repo fora do alcance do App.

Enquanto não for resolvido, todo deploy é manual:

```bash
railway service redeploy --service blog --from-source --yes
```

O conserto é no GitHub, não aqui: *Settings › Applications › Railway ›
Repository access*, adicionar `alab-wordpress`. Depois disso o Railway cria o
trigger e o push volta a bastar.

### 🔴 Backup nunca foi baixado

O item mais importante do §6 do playbook, e o único do checklist ainda em
aberto. Comando na seção abaixo — rode antes de publicar qualquer conteúdo.

### O blog está em inglês

`<html lang="en-US">` e `og:locale=en_US`. Não há `WPLANG` no `.env.railway`
nem no `config/application.php`, e não há `web/app/languages` — o pacote de
idioma nunca foi instalado. Trocar pelo painel esbarra em `DISALLOW_FILE_MODS`,
e mesmo instalando, o pacote cairia na imagem e sumiria no próximo deploy.

O lugar certo é o provisionamento: `wp language core install pt_BR --activate`
no `docker-entrypoint.sh`, junto com os plugins.

### Sem `robots.txt` no domínio

`alabventure.com/robots.txt` responde 404, então o `sitemap_index.xml` do Rank
Math não é anunciado a ninguém. O arquivo pertence à **raiz do domínio**, não ao
`/blog`: `alab-lp/public/robots.txt`, com a linha
`Sitemap: https://alabventure.com/blog/sitemap_index.xml`.

### Sem tagline

`blogdescription` está vazio e o Rank Math monta o título como `A.lab -`, com o
traço solto. Preencher em *Configurações › Geral*.

### Apex e `www` discordam

`alabventure.com` responde 307 para `www.alabventure.com`, mas `WP_HOME` e o
`canonical` da landing declaram o apex. Funciona, ao custo de um redirect em
todo link interno e de um sinal ambíguo para busca — que é justamente o que a
escolha por subdiretório existe para evitar. Ver `alab-lp/README.md`.

## Backup — é seu

Volume não é backup. O arquivo **é** o banco inteiro:

```bash
railway volume files -v <volume> download /database/.ht.sqlite ./backup-$(date +%F).sqlite
```

Uploads também vivem no volume (`/data/uploads`) e não estão em lugar nenhum além
dele.

---

## Plugins

Três, e cada um tem motivo:

| Plugin | Por quê |
| --- | --- |
| `sqlite-database-integration` | elimina o serviço de banco. Feature plugin do time de Performance do WordPress, ainda marcado **"Under Development"** — é o risco assumido, e a saída é MariaDB |
| `seo-by-rank-math` | title, meta, Open Graph, JSON-LD, sitemap e redirects por configuração. `RANK_MATH_REGISTRATION_SKIP` está definido: sem ela o plugin fica ativo e não emite nada |
| `roots/bedrock-disallow-indexing` | impede que ambiente que não é produção seja indexado |

Instalar plugin pelo painel está **desligado** (`DISALLOW_FILE_MODS`): o que não
está no `composer.json` some no próximo deploy. Plugin novo entra por PR, com o
lock atualizado. E no máximo **um** plugin de segurança — Wordfence + Solid +
Patchstack juntos não somam proteção, somam superfície e conflito.

## O tema

`web/app/themes/alab` é filho do Twenty Twenty-Five. Não tem build: paleta,
tipografia e CSS vivem no `theme.json`, com as cores tiradas do `lp.css` da
landing. As partes (`parts/header.html`, `parts/footer.html`) usam os tokens
`{{APP_URL}}`, `{{BLOG_URL}}` e `{{ANO}}`, resolvidos em `functions.php` — é o que
mantém uma fonte de verdade só para a URL da landing, em produção e em dev.
