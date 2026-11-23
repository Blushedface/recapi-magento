# recapi for Magento 2

A Magento 2 module (Magento Open Source or Adobe Commerce 2.4.7 or later
(Adobe Commerce on-premises or on Cloud, PaaS) or Mage-OS 1.0.6 or later, on
PHP 8.2 or later) that counts product views, add-to-carts and orders for
recapi, describes each product to it, and shows recapi's recommendations on
product pages.

Adobe Commerce as a Cloud Service (SaaS) installs no modules: there, paste
the script tag (the dashboard's quick start, *Add the SDK to your site*) for
product views, clicks and the row, and send each order from your own back
end with a server library (recapi's PHP library `recapi/client` or any of
the others).

## Install

recapi serves the module with the PHP library (`recapi/client`) inside. From
the Magento root, with your API's address:

```sh
curl -fLo recapi-magento.zip https://api.<your-domain>/sdk/magento/recapi-magento-0.4.29.zip
unzip -o recapi-magento.zip -d app/code
bin/magento module:enable Recapi_Recommendations
bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
```

(`setup:di:compile` and `setup:static-content:deploy` only in production
mode.)

With Composer, once the module is on Packagist, the same module installs as
`recapi/module-recommendations`, with the PHP library as its dependency
`recapi/client`, and updates with `composer update`:

```sh
composer require recapi/module-recommendations
bin/magento module:enable Recapi_Recommendations
bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
```

Use one or the other, never both: a copy in `app/code/Recapi/Recommendations`
and one in `vendor/recapi/module-recommendations` register the same module
twice, and Magento stops with "Module 'Recapi_Recommendations' … has been
already defined". To move to Composer, delete `app/code/Recapi` before
`composer require`.

Then paste the API address, the public key and the secret key in
**Stores → Configuration → Sales → recapi** (per store view if two store
views are two recapi shops). Until all are filled in, nothing is sent and
the row shows the store's own products. Then send the catalog now rather
than at night:

```sh
bin/magento recapi:sync-catalog
```

## Themes

Luma and its children, and [Hyvä](https://github.com/hyva-themes/magento2-default-theme)
(open source since November 2025, without jQuery) and its children: CI runs
the same end-to-end test on both (below). On Hyvä the add to cart is
counted when the product form is posted, and the cookie notice's accept
reaches the module through Hyvä's `user-allowed-save-cookie` event. Not
tested: Hyvä Checkout (paid; orders are sent from the server whatever the
checkout, so only its Content-Security-Policy is unknown) and third-party
Ajax add-to-cart modules for Hyvä, whose add to cart posts no form and fires
no jQuery event: those carts go uncounted (views and orders still count).

## The recommendation row

On product pages, recapi's row ("You may also like") takes the place of
Magento's *Related Products* (`catalog.product.related`, Hyvä's `related`): two rows of
suggestions split the clicks, and recapi's lift then takes longer to show.
The up-sells stay. The row loads only as the shopper scrolls within 400px of
it (each list is billed and counted as shown), with up to 4 products that are
visible in the catalog and in stock, linked with `#<recommendation id>` so the
click is credited even without consent. A fragment never reaches a server, so
the click gets the page from the full-page cache: a `?rec=` got its own cache
entry per list (on a test store, 7 of 7 clicks hit the cache against 0 of 7). A
card uses `?rec=` when its address has a fragment of its own or is the page
already open; the page still reads it. The row asks through the JS library's `recommendLists()`,
the call that takes all of a page's rows (billed as one recommendation, no
product on two rows): a product page's two rows cost one call.

- **4 asked, 4 shown.** recapi counts every product of a list as shown, so
  the row asks for the 4 it draws: a spare would get an impression and could
  be credited a view it never caused. A product recapi lists that the store
  no longer shows or sells (changed since the last sync) leaves a gap: a full
  list that leaves one is topped up once with one more call, for the rows
  left short only, of the missing count, without the products already listed
  (not after the popular fallback, nor after a list shorter than 4, which is
  all recapi has). A top-up refused (a key revoked mid-page) keeps the cards
  already drawn. Up to 0.4.19 the row asked for 6, so 2 of every full list
  were logged as shown unseen.
- **One placement per row.** The row asks as `product` on product pages,
  `cart` on the cart page and `block` anywhere else a theme's layout adds
  the `Recapi\Recommendations\Block\Recommendations` block, so the
  Overview's "By placement" and a lift test tell them apart. A placement not
  configured in the dashboard serves as `default`, with its settings and its
  running lift test: a store that set up `default`, or started a test on it,
  keeps both after the update. Up to 0.4.19 every row was `default`.

- **Never empty.** The page is printed with the store's own products in the
  row: the product's related products and up-sells, then other products of
  its categories. recapi's cards replace them once it has some; recapi
  unreachable or not set up yet, they stay. With neither, the row hides,
  title included.
- **Cards.** A recommended item's [metadata](https://recapi.io/docs/api#item-metadata)
  draws its card when it has a `name` and an http(s) `url` (and `picture`,
  `price`: a number in the store's base currency, shown only to visitors
  browsing in it, or text shown as it is): the module sends it for every
  product (below). The others come from the store in
  one request, `GET /recapi/cards?ids=12,7` (public, product ids only), with
  the visitor's currency and the theme's `related_products_list` image.
- **Theme.** Restyle it with rules for `#recapi-recommendations` (`a`, `img`,
  `.recapi-price`): the default rules weigh nothing. Rename it with the
  layout argument `heading`, move it with `<move element="recapi.recommendations"
  destination="…"/>`, or bring Magento's back with your theme's
  `catalog_product_view.xml`:

```xml
<referenceBlock name="recapi.recommendations" remove="true"/>
```

### Often bought together

Since 0.4.22 a product page has a second row under the first, "Often bought
together": what shoppers carted or bought with the product, asked for in the
same call as the first row, under the placement `bought_together`. It needs
no setup: until you give that placement settings of its own in the
dashboard, recapi serves it as the Bought together list type.

- **Off switch.** Stores → Configuration → Sales → recapi → Rows → *Often
  bought together*: No. It is on by default, per store view.
- **Only what recapi has.** It is printed hidden and empty, and shows only
  once recapi has products for it: the store has no bought-together products
  of its own to fill it, and the popular fallback (recapi down or at a
  limit) is not what its title promises. Either row empty or failing never
  hides the other.
- **Theme.** Rules for `#recapi-bought-together`, the layout argument
  `heading` on `recapi.bought_together`, or
  `<referenceBlock name="recapi.bought_together" remove="true"/>`.

## What it tells recapi about products

With the secret key, the module describes the **default store view**'s
products to recapi itself, with no code to write:

- **Each product saved or deleted** (admin, REST API, scripts: an observer
  on `catalog_product_save_after` and `catalog_product_delete_after`) is
  sent once the response is out, when the save is committed and the admin
  form's stock saved: one `PUT /v1/items/{id}`, or one `POST /v1/items:batch`
  when a request changed several. One try of at most 2 s: a recapi outage
  never slows or fails a save (the error is logged once), and the nightly
  sync catches up.
- **The whole catalog** at 03:17 every night (Magento cron, job
  `recapi_sync_catalog`), and whenever you run
  `bin/magento recapi:sync-catalog` (after an import, say): mass attribute
  updates, imports and stock changes save no product one by one. 1,000
  products a request, at most ~5.5 MB with every field at its limit, within
  the API's 8 MB; retried like the library's calls. A batch recapi refuses
  doesn't stop the others: the command then fails, naming the refusal, and
  the cron job logs it once. recapi writes and bills
  only the items that changed (one `item_write` each), so the nightly send of
  an unchanged catalog costs nothing.

Each product goes as a complete item, since the API replaces it whole:

- `active`: enabled, visible in the catalog, in the default store view's
  website and salable (in stock). recapi never recommends the others: no
  sold-out or disabled product in the row, even before a click shows it.
  A product just saved also counts as in stock when its own stock says so,
  so a new product is active at once on a store whose indexers run "Update
  by Schedule" (the default), before its next cron run.
  A deleted product goes inactive, keeping its name.
- `title`: the product's name (cut to 512 bytes).
- `tags`: its categories' names in the API's alphabet ("Rain Coats &
  Jackets" → `rain-coats-jackets`), at most 32; store roots left out.
- `published_at`: its creation date, so a product that was never viewed
  before the module came doesn't count as new (left out when the date is no
  real one, as an old import's `0000-00-00`).
- No `boost`: every send sets it back to 0, so a boost you set through the
  API lasts only until the product's next save or the nightly sync.
- `metadata`: `name`, `url`, `picture` (the theme's `related_products_list`
  image, as the row's cards) and `price` (the final price as the store shows
  it, taxes included or not as it displays them, in the base currency). Only
  http(s) links. Over the API's 2,048 bytes, the picture goes first, then
  the name is cut; a link too long for any name leaves only the price, and
  the row then draws that product from the store.

Other store views are not sent: one recapi environment per store view stays
yours to set up (the keys are per store view, the catalog the default one's).

## What it counts

The product id is the item id (a configurable product's parent id, as its
page and its order line name it).

- **Views and add-to-carts**, from the visitor's browser: a block in
  `before.body.end` prints, on every storefront page, the page's values and
  an import of `magento.js`, the storefront code recapi's API serves beside
  its JS SDK. From 0.4.25 a fix of the row, consent or these events reaches
  every store within a day, with nothing to install again; the module's PHP
  still updates with the module. A `view` on product pages, with `#<recommendation id>` from
  a card's link (then removed from the address, and sent only once a page the
  browser prerendered is shown). A `cart` on Luma's
  jQuery `ajax:addToCart` (product page and category buttons, with the
  products' ids), or, on themes without jQuery such as Hyvä, when a form
  posting to `checkout/cart/add` is submitted and no script stopped it.
  The full-page cache serves a page's HTML to every visitor, so it holds
  only what is the same for all: the keys, the address and the product id.
  Magento's Content-Security-Policy (enforced on checkout's payment pages
  since 2.4.7, reported elsewhere) allows the API's origin in `script-src`
  and `connect-src` for each store view with its keys set (`magento.js` and
  the SDK both come from there, so nothing more is needed): the module adds
  it to the policies Magento already has (or to `default-src`'s, for a
  directive the store doesn't set), so no `csp_whitelist.xml` is needed.
  Its collector is declared in `etc/di.xml`, not the storefront's: an area's
  array argument replaces Magento's instead of joining it, and up to 0.4.3
  that left the storefront with no policy at all.
- **Orders**, from the server: an observer on
  `sales_model_service_quote_submit_success` (global: Luma's checkout
  places orders through the REST API, headless shops through GraphQL)
  sends a `purchase` per order line with the PHP library
  and the secret key, once the response is out, one try of at most 2 s.
  A buyer who blocks scripts or never comes back from a payment page still
  counts, each order counts once, and a recapi outage never fails a
  checkout (the error is logged). The buyer is the customer id when logged
  in; the visitor's ids come from the `recapi` cookie below; a guest without
  it gets `magento-order-<increment id>`, so the basket still trains as one.
  An order placed in the admin uses no cookie. From 0.4.29 each purchase
  carries what the line paid (`value`, its row total less its discount,
  without tax or shipping) in the order's currency, for the sales figure of
  a lift test; a line without a price is sent without an amount.

The module names itself, so recapi words its setup hints for Magento: the
storefront script sends `?platform=magento/<version>`, the catalog calls
`User-Agent: recapi-magento/<version>` (the order observer's PHP library sends
its own `recapi-php/<version>`).

## Do Not Track

A visitor whose browser sends Do Not Track or Global Privacy Control is
followed nowhere: the storefront's script stores nothing on their device
and sends no event about them (the JS SDK's
default); and when their browser places the order (the request carries `DNT: 1` or `Sec-GPC: 1`), the observer sends
nothing about it. An order placed in the admin is sent whatever the
admin's browser says: it speaks for the admin, not the customer.

## Consent and the cookie

Magento's **Cookie Restriction Mode** (Stores → Configuration → General →
Web → Default Cookie Settings) is the consent switch, as for Magento's own
Google Analytics: on, nothing is stored on the visitor's device until they
accept the cookie notice (`user_allowed_save_cookie` for this website);
events still go out, without a visitor id. Off, the store has decided it
needs no notice, and the script stores the visitor's state at once.

So recapi's promise that its script stores nothing until the visitor consents
holds on Magento only with Cookie Restriction Mode on. Check the setting before
you go live: a store whose consent banner comes from a third-party module that
doesn't drive this mode stores from the first page, whatever that banner says.

With consent, each time the SDK saves the visitor's state the script mirrors
what an order needs into a first-party `recapi` cookie (a day,
`SameSite=Lax`): the SDK's visitor id and, for each product the visitor
clicked in a recommendation list, that list's id, as the SDK would tag the
purchase itself. The server checks every id read from it. Deleting the
notice's cookie deletes the `recapi` cookie on the next page.

## Errors and troubleshooting

A recapi outage or refusal never breaks a page, a save or a checkout: the row
keeps the store's own products, and product and order sends log the error
once (the nightly sync catches up). `bin/magento recapi:sync-catalog` fails,
naming the refusal, when recapi refuses a batch.

- [Errors](https://recapi.io/docs/errors): each error code, its cause and fix.
- [Troubleshooting](https://recapi.io/docs/troubleshooting): no lists yet,
  empty lists, only popular items, key errors.
- [Magento guide](https://recapi.io/docs/magento): install, the first ten
  minutes, the usual problems.

## Tests

`sdk/js/test/magento.test.js` runs the module with `sdk/js/test/magento.php`
standing in for Magento (through `scripts/php.sh`, no Magento install): the
printed script runs against the SDK, the row is printed from a stand-in
catalog and filled from a recommendation response or kept as printed, and
the observer sends orders through the PHP library to a local stand-in for
the API, as the product observer, the command and the cron job send
products, and the CSP collector runs on stand-in policies merged as
Magento merges them. `server/internal/magentosdk` checks the served zip.

## End to end on a throwaway Magento

CI's `magento` job runs `scripts/e2e_magento.sh` on every push and pull
request (Docker and python3 are all it needs, so it runs on a laptop too),
on the newest Magento and the oldest the module declares: 2.4.9 (Mage-OS
4.0.0) on PHP 8.5, and 2.4.7 (Mage-OS 1.0.6) on PHP 8.2, both on Luma, and
2.4.9 on Hyvä (`RECAPI_E2E_MAGENTO_THEME=hyva`, image built with
`THEME=hyva`: Hyvä's GitHub repositories at the commits pinned in
`scripts/e2e/magento-hyva.txt`, with Luma's checkout). Each line is
a new Mage-OS store with MariaDB and OpenSearch, the module installed with
the quick start's lines (`setup:di:compile` included) or, on the Hyvä line,
with `composer require` as Packagist would serve it
(`RECAPI_E2E_MAGENTO_INSTALL=composer`: the release service's splits of the
module and the PHP library, `scripts/e2e/magento_composer.py`), and the
keys set, then
`scripts/e2e/magento.test.js` in a browser: products saved through the REST
API and the admin's form reach `GET /v1/items/{id}` with their metadata, a
product page counts a view and shows recapi's row, a card's click is
credited, the add to cart counts, the checkout's payment step enforces a
policy that allows the API, and a guest order sends one purchase credited to
the clicked recommendation; then, with Cookie Restriction Mode on, a new
visitor's accept in the cookie notice lets the module store their ids on
that same page. A PHP warning or deprecation that the store's
logs trace to the module's files fails it too. To build the image for the
floor line by hand: `docker compose -f docker-compose.yml -f
scripts/e2e/magento.compose.yml --profile magento build --build-arg
MAGEOS=1.0.6 --build-arg PHP=8.2.35 magento`.

The steps below cover what the job doesn't (production mode, the full-page
cache, the Luma checkout's own form, multishipping,
admin and logged-in orders), by hand on a real store wherever Docker runs.
Use a fresh recapi environment and a store you can throw away.

1. Start Magento Open Source 2.4.7 or later (or Mage-OS) in Docker with its
   sample data, for example with
   [`markshust/docker-magento`](https://github.com/markshust/docker-magento)'s
   one-line setup, then `bin/magento sampledata:deploy && bin/magento setup:upgrade`.
2. Install the module as above, in **production mode**
   (`bin/magento deploy:mode:set production`): `setup:di:compile` must finish
   without an error naming `Recapi`. `bin/magento module:status
   Recapi_Recommendations` says enabled.
3. Paste the keys from recapi's quick start. Open a product page: the row
   shows the store's products until recapi has data, and recapi's
   **Live events** shows the `view`. Turn the full-page cache on
   (`bin/magento cache:enable full_page`) and open it again in a private
   window: same row, a new visitor.
4. Add the product to the cart from the product page and from a category
   page: one `cart` each on Live events.
5. Turn on **Cookie Restriction Mode**: in a new private window nothing is
   in localStorage and no `recapi` cookie until you accept the notice (Luma's
   `user:allowed:save:cookie` event, Hyvä's `user-allowed-save-cookie`); after
   it, both appear without a reload.
6. CSP: open the checkout's payment step with the
   browser's console open. Magento 2.4.7+ enforces its policy there: the
   response's `Content-Security-Policy` header names the API's origin in
   `script-src` and `connect-src`, and the console shows no violation for it.
7. Save a product in the admin, then `curl -H "Authorization: Bearer
   <secret key>" <API>/v1/items/<product id>`: its name, link, picture and
   price are in `metadata`. Run `bin/magento recapi:sync-catalog`: it prints
   how many products recapi stored; mark a product out of stock, run it
   again, and that item comes back `"active": false`.
8. Wait for the first training run, within the hour after events arrive
   (the Overview's *Personalised models* line then says "last trained"),
   reload the product page, click a card (the address ends
   in `#rec_…`, then loses it), add to cart and place an order as a guest
   with *Check / Money order*. Live events shows a `purchase` per order line,
   sent by the server, with the card's recommendation id, and the
   Overview's *By placement* counts it as a conversion of `product`: the
   measurement this test exists for. The row's
   cards come from metadata: the browser's network tab shows no
   `/recapi/cards` request.
9. Also: an order placed through *Check Out with Multiple Addresses* (one
   purchase per order line of each order), an order placed from the admin
   (no visitor ids), and a logged-in customer's order (`user_id` is the
   customer id).

Report what failed on the board, with the Magento version.
