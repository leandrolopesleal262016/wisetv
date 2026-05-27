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
3. Ajuste `APP_PORT` e `APP_DATA_DIR=/srv/wisetv/data`.
4. Rode `./deploy/bootstrap.sh`.
5. Rode `docker compose up -d --build`.

## Atualização

```bash
cd /srv/wisetv/app
./deploy/deploy.sh
```

Esse fluxo atualiza o código via `git pull`, preserva dados persistentes e recria o container.
