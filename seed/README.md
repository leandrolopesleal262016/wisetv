Runtime data was removed from the repository.

Use this directory only for clean bootstrap seeds:
- `midias/`: keep empty unless you intentionally want sample media
- `player/`: active app data templates, kept empty by default

Production and local operational data should live outside Git in:
- `.docker-data/`
- or custom paths provided via `WISETV_*` environment variables
