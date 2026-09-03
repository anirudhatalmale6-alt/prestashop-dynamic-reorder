# Dynamic Reorder — PrestaShop

Turns an **existing** homepage reorder banner/button into a dynamic one. Instead of a
hard-coded `id_order`, it resolves the **last order of whoever is logged in right now**,
loads it into their cart using PrestaShop's own native reorder logic, shows a
confirmation pop-up, and leaves the customer on the homepage.

Built for PrestaShop **1.6.1.x** (also runs on 1.7.x / 8.x — every branch difference goes
through a compat check). Every PrestaShop API it calls was checked against the actual
1.6.1.6 source; the logic is covered by `tests/logic_test.php`. It has **not** yet been
run on a live 1.6 shop — see *Status* below. Works on the default theme and on purchased
themes: the pop-up is self-contained, no jQuery and no Bootstrap dependency.

All customer-facing text ships in **Spanish** by default and is editable in the back office.

---

## What it does

| Situation | What happens |
|---|---|
| Logged in, has a previous order | Last order is duplicated into the cart. Pop-up: "Order loaded successfully — N products". Header cart refreshes. **No checkout redirect.** |
| Logged in, some products gone | Loads everything still available, pop-up says how many of how many made it. |
| Not logged in | Pop-up with **Log in** and **Create an account**. After logging in, the customer lands back on the homepage and the order loads **automatically** — no second click. |
| Logged in, never ordered | Pop-up: no previous order found. |

It reuses PrestaShop's native code path — `Cart::duplicate()` + `CartRule::autoAddToCart()`,
exactly what `ParentOrderController::init()` runs for `?submitReorder&id_order=…` (the
parent of both `OrderController` and `OrderOpcController`) — minus the redirect that
leaves the customer on the checkout page.

---

## Install

**PrestaShop 1.6:** Back office → **Modules and Services** → *Add a new module* →
upload `dynamicreorder.zip` → **Install** → **Configure**.

**PrestaShop 1.7 / 8:** Back office → **Modules → Module Manager → Upload a module**.

Or by FTP: upload the `dynamicreorder/` folder into `/modules/`, then install it from the
modules list.

## Connecting your existing button

Two ways — either is fine.

**Option A — paste the link into the banner (easiest, no code).**
If the banner is an image managed from the back office (an AP Page Builder image block,
for example), set its link/URL field to:

```
https://your-shop.com/index.php?fc=module&module=dynamicreorder&controller=reorder
```

The customer is sent to login if needed, the order loads, and they come back to the
homepage with the pop-up. Works with JavaScript disabled too.

**Option B — CSS selector.**
If the banner is HTML you can edit, give it an id or class and type it into the config:

```html
<a href="#" id="dynamic-reorder">REPETIR ÚLTIMO PEDIDO</a>
```
→ selector: `#dynamic-reorder`

Several selectors can be comma-separated. Clicks are captured by delegation, so buttons
injected later by a slider or another module still work.

---

## Settings

| Setting | Notes |
|---|---|
| Enable dynamic reorder | Master on/off switch. Off = the shop behaves exactly as before. |
| Button / banner CSS selector | id/class of your existing button. |
| Which order counts as "the last order" | Last **valid** (paid) order / last valid then fall back to any / last order of any status. Cancelled and refunded orders are not "valid" in PrestaShop. |
| If the cart is not empty | **Replace** (native behaviour, carries product customisations) or **merge** on top of the current cart. |
| After the pop-up is closed | **Reload** the homepage (safest — cart block always correct) or stay and only refresh the cart block via `prestashop.emit('updateCart')`. |
| Load on every page | Off = homepage only. On if the button also sits in the header/footer. |
| All pop-up titles, messages and button labels | Fully editable, Spanish out of the box. |

Message placeholders: `%count%` (products added), `%total%` (products in the order),
`%reference%` (order reference), `%date%` (order date).

---

## Notes

- **Security.** The AJAX endpoint accepts `POST` with an `X-Requested-With` header only —
  a cross-site page cannot set that header, so it is CSRF-safe without embedding a token
  in the HTML (a token would break on full-page-cached homepages). The direct-link
  fallback is a plain `GET`, matching native PrestaShop's own tokenless reorder link.
- **Merge mode limitation.** Product customisations (personalised text / uploaded files)
  cannot be recreated from order lines, so they carry over in *replace* mode only.
- **Deleted address.** If the old order's delivery address has since been deleted, the
  cart is re-pointed at the customer's current first address so checkout does not dead-end.
- **Multistore** is respected via `Shop::addSqlRestriction(Shop::SHARE_ORDER)`.
- **1.6 compatibility.** `registerStylesheet()`/`registerJavascript()` do not exist on 1.6,
  so assets go through `addCSS()`/`addJS()` there. The cart block is refreshed through
  `ajaxCart.refresh()` on 1.6 and `prestashop.emit('updateCart')` on 1.7+. The
  registration link is version-gated: 1.6 has no `registration` controller, so it uses
  `authentication&create_account=1`.
- **Partial detection does not trust `duplicate()` alone.** On 1.6 that method computes
  its success flag with `&=`, and an out-of-stock line returning `-1` leaves the flag
  set, so the module also compares the loaded quantity against the order's own total.

## Files

```
dynamicreorder.php                     module, hooks, back-office config
controllers/front/reorder.php          resolves the last order + loads the cart
views/js/dynamicreorder.js             click binding, AJAX, pop-up, cart refresh
views/css/dynamicreorder.css           self-contained pop-up styles
```

---

## Status

Verified so far:

- Every PrestaShop method the module calls exists in the 1.6.1.6 source with a matching
  signature (`Cart::duplicate`, `Cart::updateQty`, `Order::getCartIdStatic`,
  `CartRule::autoAddToCart`, `Shop::addSqlRestriction`, `Link::getPageLink`, …), and
  `registerStylesheet()` is confirmed absent there, which is what the compat branch is for.
- 1.6's `Controller::run()` calls `postProcess()` before `initContent()`, which is where
  this controller does its work.
- 1.6's `sslRedirection()` skips POST requests, so the AJAX call cannot be bounced into a GET.
- `tests/logic_test.php` executes the controller's own methods against stubs: 15 checks
  pass under 1.6.1.6 and 14 under 8.1.5.
- The pop-up was rendered in a real browser in all four states.

Not yet verified: **an end-to-end run on a live PrestaShop 1.6 shop** with a real customer
and a real order. That needs shop access.

Run the logic tests with:

```
php tests/logic_test.php          # defaults to 1.6.1.6
PSV=8.1.5 php tests/logic_test.php
```
