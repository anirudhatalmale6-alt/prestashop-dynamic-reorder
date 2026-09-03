# Dynamic Reorder — PrestaShop

Turns an **existing** homepage reorder banner/button into a dynamic one. Instead of a
hard-coded `id_order`, it resolves the **last order of whoever is logged in right now**,
loads it into their cart using PrestaShop's own native reorder logic, shows a
confirmation pop-up, and leaves the customer on the homepage.

Compatible with PrestaShop **1.7.x and 8.x**. Works on the Classic theme and on custom
themes — the pop-up is self-contained (no jQuery, no Bootstrap dependency).

---

## What it does

| Situation | What happens |
|---|---|
| Logged in, has a previous order | Last order is duplicated into the cart. Pop-up: "Order loaded successfully — N products". Header cart refreshes. **No checkout redirect.** |
| Logged in, some products gone | Loads everything still available, pop-up says how many of how many made it. |
| Not logged in | Pop-up with **Log in** and **Create an account**. After logging in, the customer lands back on the homepage and the order loads **automatically** — no second click. |
| Logged in, never ordered | Pop-up: no previous order found. |

It reuses PrestaShop's native code path — `Cart::duplicate()` + `CartRule::autoAddToCart()`,
exactly what `OrderController` runs for `?submitReorder&id_order=…` — minus the
`Tools::redirect('index.php?controller=order')` that sends the customer to checkout.

---

## Install

1. Back office → **Modules → Module Manager → Upload a module** → pick `dynamicreorder.zip`.
2. Open its **Configure** screen.
3. Set the **Button / banner CSS selector** to match the button you already placed.

Or by FTP: upload the `dynamicreorder/` folder into `/modules/`, then install it from
the Module Manager.

## Connecting your existing button

Two ways — either is fine.

**Option A — CSS selector (recommended, gives the pop-up).**
Give the banner an id or class and type it into the config field:

```html
<a href="#" id="dynamic-reorder">Reorder my last order</a>
```
→ selector: `#dynamic-reorder`

Several selectors can be comma-separated. Clicks are captured by delegation, so buttons
injected later by a slider or another module still work.

**Option B — direct link (works with JavaScript disabled).**
Point the banner's `href` at:

```
https://your-shop.com/index.php?fc=module&module=dynamicreorder&controller=reorder
```

The customer is sent to login if needed, the order loads, and they come back to the
homepage with the same pop-up. Still no checkout redirect.

---

## Settings

| Setting | Notes |
|---|---|
| Button / banner CSS selector | id/class of your existing button. |
| Which order counts as "the last order" | Last **valid** (paid) order / last valid then fall back to any / last order of any status. Cancelled and refunded orders are not "valid" in PrestaShop. |
| If the cart is not empty | **Replace** (native behaviour, carries product customisations) or **merge** on top of the current cart. |
| After the pop-up is closed | **Reload** the homepage (safest — cart block always correct) or stay and only refresh the cart block via `prestashop.emit('updateCart')`. |
| Load on every page | Off = homepage only. On if the button also sits in the header/footer. |
| All pop-up titles and messages | Fully editable, so you can write them in your shop's language. |

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

## Files

```
dynamicreorder.php                     module, hooks, back-office config
controllers/front/reorder.php          resolves the last order + loads the cart
views/js/dynamicreorder.js             click binding, AJAX, pop-up, cart refresh
views/css/dynamicreorder.css           self-contained pop-up styles
```
