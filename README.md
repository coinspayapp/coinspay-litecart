# CoinsPay for LiteCart

Accept cryptocurrency payments in your LiteCart store. Buyers pay on the CoinsPay payment page, and the money goes on to your own wallets.

## Requirements

| | Supported | Tested on |
|---|---|---|
| LiteCart | 2.5 to 2.7 | 2.5.5, 2.6.3, 2.7.2 |
| PHP | what your LiteCart runs on: 7.0 to 8.5 (the module itself needs 5.6+) | 7.0, 8.1, 8.5 |

You also need a [CoinsPay](https://coinspay.app) account with a shop.

## Description

The module adds a "Cryptocurrency" payment method to the checkout. When the buyer confirms the order, the module saves it with the "Awaiting crypto payment" status (the stock is reserved), and the buyer goes to the CoinsPay payment page, picks a coin and a network and pays. Once the payment is confirmed, the order gets your "Paid" status, the payment date and the CoinsPay invoice as its transaction, and the buyer comes back to the "order placed" page.

Prices stay in your store currency. CoinsPay converts the total at the current rate and holds the coin amount for 30 minutes, so the rate does not change while the buyer opens their wallet.

### Coins and networks

More than 30 coins and tokens on TRON, Ethereum, BNB Smart Chain, Solana, Bitcoin and Litecoin, among them USDT, USDC, BTC, ETH, BNB, TRX, LTC and SOL. The full list is on [coinspay.app/coins](https://coinspay.app/coins).

### What the module takes care of

- Each invoice event maps to an order status of your choice. A comment with amounts, coins and transaction hashes goes to the order at every event, hidden from the customer.
- An invoice that expires unpaid cancels the order, and its reserved stock is free again.
- When you put an order into a cancel status, the module cancels its open invoice at the next run of its job (every 5 minutes with a cron job, otherwise about once an hour), so the buyer can no longer pay it.
- If a payment does not match the order total, or arrives for an order you cancelled, the order gets the "Crypto payment review" status and a comment for you to review.
- A buyer who closed the payment page can pay later: the "order placed" page shows "Go to payment" while the invoice is open. A buyer who paid on another device gets the "order placed" page of the order when they come back.
- Webhooks are signed and checked. If they do not arrive, a job of LiteCart asks CoinsPay about open invoices.
- The settings page shows the state of the module, checks the connection, gives the webhook address and the test buyer link, and lists the last invoices with a "Refresh" link.
- English and Russian.

### Why CoinsPay

Payments go straight to your wallets. Each network takes up to five payout addresses with percentage shares, for example 70% to you and 30% to a partner, and nothing is kept on the platform.

The fee is as low as 0.3% of each payout: the rate goes down as the monthly turnover of all your shops grows. There is no monthly or setup fee. For the first 10 days after your first payment, payouts up to $500 carry no fee. You can let the buyer pay the CoinsPay fee and the network fee on top of the order total. Details are on the [pricing page](https://coinspay.app/pricing).

## Installation

1. Download `coinspay-litecart.zip` from the [releases](https://github.com/coinspayapp/coinspay-litecart/releases/latest) or the [LiteCart add-on store](https://www.litecart.net/en/addons/914/coinspay-crypto-payments-usdt-btc-eth).
2. Copy the contents of its `public_html` folder into the root folder of your store (the one with `index.php`), keeping the folders. Nothing of LiteCart is replaced: the module adds `includes/coinspay/`, `includes/modules/payment/pm_coinspay.inc.php`, `includes/modules/jobs/job_coinspay.inc.php`, four `pages/coinspay_*.inc.php` and `images/payment/coinspay.png`.
3. In the admin open Modules → Payment, find CoinsPay and press Install, then Save.

On install the module adds two order statuses, "Awaiting crypto payment" (reserves the stock) and "Crypto payment review", its tables `coinspay_invoice` and `coinspay_kv`, and the job "CoinsPay" under Modules → Jobs.

The archive includes the installation guide in [English](docs/install-en.md) (also as its README.md) and [Russian](docs/install-ru.md), and the [upgrade steps](docs/upgrade-en.md) as UPGRADE.md.

## Configuration

Modules → Payment → CoinsPay. The panel at the top of the page shows the state of the module.

1. In the CoinsPay dashboard open your shop:
   - under "API keys" create a key with "Invoices only" access and paste it into "API key". The module needs nothing more, and such a key cannot withdraw money;
   - leave "API address" empty: the module then uses `https://coinspay.app/api/v1`;
   - under "Webhook" enter the address the panel shows, save, and copy the `whsec_…` secret into "Webhook secret".
2. Save. The panel checks the connection and shows the name of your CoinsPay shop.
3. Press "Send test event" in the dashboard: the panel shows when it arrived.
4. Check the status of each invoice event and save.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins move. By default the method is then shown to the test buyer only: open the store with "Open the shop as the test buyer" from the panel (the link works for a day in that browser). A test payment completes only orders of the test buyer.

## When webhooks do not arrive

The job "CoinsPay" asks CoinsPay about open invoices. LiteCart runs its jobs from page visits about once an hour; for checks every 5 minutes add the cron address the panel shows (or LiteCart's own `push_jobs` address) to the cron of your hosting. The webhook and cron address contains "webhook", so the bot challenge and the CSRF check of LiteCart 2.7 let it through.

## Good to know

- The order is saved before the payment, as with any payment page. LiteCart resumes only orders without a status, so a buyer who goes back to the checkout and confirms again gets a new order; the unpaid one is cancelled when its invoice expires.
- LiteCart sends the order confirmation e-mail when the buyer comes back in the browser of the checkout. To e-mail the customer on payment as well, turn on "Notify" for your paid status (Orders → Order Statuses).
- The invoice is made in the order currency when CoinsPay accepts it, otherwise in the store currency.
- The API key and the webhook secret are kept with the other module settings of LiteCart and shown in password fields.
- Uninstall removes the job and the cache; the invoices table and the two statuses stay, orders refer to them.
- The log is `logs/coinspay.log`. Errors are logged always; keys and secrets never.

## Development

```
core/              the core shared with the other CoinsPay modules, without LiteCart calls: decisions,
                   synchronization, settings and statuses, the invoice request, signatures, the API client
core/litecart/     LiteCart side: orders and settings (Store), checkout, return, webhook and polling
                   (Front), settings fields, install and the panel (Admin)
public_html/       what goes into the store: the payment module, the job, the pages, the icon
tests/             unit tests of the core
bin/build.php      builds dist/coinspay-litecart.zip (the core goes to public_html/includes/coinspay)
```

Checks and the build run in Docker:

```bash
P=$(pwd)
docker run --rm -v "$P:/app" -w /app composer:2 install
docker run --rm -v "$P:/app" -w /app php:7.4-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpcs -q
docker run --rm -v "$P:/app" -w /app --entrypoint php composer:2 bin/build.php
```

`phpcs` checks compatibility with PHP 5.6 and later.

## Changelog

### 0.1.2

- The archive follows the layout of the LiteCart add-on store: README.md with the installation guide, UPGRADE.md and LICENSE.md at its root. The module itself is the same as in 0.1.1.

### 0.1.1

- The help of "Order cancel statuses" and the installation guides now say when the invoice of a cancelled order is cancelled: at the next run of the job "CoinsPay", every 5 minutes with a cron job, otherwise about once an hour.

### 0.1.0

- First release.

## License

GNU General Public License v3.0 or later. The full text is in [LICENSE](LICENSE).
