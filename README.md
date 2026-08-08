# alab-wordpress

Blog da A.lab: WordPress sobre [Bedrock](https://roots.io/bedrock/), banco SQLite
em arquivo, rodando no Railway e servido em `alabventure.com/blog` por um rewrite
da Vercel.

O desenho e as armadilhas estão no playbook, que fica no repo da landing:
`alab-lp/playbook-produto-em-wordpress.md`. Este README é a operação.

---

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
> bloqueio. Se um dia isso incomodar, a saída **não** é `X-Robots-Tag` por host:
> não está confirmado qual `Host` a Vercel envia no rewrite, e errar aí
> desindexaria o blog inteiro.

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

---

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
