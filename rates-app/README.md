# Гайд

## Требования
- docker
- docker compose (с поддержкой профилей)

## Установка
#### Копируем энвы
```bash
cp .env.example .env
cp rates-app/.env.example rates-app/.env
```
корневой - для создания Postgres и rabbitmq
в rates-app с настройками приложения

### Сборка и запуск проекта
docker compose up -d --build

### Установка зависимостей
```bash
docker compose exec php composer install
```

#### Генерация ключа и миграция
```bash
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

### Доступ к приложению
http:://localhost:8079/rate

#### Пример
```http
GET /rate?date=2026-03-06&target=USD&base=EUR
```
- базовая валюта по умолчанию RUR, даже при отсутствии параметра
- дата в формате YYYY-MM-DD, не позднее сегодня (даты в будущем отсекает валидация)
- валюта - любые три латинские буквы (приводятся к верхнему регистру, пробелы отсекаются)

### Загрузка истории
#### Запуск воркера
```bash
docker compose --profile worker up -d worker
```
#### Добавить загрузку в очередь
```bash
docker compose exec php php artisan rates:fetch
```
Команда загружает данные за сегодня + 180 дней

#### Логи воркера (live)
```bash
docker compose --profile worker logs -f worker
```

## Тесты

Запустить все:

```bash
docker compose exec php ./vendor/bin/pest
```

Только юнит-тесты сервиса:

```bash
docker compose exec php ./vendor/bin/pest tests/Unit/RatesServiceTest.php
```

Только функциональные тесты:

```bash
docker compose exec php ./vendor/bin/pest tests/Feature
```

## Остановка

```bash
docker compose --profile worker down
```
