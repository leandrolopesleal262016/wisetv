# Deploy na VPS com Docker

## Estrutura recomendada na VPS

```text
/srv/wisetv/
  app/    -> clone do repositório Git
  data/   -> mídias, playlists, status, logs e devices persistentes
```

## Primeiro deploy

1. Clone o repositório em `/srv/wisetv/app`.
2. Copie `.env.example` para `.env`.
3. Ajuste `APP_PORT`, `APP_DATA_DIR=/srv/wisetv/data`, `TRAEFIK_HOST=tv.seudominio.com` e `WISETV_PUBLIC_BASE_URL=https://tv.seudominio.com`.
4. Se for usar Fully Cloud, preencha tambÃ©m `WISETV_FULLY_CLOUD_API_EMAIL`, `WISETV_FULLY_CLOUD_API_KEY` e mantenha `WISETV_FULLY_CLOUD_API_BASE` no padrÃ£o oficial.
5. Rode `./deploy/bootstrap.sh`.
6. Rode `docker compose up -d --build`.

## Atualização

```bash
cd /srv/wisetv/app
./deploy/deploy.sh
```

Esse fluxo atualiza o código via `git pull`, preserva dados persistentes e recria o container.
