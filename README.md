# demo-symfony-k3s

Minimal **Symfony 8.1 / PHP 8.5** application: it shows a start page and
nothing more. It exists to prove the CI/CD platform on the Raspberry Pi k3s
cluster (`malina`).

## What it does

- `GET /` — start page (Symfony + PHP version, deployed release tag, database
  connection status).
- `GET /healthz` — `ok` (Kubernetes liveness/readiness probe).

The application is configured (via environment variables, never committed) to
connect to a PostgreSQL database deployed next to it in the `demo` namespace.

## CI/CD flow (no manual steps)

```text
merge to main (GitHub)
  └─▶ Jenkins on k3s polls the repo (≤ 1 min)
        └─▶ Kaniko builds this repo's Dockerfile (linux/arm64)
              └─▶ push 192.168.68.52:30500/demo-symfony-k3s:<short-sha> (+ :latest)
                    └─▶ Jenkins bumps image.tag in piotrwojewoda/infr-as-a-code
                          └─▶ ArgoCD syncs → https://demo.kreciowo.pl
```

Pipeline contract and platform specification:
`infr-as-a-code/specs/001-k3s-cicd-platform/`.

## Local development

```bash
composer install
docker compose up -d   # PostgreSQL from the doctrine recipe (optional)
symfony server:start   # or: php -S 127.0.0.1:8000 -t public
```

Note: the project targets PHP 8.5 (see `composer.json`). On older PHP versions
use `COMPOSER_IGNORE_PLATFORM_REQS=1 composer install`.
