# Sakani

Sakani is a student-housing platform that connects students with property owners and coordinates house verification, rental requests, and maintenance workflows. The application consists of a React frontend and a Laravel JSON API.

## Features

- **Students:** Browse verified homes, view listing photos, request inspections, review shared reports, submit rental requests, track secured rentals in **My house**, and send move-out reports to the company.
- **Property owners:** Create and manage house listings, respond to rental requests after the administrator shares the condition report, receive notifications about reported damage, and review maintenance and repair information.
- **Administrators:** Review submissions and inspection reports, assign inspectors, share condition reports, review move-out reports, and manage house posts.
- **Workers:** View assigned inspections and maintenance work, submit inspection reports, and record repair progress.
- **Rental workflow:** A rental request automatically starts a linked house inspection. The owner can accept or reject only after an administrator has shared the report. The student and owner separately confirm when the rental is secured.
- **Move-out reporting:** Students with a secured rental can tell the company when they plan to leave. Submitting or reviewing this report does not automatically terminate the rental or make the listing available.

## Technology

| Component | Technology |
| --- | --- |
| Frontend | React 19, React Router, Vite 6 |
| Backend | PHP 8.2+, Laravel 12 |
| API authentication | Laravel session authentication and CSRF protection |
| Database | Laravel-supported database; MySQL is configured by default in `.env.example` |

## Repository layout

```text
Front/   React application
Back/    Laravel application, API, database migrations, and tests
```

For more detail about the application structure and request flow, see [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).

## Requirements

- PHP 8.2 or later and Composer
- Node.js and npm
- A database supported by Laravel (MySQL recommended)

## Local development

### 1. Configure the backend

In PowerShell:

```powershell
Set-Location Back
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Edit `Back/.env` with your local database connection, then apply the migrations:

```powershell
php artisan migrate
```

The example configuration uses MySQL. Ensure the selected database exists and the connection settings in `.env` match your local environment. Do not commit `.env` or credentials.

### 2. Install frontend dependencies

In a second PowerShell terminal:

```powershell
Set-Location Front
npm install
```

### 3. Start both applications

Start the Laravel API:

```powershell
Set-Location Back
php artisan serve --host=127.0.0.1 --port=8000
```

Start the React development server in another terminal:

```powershell
Set-Location Front
npm run dev
```

Open the address printed by Vite (normally `http://127.0.0.1:5173`). The frontend development server proxies `/api` requests to Laravel at `http://127.0.0.1:8000`.

## Staff accounts

Public registration is intended for students and property owners. An administrator and workers can be provisioned from the backend:

```powershell
Set-Location Back
php artisan housing:create-staff admin
php artisan housing:create-staff worker
```

The command prompts for account details and, for workers, a governorate. Workers are assigned to their service area; the system supports up to six worker accounts. Keep administrator and worker credentials private.

## Build and test

Build the frontend for production:

```powershell
Set-Location Front
npm run build
```

Run the Laravel test suite:

```powershell
Set-Location Back
php artisan test
```

Inspect registered API routes:

```powershell
Set-Location Back
php artisan route:list --path=api
```

## Payments and operational notes

Payment obligations and in-app notifications are recorded by the platform, but **no payment provider, checkout, or bank payout integration is configured**. Sakani does not collect, transfer, or confirm rent payments. Pending payment records are not proof that money has been paid. Owners and students must arrange rent directly until a supported provider is integrated.

Monthly owner rent reminders require Laravel's scheduler. During local development, run:

```powershell
Set-Location Back
php artisan schedule:work
```

For production, configure the host to run `php artisan schedule:run` every minute. Production hosting must also serve the React frontend and Laravel API with appropriate HTTPS, session-cookie, and routing configuration; the Vite proxy is for local development only.

## Additional documentation

- [Project structure and development conventions](PROJECT_STRUCTURE.md)
- [Backend workflow and API notes](Back/README.md)
