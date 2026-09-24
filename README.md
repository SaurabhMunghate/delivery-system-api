# Delivery System API (PHP + SQLite)

REST API for the Delivery Management System. The React Native Android app is in [delivery-system](https://github.com/SaurabhMunghate/delivery-system).

## Run

Needs PHP 8.1+ with `pdo_sqlite` and `fileinfo` (both enabled by default in XAMPP / most PHP installs).

```bash
cd delivery-system-api
php -S 0.0.0.0:8000 index.php
```

Check it: open `http://localhost:8000/api/health`.

On first request the API adds its tables to `info.sqlite` automatically. Your existing `table1` keeps its data. Two columns are added to it: `role` (`admin` / `delivery`) and `is_active`.

* Ram (`ram@gmail.com` / `ram@123`) and Omm (`omm@gmail.com` / `omm@123`) become **delivery boys**. Their plain-text passwords are converted to secure hashes the first time they log in.
* A default **admin** is created: `admin@delivery.local` / `admin@123`. Change it with `POST /api/me/password`.

**Apache/XAMPP:** copy `backend/` to `htdocs/backend`. The `.htaccess` file routes requests and blocks `data/`, `logs/`, `src/`. The API is then `http://<host>/backend/api/...`. Make sure `data/`, `logs/` and `uploads/` are writable by the web server.


> The database file is not committed (it holds user passwords). Copy your `info.sqlite` into `data/`, or just start the server: an empty database with all tables and the default admin is created automatically.

##  Status flow

```
Shopify webhook / admin create → New
New  --admin validate (select items)-->  Pending
Pending --admin assign--> Pending (assigned_to set, OTP generated)
Pending --boy Start Delivery--> OutForDelivery
Pending/OutForDelivery --boy submits bill+payment--> Delivered
Pending/OutForDelivery --boy cancel (reason) / admin cancel--> Cancelled
```
Every change is saved in `order_timeline` (who and when) and also appended to `logs/app.log`.

##  API reference

Send `Authorization: Bearer <token>` on every call except login and webhook. Bodies are JSON unless marked multipart.
Every response looks like `{ "success": true, ... }` or `{ "success": false, "message": "..." }`.

### Auth
| Method | Path | Body |
|---|---|---|
| POST | `/api/login` | `{email, password}` → `{token, user}` |
| GET | `/api/me` | |
| POST | `/api/logout` | |
| POST | `/api/me/password` | `{old_password, new_password}` |

### Admin (web dashboard)
| Method | Path | Body / query |
|---|---|---|
| GET | `/api/admin/delivery-boys?active=1` | includes `pending_count` for load balancing |
| POST | `/api/admin/delivery-boys` | `{fname, lname, email, password, phone?, city?}` |
| PATCH | `/api/admin/delivery-boys/{id}` | any of `fname, lname, email, phone, city, password, is_active` |
| GET | `/api/admin/orders` | `?status=&boy_id=&date=YYYY-MM-DD&q=&limit=&offset=` |
| POST | `/api/admin/orders` | `{customer_name, address, phone, items:[{sku,name,qty,price}], amount_type?}` |
| GET | `/api/admin/orders/{id}` | full order + items + bill + timeline + OTP |
| POST | `/api/admin/orders/{id}/validate` | `{item_ids:[...]}` → Pending |
| POST | `/api/admin/orders/{id}/assign` | `{delivery_boy_id, address?, drop_instructions?, expected_at?}` |
| POST | `/api/admin/orders/{id}/billing` | multipart: `amount_type` (Prepaid/COD/Partial), `total_amount`, `payment_mode` (Cash/UPI/Card), `bill_photo` |
| POST | `/api/admin/orders/{id}/cancel` | `{reason}` |
| GET | `/api/admin/stats` | counts by status, delivered/collected today |

### Delivery boy (Android app)
| Method | Path | Body |
|---|---|---|
| GET | `/api/my/orders?status=active\|done\|all` | only this boy's orders |
| GET | `/api/my/orders/{id}` | |
| POST | `/api/my/orders/{id}/start` | |
| POST | `/api/my/orders/{id}/cancel` | `{reason}` (required) |
| POST | `/api/my/orders/{id}/deliver` | multipart: `amount_collected`, `payment_mode`, `bill_photo` (required), `signature?`, `otp?` |

### Shopify
`POST /api/webhooks/shopify/orders-create`. In Shopify Admin → Settings → Notifications → Webhooks, create an **Order creation** webhook (JSON) that points here. Put the signing secret in `config.php` (`shopify_webhook_secret`) so the HMAC is checked. Duplicate deliveries of the same order are ignored.

### Quick test with curl
```bash
B=http://localhost:8000/api
T=$(curl -s -X POST $B/login -H 'Content-Type: application/json' \
     -d '{"email":"admin@delivery.local","password":"admin@123"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')
curl -s -X POST $B/admin/orders -H "Authorization: Bearer $T" -H 'Content-Type: application/json' \
  -d '{"customer_name":"Test","address":"Civil Lines, Nagpur","phone":"9876543210","items":[{"name":"Item A","qty":2,"price":100}]}'
curl -s -X POST $B/admin/orders/1/validate -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -d '{"item_ids":[1]}'
curl -s -X POST $B/admin/orders/1/assign   -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -d '{"delivery_boy_id":1}'
```
Now log in on the app as `ram@gmail.com` / `ram@123` and the order appears.

##  Notes / next steps
* Bill photos are served from `/uploads/<random-name>`. The names can't be guessed, but anyone with the link can open the file. If you need stricter access, move `uploads/` outside the web root and serve files through an authenticated endpoint.
* To make the OTP mandatory at the doorstep, set `require_otp` to `true` in `config.php`. The admin sees each order's OTP in `GET /api/admin/orders/{id}` and can share it with the customer.
* Not built yet: the admin **web dashboard** UI. All the APIs it needs are ready above. Push notifications (Expo push / FCM) and live GPS are also not built.
# delivery-system-api
