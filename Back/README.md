<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
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

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Inspection staff setup

Public registration is limited to students and property owners. An authorized administrator creates staff accounts from the backend command line; the password prompts are hidden and passwords are stored as hashes.

From the backend folder, create an administrator first:

```powershell
php artisan housing:create-staff admin
```

Then create each worker. The command asks for a governorate, which restricts that worker to inspections in that service area:

```powershell
php artisan housing:create-staff worker
```

Run the worker command once for each worker, up to a maximum of six worker accounts. Each worker is assigned to a governorate, and the command refuses to provision a seventh account. Use the shared login page for staff to sign in; only accounts provisioned by this command receive the `admin` or `worker` role.

An authenticated administrator can list workers in a governorate with `GET /api/admin/workers?governorate=Tunis`, assign an inspection with `POST /api/admin/houses/{house}/inspection-assignment` and JSON fields `worker_id` and `scheduled_at`, then review the submitted report with `POST /api/admin/inspections/{inspection}/decision` and JSON fields `decision` (`verified`, `rejected`, or `corrections_required`) and optional `review_notes`. These routes require the administrator's authenticated session and CSRF protection. Workers can only see reports assigned to their account; submitting their report does not verify the house. Only an administrator can record the final decision.

For a fresh check requested by a student, the administrator can list pending requests with `GET /api/admin/student-inspection-requests` and assign the request using `POST /api/admin/inspections/{inspection}/assignment` with `worker_id` and `scheduled_at`. After the worker submits the report, an administrator reviews it with `POST /api/admin/inspections/{inspection}/decision` using `decision: "report_shared"`. This shares the reviewed report with the requesting student without changing the house's existing listing verification. If the report records damage, the house owner also receives an in-app notification containing the damaged item and inspection notes; it appears only to that house's owner and can be marked as read.

An owner can request deletion of their house with `POST /api/owner/houses/{id}/deletion-request`. The listing remains in the owner's account while the request is pending. An administrator reviews the request in All house posts; confirming its removal permanently deletes the house, uploaded photos, and linked rental, payment, inspection, and maintenance records. Administrators can also remove any house post directly with `DELETE /api/admin/houses/{id}`.

## Student house search and rental requests

Students can search houses through `GET /api/student/houses` using optional `governorate`, `city`, `max_rent`, `min_bedrooms`, `furnishing_status`, `available`, and `page` query parameters. Results are paginated and include only houses with a recorded administrator decision and a completed, approved, reviewed inspection. Availability defaults to `true`. House details and photos are available through the student-only routes.

After both parties confirm a rental, the active rental and house details appear under the student's My house page. A student can submit one move-out report with a planned date and message using `POST /api/student/rental-requests/{id}/move-out-reports`. Reports are visible to administrators at `GET /api/admin/move-out-reports`; an administrator can mark one reviewed with `POST /api/admin/move-out-reports/{id}/review`. A move-out report informs the company but does not automatically terminate the rental or make the house available.

Every new `POST /api/student/rental-requests` also creates a linked house-condition inspection automatically. Administrators see it in the student inspection queue and assign it to a local worker using the existing student-inspection workflow. Once the worker submits the inspection, an administrator shares the reviewed report with `decision: "report_shared"`; the report then appears in that student's rental request. The owner cannot accept or reject that rental request until the report has been shared. These rental-triggered inspections are kept separate from standalone house-check requests, so they do not appear as duplicate check requests on the house listing.

From a house's details, a student can request a fresh condition check with `POST /api/student/houses/{house}/check-requests`. The company assigns a local inspector, and the student sees the visit status and company-reviewed report on that house's details after it is shared.

For a standalone condition check, after its reviewed report is shared the student can choose “Rent this house” from the inspection request to open the house details. Selecting “Rent now” creates a rental request and triggers its own linked inspection as described above. `POST /api/student/rental-requests` records a pending payment obligation for the first month's public rent plus the configured one-time student service fee. The student request is sent to the owner for acceptance after the linked report is shared; the student and owner each separately confirm a secured rental through their respective `/confirm-secured` routes. When the owner accepts, an in-app notification confirms the monthly owner rent amount (the agreed amount after the 5% reduction if accepted, otherwise the agreed/requested owner rent). This confirms acceptance only, not payment. An owner rejection or student cancellation cancels the pending payment obligation. The house becomes unavailable after both confirmations. Once the rental is secured, the system creates a monthly owner rent reminder using the agreed owner rent (or the requested owner rent if no negotiated amount was recorded). The reminder is due monthly from the rental secured date. Online collection and owner bank payouts are not configured: notifications and reminders do not transfer money and are never proof that rent was paid or received.

The service fee is configured with `STUDENT_SERVICE_FEE_TND` in the backend environment (the default is `20.000` TND). Searching and requesting a condition check are free.

## Maintenance requests and repair handling

After updating the backend, apply the additive schema changes from the `Back` folder with `php artisan migrate`. This migration preserves existing maintenance records.

Only a student with a completed rental confirmed by both parties can report a maintenance issue. Student requests include a category, description, priority, report date, and optional JPG/PNG/WebP photos (up to 8, each no larger than 5 MB). Photos are saved on Laravel's configured filesystem and are served only to the reporting student, the house owner, the assigned worker, or an authenticated administrator. Owners can view completed and owner-confirmed company repair reports for their own houses at `GET /api/owner/repair-reports`; reports include the repair outcome, final cost when recorded, evidence photos, visits, and update history. Reports belonging to other owners and work not yet confirmed complete are excluded.

The owner confirms receipt in the owner dashboard. After receipt, an administrator can find workers for the house governorate with `GET /api/admin/workers?governorate=Tunis` and assign one using `POST /api/admin/maintenance-requests/{id}/assignment` with `worker_id` and optional `scheduled_at`. Assignment is rejected if the worker is not registered for the house governorate. Workers then schedule a visit, record the visit, submit an estimate, start approved work, submit the repair outcome/evidence for owner review, or reject with an explanation. The owner can add notes, approve/reject estimates, and confirm or dispute reported completion.

The default policy in `config/student_housing.php` does **not** presume subscription coverage (`MAINTENANCE_DEFAULT_COVERED=false`) and requires owner approval before estimated repair work proceeds (`MAINTENANCE_OWNER_APPROVAL_REQUIRED=true`). These settings can be changed in the backend environment to match an agreed repair policy. The owner records service coverage and payment responsibility when reviewing an estimate. Estimated and actual repair costs are stored separately. The platform does not collect or deduct repair payments.

Maintenance notifications are stored in the `maintenance_notifications` database table and shown in the notification menu for administrators and workers. Owners have a Reports page for completed repairs, but no maintenance-request management page or maintenance notifications. Notifications are in-app only; no email or SMS is sent by this feature.

## Payment records

Apply the additive payment schema from the `Back` folder with `php artisan migrate`. It adds `payments` and creates pending subscription obligations for existing houses plus pending student-fee obligations for completed, mutually confirmed rentals that are not already recorded as paid. It does not create successful payment records, charge anyone, or deactivate a house.

Each newly registered house receives its own owner subscription and a pending first-month payment record. The owner dashboard shows per-house prices, the total monthly amount, renewal dates, current/expired subscription status, and payment history. Student service-fee payment records are created only when the rental is completed after both the student and owner confirm it. Searching, viewing, and initially requesting a house do not create fee records.

The only amount settings are `OWNER_SUBSCRIPTION_MONTHLY_TND` and `STUDENT_SERVICE_FEE_TND` (both default to `20.000`) in the existing `student_housing` configuration. Monetary values are stored as decimal TND amounts. The server derives the payer, obligation, and amount. Payment preparation endpoints are `POST /api/student/rental-requests/{id}/payment` and `POST /api/owner/subscriptions/{id}/payment`; they return the existing pending obligation idempotently. They do not start a checkout or accept client-supplied amounts, payer IDs, or payment statuses. Private histories are available at `GET /api/student/payments` and the owner's `/api/owner/dashboard` subscriptions data.

No payment provider credentials, SDK, checkout, or webhook are configured in this project. The `App\Contracts\PaymentProvider` interface is the integration boundary for a future provider; no public route can mark a payment paid. A pending record means only that an amount is owed, not that payment was attempted or received. The provider must verify its server notification before a later integration can record success; browser redirects alone are not confirmation. No card details are collected or stored.

The Laravel scheduler creates monthly owner rent reminders with `rentals:send-monthly-owner-reminders`. Run `php artisan schedule:work` during local development, or configure the host scheduler to run `php artisan schedule:run` every minute in production. Reminders are visible in the owner's notification menu and explicitly state that rent must be arranged directly until secure collection and payout are integrated.

Provider research for this step: [Konnect's official documentation](https://docs.konnect.network/docs/en/overview/getting-started) describes its merchant payment platform; [Paymee's official site](https://www.paymee.tn/) advertises Paylink collection. Public pages alone do not establish that either provider will onboard this business, settle in the required way, or provide approved test credentials. No provider was selected or represented as approved. Obtain merchant eligibility, supported local payment methods, fees/settlement terms, sandbox credentials, and the provider's webhook signature/verification instructions before enabling checkout.
