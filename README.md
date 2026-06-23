# CRM Omnichannel Laravel 12

Production-oriented modular monolith CRM API with Sanctum, Spatie Permission, Redis queues, Laravel Reverb, Facebook/Zalo webhooks, events, listeners, jobs, observers, DTOs, actions, services and repositories.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan queue:work
php artisan reverb:start
php artisan serve
```

Use PHP 8.3 in production. The Composer constraint also allows PHP 8.2 so the project can run on local machines that have not upgraded yet. MySQL, Redis, and `BROADCAST_CONNECTION=reverb` are required.
