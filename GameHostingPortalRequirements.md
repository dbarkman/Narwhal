# **V1 Formal Requirements — Game Hosting Portal (Auth0 \+ Paddle \+ Pterodactyl \+ Laravel)**

## **Project Narwhal: the unicorn of game server automation**

Project Narwhal is the rare “unicorn of the sea” in game hosting automation. Where most billing platforms, identity providers, and server panels remain fragmented, Narwhal ties them together into one seamless flow. By uniting Paddle billing, Auth0 identity, and Pterodactyl provisioning, it delivers a rare kind of harmony—reliable automation that’s simple to deploy, easy to manage, and built for scale. Tying these systems together isn’t fantasy, but doing it well is rare.

## **0\) Scope & Objectives**

Build a lean, production-ready backend \+ minimal portal that:

* Sells subscriptions via **Paddle** (Paddle handles tax/VAT, invoices, portal).  
* Provisions and manages game servers via **Pterodactyl Application API**.  
* Provides a small **Auth0-protected** customer portal (status \+ deep links).  
* Implements reliable, idempotent **webhook→job** automation with retries.

Out of scope (V1): affiliates, full SSO into Pterodactyl.

---

## **1\) Tech Stack & Standards**

### **1.1 Backend**

* **Framework:** Laravel 12, PHP 8.3  
* **DB:** MariaDB 10.6+ (or MySQL 8+)  
* **Queue:** Redis \+ Laravel Horizon (optional but recommended)  
* **HTTP:** Guzzle 7/8  
* **Auth:** Auth0 OIDC for the portal only  
* **Environment:** Native dev (no Docker); .env secrets via Vault/SSM in prod  
* **Time:** All server times UTC; store Paddle/Ptero timestamps as UTC

### **1.2 Packages**

**POC.1 (Required):**
* "laravel/framework": "^12"  
* "guzzlehttp/guzzle": "^7.8|^8.0"  

**Future Phases:**
* "auth0/auth0-php": "^8.0" (backend token verification)  
* "laravel/socialite": "^6.7" *or* Auth0's Laravel SDK for web login  
* "laravel/horizon": "^5.0" (queue management dashboard)  
* "spatie/laravel-activitylog" (audit trail)

---

## **1.3 POC Development Phases**

### **POC.1: Foundation & Pterodactyl Integration**
* Laravel 12 project setup with standard scaffolding
* Configuration management (strict .env validation, no defaults)
* Laravel built-in logging and email notifications
* Database migrations for core tables
* Pterodactyl API client (Guzzle-based)
* Interactive Artisan command for server provisioning (testing only)
* Full request/response logging for all API calls

### **POC.2: User Portal & Authentication**
* Auth0 OIDC integration
* User portal with dashboard
* Basic user authentication and session management
* Customer-facing server status display

### **POC.3: Billing Integration**
* Paddle webhook handling
* Subscription lifecycle management
* Automated provisioning triggered by billing events
* Queue system implementation (Redis + Horizon)

### **Future Phases:**
* Admin management interface
* Simple ticketing system
* Activity logging (Spatie)
* Advanced monitoring and alerts

---

## **2\) Functional Requirements**

### **2.1 User Portal (Web UI)**

* **Login:** Auth0 login (OIDC; email verified required).  
* **Dashboard:**  
  * Shows user’s active subscription status (from local DB).  
  * Shows provisioned server(s) status: online/offline, RAM/CPU/Disk limits (from Pterodactyl API).  
  * Buttons: **Open Game Panel** (pterodactyl.example.com), **Manage Billing** (Paddle customer portal deep link).  
* **Account Linking:** If Auth0 email ≠ Paddle email, a “Link Billing Email” flow to store billing\_email\_alias.

### **2.2 Billing & Subscription (via Paddle)**

* **Checkout:** All purchase UX happens in Paddle (links/buttons from marketing site).  
* **Customer Portal:** Use Paddle’s hosted portal link; no local card handling.  
* **Webhooks:** Handle events:  
  * subscription.created (or equivalent)  
  * payment.succeeded (first/renewal)  
  * payment.failed / subscription.past\_due  
  * subscription.canceled  
  * subscription.updated (plan/price change)  
* **Actions:** Map events to jobs (provision, adjust limits, suspend/unsuspend).  
* **Idempotency:** Each webhook processed exactly once by paddle\_event\_id.

### **2.3 Provisioning (Pterodactyl)**

* **Create user (if missing)**  
* **Create server** with mapped recipe (egg, image, env, memory, cpu, disk, location)  
* **Suspend/Unsuspend** on billing status  
* **Update build limits** on plan change  
* **Read status** for portal display

---

## **2.4 Configuration Management**

* **Strict .env Validation:** Application must validate all required configuration values on startup
* **No Defaults Policy:** If any required configuration is missing from .env, application throws descriptive error
* **Centralized Config Service:** Single service/controller responsible for reading and validating .env values
* **Required Configurations:**
  * Database connection details
  * Pterodactyl API endpoint and key
  * Email SMTP settings
  * Application URL and environment
* **Configuration Validation:** Type checking and format validation where applicable
* **Startup Checks:** All critical configurations validated during application bootstrap

---

## **3\) Integration Requirements**

### **3.1 Auth0 (Portal Identity)**

* **OIDC App:** Regular Web App  
* **Redirect URIs:**  
  * https://portal.example.com/auth/callback  
* **Logout URL:**  
  * https://portal.example.com/  
* **Claims:** Require email, email\_verified  
* **RBAC:** Optional; future “admin” role can gate admin routes  
* **Session:** Laravel session; rotate on login; 2FA handled by Auth0

### **3.2 Paddle (Billing)**

* **Environment:** Live \+ Sandbox (separate signing keys & vendor IDs)  
* **Webhook Verification:** Verify signatures (public key / HMAC per your Paddle product)  
* **Portal Links:** Store and/or generate customer billing portal URL via Paddle API or dashboard link token  
* **Plan Mapping:** Internal plan\_code ↔ Paddle price\_id

### **3.3 Pterodactyl (Game Panel)**

* **API Type:** **Application API** (admin API), least privilege key  
* **Endpoints used (typical):**  
  * POST /api/application/users (create) / GET /.../users?search=email  
  * POST /api/application/servers  
  * POST /api/application/servers/{id}/suspend  
  * POST /api/application/servers/{id}/unsuspend  
  * PATCH /api/application/servers/{id}/build (memory/cpu/disk)  
  * GET /api/application/servers/{id} (status/limits)  
* **Constraints:** Respect egg/environment expectations; validate location/node availability; exponential backoff on 5xx.

---

## **4\) Data Model (DB Schema)**

### **4.1 Tables**

**users**

* id (PK, ULID/UUID)  
* email (unique)  
* auth0\_user\_id (nullable, unique)  
* paddle\_customer\_id (nullable, unique)  
* billing\_email\_alias (nullable)  
* is\_admin (boolean, default false) // Future: admin interface  
* created\_at, updated\_at

**subscriptions**

* id (PK)  
* user\_id (FK → users)  
* paddle\_subscription\_id (unique)  
* plan\_code (index)  
* status (enum: trialing, active, past\_due, canceled, paused)  
* next\_billing\_at (datetime UTC, nullable)  
* trial\_end\_at (datetime UTC, nullable)  
* canceled\_at (datetime UTC, nullable)  
* created\_at, updated\_at

**servers**

* id (PK)  
* user\_id (FK → users)  
* subscription\_id (FK)  
* ptero\_user\_id (nullable, integer)  
* ptero\_server\_id (nullable, integer)  
* region (string)  
* egg (string)  
* docker\_image (string)  
* env\_json (json)  
* **limits:** ram\_mb (int), cpu\_pct (int), disk\_mb (int)  
* status (enum: pending, provisioning, active, suspended, error, deleted)  
* created\_at, updated\_at

**events**

* id (PK)  
* paddle\_event\_id (unique)  
* type (string)  
* received\_at (datetime)  
* processed\_at (datetime, nullable)  
* status (enum: processed, skipped, failed)  
* payload\_json (json)  
* result\_json (json, nullable)  
* error\_message (text, nullable)

**plan\_mappings**

* plan\_code (PK)  
* paddle\_price\_id (string)  
* egg (string)  
* docker\_image (string)  
* ram\_mb (int)  
* cpu\_pct (int)  
* disk\_mb (int)  
* location\_id (int)  // Pterodactyl location  
* env\_json (json)

**tickets** *(Future Phase)*

* id (PK)  
* user\_id (FK → users)  
* subject (string)  
* status (enum: open, in\_progress, resolved, closed)  
* priority (enum: low, medium, high, urgent)  
* created\_at, updated\_at

**ticket\_notes** *(Future Phase)*

* id (PK)  
* ticket\_id (FK → tickets)  
* user\_id (FK → users, nullable) // null = system/admin note  
* note\_text (text)  
* is\_admin\_note (boolean, default false)  
* created\_at, updated\_at

*(Add indexes on foreign keys, paddle\_subscription\_id, and ticket status/priority.)*

---

## **5\) API & Routing (Laravel)**

### **5.1 Public/Marketing (separate site ok)**

* GET /plans → renders plans with “Buy” buttons (Paddle links)

### **5.2 Portal (Auth0-protected)**

* GET /dashboard → server \+ subscription summary  
* GET /billing → redirect to Paddle customer portal  
* GET /panel → redirect to Pterodactyl URL  
* POST /link-billing-email → set billing\_email\_alias

### **5.3 Webhooks**

* POST /webhooks/paddle  
  **Middlewares:**  
  1. VerifyPaddleSignature (reject if invalid)  
  2. RateLimit: strict  
     **Controller:** PaddleWebhookController@handle  
     **Flow:**  
  3. Persist event (upsert by paddle\_event\_id)  
  4. Dispatch HandlePaddleEvent job (queue)  
  5. Return 200 immediately

---

## **6\) Jobs (Queue Workers)**

### **6.1** 

### **HandlePaddleEvent**

* Switch on type, call use-cases:  
  * OnSubscriptionCreated  
  * OnPaymentSucceeded  
  * OnPaymentFailed  
  * OnSubscriptionCanceled  
  * OnSubscriptionUpdated

### **6.2** 

### **ProvisionServer(subscription\_id)**

* Ensure customer exists (by email), get/create ptero\_user\_id  
* Create server with plan mapping (egg/image/env/limits/location)  
* Save ptero\_server\_id, set server status=active on success  
* Send Welcome email (panel URL, reset link if applicable)  
* Retries: 3–5 with exp backoff; mark status=error on final failure

### **6.3** 

### **SuspendServer(server\_id)**

* Call Pterodactyl suspend; set status=suspended

### **6.4** 

### **UnsuspendServer(server\_id)**

* Call Pterodactyl unsuspend; set status=active

### **6.5** 

### **AdjustServerLimits(server\_id, new\_limits)**

* PATCH build limits; update local limits

---

## **7\) Services (Code Structure)**

App\\Services\\Auth0\\  
  Auth0PortalGuard.php           // if using middleware/guard pattern

App\\Services\\Paddle\\  
  PaddleWebhookVerifier.php  
  PaddleClient.php               // minimal if needed

App\\Services\\Pterodactyl\\  
  PterodactylClient.php          // wraps Application API calls  
  Dtos\\{User,Server,BuildLimits}.php  
  Exceptions\\{ApiException}.php

App\\UseCases\\  
  OnSubscriptionCreated.php  
  OnPaymentSucceeded.php  
  OnPaymentFailed.php  
  OnSubscriptionCanceled.php  
  OnSubscriptionUpdated.php

**PterodactylClient (examples):**

public function findUserByEmail(string $email): ?int;  
public function createUser(string $email, string $firstName, string $lastName): int;  
public function createServer(array $params): int; // returns ptero\_server\_id  
public function suspendServer(int $serverId): void;  
public function unsuspendServer(int $serverId): void;  
public function updateBuild(int $serverId, int $ramMb, int $cpuPct, int $diskMb): void;  
public function getServer(int $serverId): array; // status, limits

---

## **7.1 Pterodactyl Testing Command (POC.1)**

**Purpose:** Interactive Artisan command for testing Pterodactyl API integration (throw-away code)

**Command:** `php artisan pterodactyl:provision-server`

**Interactive Prompts:**
* Email address (for user creation/lookup)
* First name / Last name
* Server name
* Memory (MB)
* CPU percentage
* Disk space (MB)
* Location/Node ID
* Egg ID
* Docker image (optional, use egg default)

**Functionality:**
1. Validate all inputs
2. Log start of provisioning process
3. Check if Pterodactyl user exists by email
4. Create Pterodactyl user if not found
5. Create server with specified parameters
6. Log all API requests and responses (full details)
7. Display success/failure status with server details
8. Log completion status

**Error Handling:**
* Catch and log all API exceptions
* Display user-friendly error messages
* Log full error details for debugging
* Exit gracefully on any failure

---

## **8\) Security Requirements**

* **Webhook verification:** Mandatory signature verification; reject invalid; log attempts.  
* **Idempotency:** Unique DB constraint on events.paddle\_event\_id; handlers must be idempotent (check existing server/sub).  
* **Secrets:** Never in code; .env \+ secret manager only.  
* **API keys:** Pterodactyl key scoped to required endpoints. Rotate quarterly.  
* **RBAC (portal):** Basic user role; optional admin for manual retries/corrections.  
* **Input validation:** Strict validation on plan codes, limits, region.  
* **Audit:** Log every event→action with correlation IDs.

---

## **9\) Operational Requirements**

### **9.1 POC.1 Requirements**
* **Logging:** Laravel built-in logging with structured format
* **Error Handling:** Try once, log activity, email admins on failure
* **API Logging:** Full request/response logging for all Pterodactyl API calls
* **Config Validation:** Strict .env validation on application startup

### **9.2 Future Phase Requirements**
* **Advanced Observability:** Structured logs (JSON), request IDs; job failure alerts
* **Queue Management:** Redis + Horizon for background job processing
* **Retry Logic:** Exponential backoff for network calls; circuit-break repeated 5xx
* **Disaster Handling:** If Pterodactyl down, queue keeps jobs; dashboard shows degraded status
* **Maintenance Tasks (daily cron):**
  * Reconcile subscriptions nearing next\_billing\_at
  * Purge canceled servers after retention window (configurable)
  * Retry stuck events
* **Backups:** Nightly DB backups, 7/30 retention

---

## **10\) Configuration (.env)**

### **10.1 POC.1 Required Configuration**

APP\_ENV=local|production  
APP\_URL=https://portal.example.com  
APP\_KEY=... (Laravel app key)

DB\_CONNECTION=mysql  
DB\_HOST=127.0.0.1  
DB\_PORT=3306  
DB\_DATABASE=narwhal  
DB\_USERNAME=...  
DB\_PASSWORD=...

PTERO\_BASE\_URL=https://panel.example.com  
PTERO\_APP\_API\_KEY=... (Application API key)

MAIL\_MAILER=smtp  
MAIL\_HOST=...  
MAIL\_PORT=...  
MAIL\_USERNAME=...  
MAIL\_PASSWORD=...  
MAIL\_ENCRYPTION=tls  
MAIL\_FROM\_ADDRESS=admin@example.com  
MAIL\_FROM\_NAME="Narwhal Admin"

### **10.2 Future Phase Configuration**

AUTH0\_DOMAIN=...  
AUTH0\_CLIENT\_ID=...  
AUTH0\_CLIENT\_SECRET=...  
AUTH0\_AUDIENCE=...

PADDLE\_VENDOR\_ID=...  
PADDLE\_WEBHOOK\_PUBLIC\_KEY=...  
PADDLE\_ENV=sandbox|live

QUEUE\_CONNECTION=redis  
REDIS\_URL=redis://...

## **11\) Plan Mapping & Business Rules**

* **plan\_code format:** MC-2GB-US, MC-3GB-US, etc.  
* Each plan\_code maps to:  
  * paddle\_price\_id  
  * Ptero recipe: {egg, docker\_image, env\_json, limits, location\_id}  
* **Trial policy:** (choose one) Provision on subscription.created **or** first payment.succeeded.  
* **Grace policy:** On payment.failed, mark past\_due and set suspend\_at \= now()+N days. A scheduled job enforces suspension if still unpaid.  
* **Cancellation:** On subscription.canceled, suspend immediately or at period end (config flag).  
* **Upgrades:** Apply immediately (increase limits).  
* **Downgrades:** Apply at next billing cycle (safer) unless forceImmediateDowngrade=true.

---

## **12\) Testing & Acceptance**

### **12.1 Unit/Feature Tests**

* Webhook verification (valid/invalid signature)  
* Event idempotency (duplicate deliveries)  
* Provision flow (user create → server create)  
* Suspend/unsuspend on payment state changes  
* Plan change → limits adjusted  
* Portal auth (only logged-in users can see dashboard)

### **12.2 Sandbox Scenarios (Paddle)**

* New subscription (trial & non-trial)  
* First payment success → provision  
* Renewal success (no-op except next\_billing\_at)  
* Payment failed → grace → suspend  
* Payment recovered → unsuspend  
* Cancellation → suspend

### **12.3 Failure Injection**

* Pterodactyl 500/timeout → retries, eventually alerts  
* DB deadlock/unique violation → safe retry

**Acceptance Criteria:** All above paths green; duplicate webhook deliveries never create duplicate servers; suspend/unsuspend always converge to correct state.

---

## **13\) Optional: Pterodactyl SSO (Future)**

* **Requirement:** Add a vetted **OIDC/OAuth SSO addon** for Pterodactyl.  
* **Config:** Point addon to Auth0; trust email claim; map to existing Ptero user by email.  
* **Portal UX:** Replace "Open Panel" with one-click SSO link.  
* **Non-goals (V1):** No reverse proxy/session injection.

---

## **13.1) Admin Management System (Future Phase)**

### **13.1.1 Admin Authentication**
* **Method:** Laravel built-in authentication with `is_admin` boolean field
* **Creation:** Initial admin creation via Artisan command
* **Migration:** Future integration with Auth0 admin roles
* **Session:** Standard Laravel session management with admin middleware

### **13.1.2 Admin Dashboard**
* **User Management:** View, search, edit user details and subscription status
* **Subscription Oversight:** View all subscriptions, billing status, plan details
* **Server Management:** View server assignments, status, resource usage
* **System Logs:** Live log viewer with filtering and search capabilities
* **Manual Actions:** Ability to manually provision, suspend, or modify servers

### **13.1.3 Admin Tools**
* **Failed Job Retry:** Interface for retrying failed webhook/job processing
* **Configuration Validator:** Tool for testing API connections and configurations
* **Data Export:** Export user/subscription data for reporting
* **System Health:** Dashboard showing API connectivity, queue status, error rates

---

## **13.2) Simple Ticketing System (Future Phase)**

### **13.2.1 User Interface**
* **Ticket Creation:** Simple form (subject, description, priority)
* **Ticket View:** User can view their tickets and conversation history
* **Email Notifications:** User receives emails on ticket updates

### **13.2.2 Admin Interface**
* **Ticket Queue:** View all tickets by status, priority, date
* **Ticket Management:** Add notes, change status, assign priority
* **Response System:** Admin responses appear in ticket conversation
* **Email Integration:** Admins notified of new tickets via email

### **13.2.3 Data Structure**
* **Tickets Table:** ID, user_id, subject, status, priority, timestamps
* **Ticket Notes Table:** ID, ticket_id, user_id, note_text, is_admin_note, timestamp
* **Status Flow:** open → in_progress → resolved → closed
* **Priority Levels:** low, medium, high, urgent

---

## **14\) Example Controller & Job Sketches**

**Paddle Webhook Controller (sketch):**

public function handle(Request $request)  
{  
    $this-\>verifier-\>assertValid($request); // throws on failure

    $payload \= $request-\>all();  
    $eventId \= $payload\['event\_id'\];  
    $type    \= $payload\['event\_type'\];

    $event \= Event::firstOrCreate(  
        \['paddle\_event\_id' \=\> $eventId\],  
        \['type' \=\> $type, 'payload\_json' \=\> $payload, 'received\_at' \=\> now()\]  
    );

    if ($event-\>status \=== 'processed') {  
        return response()-\>json(\['ok' \=\> true\]); // idempotent  
    }

    HandlePaddleEvent::dispatch($event-\>id);  
    return response()-\>json(\['queued' \=\> true\]);  
}

**Provision Job (sketch):**

public function handle(PterodactylClient $ptero)  
{  
    $sub \= Subscription::with(\['user','server','plan'\])-\>findOrFail($this-\>subscriptionId);

    $userId \= $ptero-\>findUserByEmail($sub-\>user-\>email)  
           ?? $ptero-\>createUser($sub-\>user-\>email, 'First', 'Last');

    if (\!$sub-\>server) {  
        $map \= PlanMapping::findOrFail($sub-\>plan\_code);  
        $serverId \= $ptero-\>createServer(\[  
            'owner\_id'     \=\> $userId,  
            'name'         \=\> 'MC-' . $sub-\>user-\>id,  
            'egg'          \=\> $map-\>egg,  
            'docker\_image' \=\> $map-\>docker\_image,  
            'env'          \=\> json\_decode($map-\>env\_json, true),  
            'limits'       \=\> \['memory' \=\> $map-\>ram\_mb, 'cpu' \=\> $map-\>cpu\_pct, 'disk' \=\> $map-\>disk\_mb\],  
            'location'     \=\> $map-\>location\_id,  
        \]);

        // persist  
        Server::create(\[...\]);  
    }  
}

## **15\) Development Roadmap**

### **POC.1: Foundation (Current Phase)**
1. **Environment Setup:** PHP 8.4 upgrade, Laravel 12 project initialization
2. **Core Infrastructure:** Database setup, configuration management, logging system
3. **Pterodactyl Integration:** API client development, request/response logging
4. **Testing Command:** Interactive Artisan command for end-to-end server provisioning
5. **Documentation:** API integration testing and error handling validation

### **POC.2: Authentication & Portal**
1. **Auth0 Setup:** OIDC integration, user session management
2. **User Portal:** Basic dashboard with server status display
3. **Account Management:** User profile, billing email linking

### **POC.3: Billing Integration**
1. **Paddle Integration:** Webhook handling, signature verification
2. **Subscription Management:** Lifecycle events, automated provisioning
3. **Queue System:** Redis + Horizon implementation
4. **Production Deployment:** Monitoring, secrets management, go-live

### **Future Phases**
1. **Admin Interface:** User management, subscription oversight, log viewing
2. **Ticketing System:** User support tickets with admin response system
3. **Advanced Features:** Activity logging, enhanced monitoring, reporting

