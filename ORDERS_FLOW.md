# ORDERS_FLOW.md: everything left to build

> **For any AI reading this:** these are final decisions made by the project owner. Do not redesign them. Read the existing code first (CartController, models, routes/api.php, tests) and follow its style. Work on ONE step at a time. The owner writes the code himself: review it against these rules, explain mistakes, and only write code if he asks.

**Current step:** Step A (store: validation + ownership check). Update this line after every commit.

## Where we are
Done and tested: Auth, Products (soft delete), public index/show, Cart (GET, POST, PATCH, DELETE), `orders` and `order_items` tables, `Order` and `OrderItem` models, `OrderController` index/show, `SellerOrderItemController` index/update.

## Prerequisites (check once, before Step B)
- `order_items.status` has `->default('pending')` in the migration.
- `Order` has `#[Fillable(['total_price','shipping_address','shipping_phone','recipient_name'])]`.
- `OrderItem` has `#[Fillable(['product_id','seller_id','product_name','quantity','price_at_purchase'])]`.
- `User` has `orders()` (hasMany).
- `OrderItem::product()` is `belongsTo(Product::class)->withTrashed()`.
- `cart_items` has `unique(['cart_id','product_id'])`.
- `Order`/`OrderItem` use `HasUlids` (ids are ULIDs).

## Routes (final, all inside `auth:sanctum`, prefix v1)
```
GET    /orders
GET    /orders/{order}
POST   /orders
PATCH  /orders/items/{item}/cancel          (buyer cancels)
GET    /seller/order-items
PATCH  /seller/order-items/{item}           (seller: shipped / delivered)
PATCH  /seller/order-items/{item}/cancel    (seller cancels)
```
Only register a route when its controller method is written. A route that points at an empty method returns 200 and does nothing.

## Step A: POST /orders skeleton (no order is created yet)
1. Validate: `item_ids` required|array|min:1, `item_ids.*` required|string|distinct, `recipient_name` required|string|max:255, `shipping_phone` required|string|max:30, `shipping_address` required|string|max:255.
2. Get the user's cart with `firstOrFail()`.
3. Get the requested items from INSIDE that cart: `$cart->cartItems()->with('product')->whereIn('id', $request->item_ids)->get()`.
4. If fewer items came back than ids were sent: `abort(404)`.
5. Temporarily return the items as JSON.

Postman (headers: `Authorization: Bearer <token>`, `Accept: application/json`, `Content-Type: application/json`; body: raw JSON):
- Valid item id, all 3 shipping fields: 200, returns the item.
- Two valid ids: 200, returns both.
- Non-existent id: 404.
- Another user's item id (that user MUST have a cart, otherwise the 404 comes from the missing cart, not from the check): 404.
- User with no cart: 404.
- Missing `recipient_name`: 422.
- `item_ids` empty or missing: 422.
- `item_ids` with the same id twice: 422.
- No token: 401.

Commit.

## Step B: POST /orders for real
Rules:
1. First move the cart's `unavailableReason()` into the `CartItem` model as a public method (`$item->unavailableReason()`), and make `CartController` call it. Run the tests: the cart tests must still pass.
2. Everything below runs inside `DB::transaction`.
3. Re-read the products with `lockForUpdate()` (two buyers of the last unit must not both succeed). Put each locked product on its item with `setRelation('product', ...)` so every check uses fresh data.
4. For each item: `$item->unavailableReason()` (unavailable / closed / out of stock / only N left), plus a new reason: `"your own product"` if `product->user_id` equals the buyer.
5. If ANY item fails: create nothing, change nothing, return 422:
   `{"message": "Some items cannot be purchased.", "items": [{"item_id": "...", "product_name": "...", "reason": "..."}]}`
6. If all pass:
   - `total_price` = sum of `price * quantity` from the locked products, rounded to 2 decimals.
   - Create the order from the user's relation, with the shipping fields and `total_price`.
   - For each item create an `order_item` through the order's relation: `product_id`, `seller_id` (= product's `user_id`), `product_name`, `quantity`, `price_at_purchase` (= current product price).
   - Decrease the product's stock by the quantity.
7. The cart is NOT touched.
8. Return 201 with the order and its `orderItems`.
9. Never trust prices, `seller_id` or `status` from the request. Never use `$request->all()`.

Postman:
- Happy path: 201. Check the DB: one `orders` row, one `order_items` row per item, `status = pending`, product stock decreased, cart items still there.
- Quantity above stock: 422 with the item list and `"only N left"`; DB unchanged.
- Closed product: 422 `"closed"`. Hidden product: 422 `"unavailable"`.
- The buyer's own product: 422 `"your own product"`.
- Two items, one of them failing: 422, and the good item's stock is unchanged (all or nothing).
- After a successful order, change the product's price with PUT: `GET /orders/{id}` still shows the old `price_at_purchase`. Rename the product: `product_name` in the order is unchanged.

Commit.

## Step C: buyer reads (index/show already written)
Postman:
- `GET /orders`: only my orders, newest first, paginated.
- `GET /orders/{id}` with my id: 200 with items.
- `GET /orders/{id}` with another user's order id: 404.
- No token: 401.

Commit.

## Step D: seller reads and moves status (already written, now testable)
Postman (use a seller token whose product was bought by another user):
- `GET /seller/order-items`: shows my items with the buyer's name, phone and address from the order. A user who sells nothing gets an empty list.
- `?status=pending` filters. `?status=abc`: 422.
- `PATCH` `{"status": "shipped"}` on a pending item: 200.
- `PATCH` `{"status": "delivered"}` on a shipped item: 200.
- `PATCH` `shipped` again, or `delivered` on a pending item: 422.
- `{"status": "cancelled"}` here: 422 (cancel has its own endpoint).
- Another seller's item id: 404.

Commit.

## Step E: cancel (buyer and seller share one piece of logic)
Rules:
- Put the logic in ONE place, `App\Services\OrderItemCancellationService` (a method that takes an `OrderItem`). Both controllers find the item with their own ownership rule and then call it.
- Inside a transaction, lock the item row (`lockForUpdate`) so a double click cannot restore the stock twice.
- Only a `pending` item can be cancelled. Any other status: 422.
- Set `status = cancelled` and give the quantity back to the product's stock, including soft-deleted products (`Product::withTrashed()`).
- Buyer endpoint: find the item through the order's owner: `OrderItem::whereHas('order', fn ($q) => $q->where('user_id', me))->findOrFail($id)`. Not mine: 404.
- Seller endpoint: `OrderItem::where('seller_id', me)->findOrFail($id)`. Not mine: 404.
- The order's `total_price` is not changed.

Postman:
- Cancel a pending item as the buyer: 200, status cancelled, stock back.
- Cancel the same item again: 422, stock not increased twice.
- Cancel a shipped item: 422.
- Another user's item: 404. Seller cancels their own pending item: 200, stock back.

Commit.

## Step F: tests
After EACH step, give an AI the prompt below with that step's Postman list as the cases.

```
Add feature tests for <endpoint> to my Laravel API project. Follow the style of the existing tests. Read the controller, routes/api.php and ORDERS_FLOW.md first (the step's rules are the source of truth). Test these cases: <paste the Postman list>. Do not change application code. Run the whole suite. If a test fails because of an app bug, do not fix it, report the failing test and what you think is wrong.
```

If the AI wants to change app code to make a test pass, stop and check the rule first. The test must follow the rules, not the other way around.

## Step G: finish line
1. `./vendor/bin/pint`, then the full test suite.
2. README: what the project is, the flow, how to run it with Sail, the endpoints list, a few screenshots.
3. Export the Postman collection into the repo.
4. After the core: categories (admin only, one level) plus search by name and filter by category, then `/my/products` (the seller sees hidden products).
5. Optional last: Reviews.

## Pending small things (do not forget)
- Tests for soft delete: deleting a product removes it from carts, and deleting a product that is in an order works.
- Try soft delete once by hand in Postman: the product disappears from `GET /products`, the row stays in the DB with `deleted_at`.

## Rules for getting unstuck
1. Do not redesign. If something is not in this file, decide in one sentence and write it here.
2. Stuck on an error? Paste the error and the method into any AI and say: "read ORDERS_FLOW.md, I am on Step X".
3. Commit after every green step. Never carry two broken things at once.

## Common errors
| Error | Usual cause |
|---|---|
| `Unknown column '01...'` | an array without `=>` (values with no column names) |
| 405 where you expected 200 | the route path or method does not match (`/cart/item` vs `/cart/items`) |
| 404 where you expected 401 | the route is not registered, so the middleware never ran |
| `Call to a member function ... on null` | the route is not behind `auth:sanctum`, or a relation returned null |
| foreign key error on migrate | `foreignId` vs `foreignUlid` does not match the other table's id type |
| 404 passing for the wrong reason | the test user has no cart, so `firstOrFail` fails before the real check |
