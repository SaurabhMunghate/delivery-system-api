#!/usr/bin/env bash
# End-to-end smoke test for the Delivery System API.
# Usage:  ./test-api.sh            (server must be running on localhost:8000)
#         BASE=http://localhost:8080/api ./test-api.sh
set -u
BASE="${BASE:-http://localhost:8000/api}"
J="Content-Type: application/json"

# pull a JSON field: first "key":value (string or number)
field() { sed -n "s/.*\"$1\":\"\{0,1\}\([^\",}]*\).*/\1/p" | head -1; }
step()  { echo; echo "=== $1"; }

step "Health"
curl -s "$BASE/health"; echo

step "Login as admin"
RES=$(curl -s -X POST "$BASE/login" -H "$J" -d '{"email":"admin@delivery.local","password":"admin@123"}')
ADMIN_TOKEN=$(echo "$RES" | field token)
[ -z "$ADMIN_TOKEN" ] && { echo "Login failed: $RES"; exit 1; }
echo "ADMIN_TOKEN=$ADMIN_TOKEN"
A="Authorization: Bearer $ADMIN_TOKEN"

step "Create delivery boy (ram@gmail.com / ram@123) - fine if it says email exists"
curl -s -X POST "$BASE/admin/delivery-boys" -H "$A" -H "$J" \
  -d '{"fname":"Ram","lname":"Patil","email":"ram@gmail.com","password":"ram@123","phone":"9876543210","city":"Nagpur"}'; echo

step "Login as delivery boy"
RES=$(curl -s -X POST "$BASE/login" -H "$J" -d '{"email":"ram@gmail.com","password":"ram@123"}')
BOY_TOKEN=$(echo "$RES" | field token)
BOY_ID=$(echo "$RES" | sed -n 's/.*"user":{"id":\([0-9]*\).*/\1/p')
[ -z "$BOY_TOKEN" ] && { echo "Boy login failed: $RES"; exit 1; }
echo "BOY_TOKEN=$BOY_TOKEN  BOY_ID=$BOY_ID"
B="Authorization: Bearer $BOY_TOKEN"

step "Create order"
RES=$(curl -s -X POST "$BASE/admin/orders" -H "$A" -H "$J" \
  -d '{"customer_name":"Amit Sharma","phone":"9123456780","address":"12 Dharampeth, Nagpur","amount_type":"COD","items":[{"sku":"MNG-1KG","name":"Mango 1kg","qty":2,"price":150},{"sku":"BAN-12","name":"Banana dozen","qty":1,"price":60}]}')
ORDER_ID=$(echo "$RES" | sed -n 's/^{"success":true,"data":{"id":\([0-9]*\).*/\1/p')
ITEM_IDS=$(echo "$RES" | grep -o '"items":\[[^]]*\]' | grep -o '{"id":[0-9]*' | grep -o '[0-9]*' | paste -sd, -)
echo "ORDER_ID=$ORDER_ID  ITEM_IDS=[$ITEM_IDS]  status=$(echo "$RES" | field status)"

step "Validate order (New -> Pending)"
curl -s -X POST "$BASE/admin/orders/$ORDER_ID/validate" -H "$A" -H "$J" -d "{\"item_ids\":[$ITEM_IDS]}" | field status

step "Assign to delivery boy"
curl -s -X POST "$BASE/admin/orders/$ORDER_ID/assign" -H "$A" -H "$J" \
  -d "{\"delivery_boy_id\":$BOY_ID,\"drop_instructions\":\"Call before arriving\"}" | field status

step "Delivery boy: my orders"
curl -s "$BASE/my/orders" -H "$B" | cut -c1-300; echo

step "Delivery boy: start (Pending -> OutForDelivery)"
curl -s -X POST "$BASE/my/orders/$ORDER_ID/start" -H "$B" | field status

step "Delivery boy: deliver with bill photo"
PHOTO=$(mktemp --suffix=.png)
# 1x1 PNG
echo 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC' | base64 -d > "$PHOTO"
curl -s -X POST "$BASE/my/orders/$ORDER_ID/deliver" -H "$B" \
  -F amount_collected=360 -F payment_mode=UPI -F "bill_photo=@$PHOTO" | field status
rm -f "$PHOTO"

step "Admin: stats"
curl -s "$BASE/admin/stats" -H "$A"; echo

echo
echo "Done. Reuse in your own curl calls:"
echo "  export ADMIN_TOKEN=$ADMIN_TOKEN"
echo "  export BOY_TOKEN=$BOY_TOKEN"
