# DDD Demo

A demonstration of Domain-Driven Design patterns: Outside pattern, CQRS-lite, Behat conventions, and quality gates.

## What this demo shows

- **Bounded contexts**: SharedKernel, User (OTP auth), Client (tenant/membership management)
- **Outside pattern**: domain reads external state without side-effects via Outside interfaces
- **CQRS-lite**: Commands for writes, DBAL Queries for reads (no ORM on the read side)
- **Quality gates**: cs-check, phpstan, deptrac-ci, phpunit, behat

## Dev (Docker + Symfony)

### Run (build if needed)
```bash
docker compose up -d --build
```
### Run (no rebuild)
```bash
docker compose up -d
```

### Smoke tests
```bash
docker compose exec php sh -lc 'cd /var/www/app && php bin/console about'
curl -i http://localhost:8080/api/health
```

### Health endpoints
 - GET /health      (nginx only)
 - GET /api/health  (Symfony via PHP-FPM)
