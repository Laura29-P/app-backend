<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>

## Backend de Aventura de Aprendizaje

Este servicio guarda el progreso de cada navegador de la aplicación React.

La autenticación está disponible en `POST /api/auth/register` y `POST /api/auth/login`. Las contraseñas se almacenan usando el cast `hashed` de Laravel y las respuestas entregan un token bearer; la contraseña nunca vuelve al frontend.

### Puesta en marcha

```bash
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

El frontend debe usar `VITE_API_URL=http://localhost:8000/api`.

Si checkout devuelve `502` indicando un certificado CA, configura PHP para validar HTTPS antes de iniciar el servidor. Descarga el bundle CA oficial de cURL desde <https://curl.se/ca/cacert.pem> y añade estas líneas al `php.ini` que muestra `php --ini`:

```ini
curl.cainfo = "C:\\ruta\\a\\cacert.pem"
openssl.cafile = "C:\\ruta\\a\\cacert.pem"
```

Reinicia `php artisan serve` después de guardar el cambio. No desactives la verificación TLS para solucionar este error.

Después de agregar migraciones nuevas, ejecuta `php artisan migrate`.

### Suscripción y personajes

Cada cuenta tiene una única prueba de 15 minutos. Al vencer, el backend bloquea el progreso y los juegos hasta que Stripe confirme una suscripción activa mediante el webhook.

Configura estas variables en `.env` para activar los pagos:

```env
FRONTEND_URL=http://localhost:5173
STRIPE_SECRET=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_BASIC=price_...
STRIPE_PRICE_FAMILY=price_...
STRIPE_PRICE_PREMIUM=price_...
```

Los tres precios deben ser recurrentes y pertenecer al mismo modo (prueba o producción) que `STRIPE_SECRET`. Tras cambiar `.env`, ejecuta `php artisan config:clear`.

El regreso de Checkout incluye `session_id={CHECKOUT_SESSION_ID}`. El frontend llama a `POST /api/billing/confirm` con ese identificador; el backend verifica en Stripe la cuenta propietaria, el pago y el estado actual de la suscripción. El plan se obtiene del precio de Stripe, nunca de la URL ni de localStorage. Esta confirmación permite activar el acceso aunque el webhook aún no haya llegado.

Configura también el webhook `POST /api/billing/webhook` para mantener el acceso actualizado cuando el cliente no vuelve a la aplicación, cambia el plan o cancela. Eventos: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.subscription.paused` y `customer.subscription.resumed`.

En desarrollo, usa `stripe listen --forward-to http://localhost:8000/api/billing/webhook` y copia el secreto `whsec_...` de esa sesión a `STRIPE_WEBHOOK_SECRET`. El secreto del listener local es diferente al del endpoint del Dashboard.

Para compras anteriores que no actualizaron la cuenta, reenvía desde Stripe el evento `checkout.session.completed` al webhook configurado. No es necesario volver a pagar.

Pruebas de pagos (Stripe simulado, sin cobros): `php artisan test --filter=SubscriptionApiTest`. Las dependencias instaladas requieren PHP 8.4 o superior.

Los personajes se compran exclusivamente con estrellas mediante `/api/characters/{character}/purchase`; no se usa dinero para los personajes.

### API de progreso

`GET /api/progress?client_id=<id>` recupera el progreso guardado. Si todavía no existe, devuelve `404`.

`PUT /api/progress` crea o actualiza el progreso. El cuerpo debe incluir:

```json
{
	"client_id": "id-del-navegador",
	"age_group": "peques",
	"stars": 1,
	"completed_exercises": {"m1": true},
	"current_subject": "math",
	"current_index_in_subject": 1
}
```

Las pruebas del contrato se ejecutan con `php artisan test --filter=ProgressApiTest`.
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
