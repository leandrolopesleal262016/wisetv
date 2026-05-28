# Deploy na VPS com Docker

## Estrutura recomendada na VPS

```text
/srv/wisetv/
  app/    -> clone do repositorio Git
  data/   -> midias, playlists, status, logs e devices persistentes
```

O repositorio nao deve mais carregar dados operacionais reais. O codigo fica em `app/` e os dados persistentes ficam em `data/`.

## Primeiro deploy

1. Clone o repositorio em `/srv/wisetv/app`.
2. Copie `.env.example` para `.env`.
3. Ajuste `APP_PORT`, `APP_DATA_DIR=/srv/wisetv/data`, `TRAEFIK_HOST=tv.seudominio.com` e `WISETV_PUBLIC_BASE_URL=https://tv.seudominio.com`.
4. Se for usar Fully Cloud, preencha tambem `WISETV_FULLY_CLOUD_API_EMAIL`, `WISETV_FULLY_CLOUD_API_KEY` e mantenha `WISETV_FULLY_CLOUD_API_BASE` no padrao oficial.
5. Rode `./deploy/bootstrap.sh`.
6. Rode `docker compose up -d --build`.

## Atualizacao

```bash
cd /srv/wisetv/app
./deploy/deploy.sh
```

Esse fluxo atualiza o codigo via `git pull`, preserva os dados persistentes e recria o container.

## Seeds limpos

O diretorio `seed/` existe apenas para inicializacao limpa do storage quando o volume ainda esta vazio. Ele nao deve conter playlists reais, logs, devices reais nem midias de clientes.
