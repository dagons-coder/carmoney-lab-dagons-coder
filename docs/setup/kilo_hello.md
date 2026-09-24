# Kilo: первый ответ

готов

1) Это учебный сервис `carmoney-lab` — предварительная оценка заявки на заём под ПТС: принимает заявку (VIN, год, пробег, оценочная стоимость, сумма, срок), считает LTV и возвращает решение `approve` / `review` / `reject`. Все данные синтетические.
2) В Makefile: `make up` (docker compose up -d --build, сервис на http://localhost:8080), `make down`, `make ps`, `make logs`, `make install` (composer install), `make test` (PHPUnit — локально или в контейнере backend), `make lint` (php -l по backend/ и tests/), `make seed` (залить db/seed.sql в уже поднятую MySQL), `make help`. В docker-compose.yml сервисы `backend` (PHP-Slim на 8080, маунты backend/, frontend/, tests/) и `db` (mysql:8.0, схема и сид из db/ накатываются на пустом томе, healthcheck mysqladmin ping).
3) Решение approve / review / reject считается в `backend/src/Domain/` — там DecisionEngine.php, LtvCalculator.php, AssessmentService.php, ApplicationValidator.php, VehicleAge.php, VinValidator.php; пороги и лимиты берутся из `backend/config/rules.php`.

модель: stg-proxy/training-2026-09-minimax-m3 (MiniMax-M3)
