# CoinsPay for LiteCart: installation

The module takes cryptocurrency payments through CoinsPay: the buyer picks a coin and a network on
the CoinsPay payment page, and the order is marked paid by itself once the payment arrives. The money
goes straight to your wallets.

It works on LiteCart 2.5 to 2.7. You need a CoinsPay account with a shop.

## Installation

1. Copy the contents of the `public_html` folder of the archive into the root folder of your store
   (the one with `index.php`), keeping the folders. No file of LiteCart is replaced.
2. In the admin open Modules → Payment, find CoinsPay, press Install, then Save.

The module adds two order statuses: "Awaiting crypto payment" (the order waits for the payment, its
stock is reserved) and "Crypto payment review" (on hold, for you to decide). It also adds the job
"CoinsPay" under Modules → Jobs: it checks open invoices when a webhook does not arrive.

## Connection

Modules → Payment → CoinsPay. The panel at the top shows the state of the module.

1. In the CoinsPay dashboard open your shop.
2. Under "API keys" create a key with **"Invoices only"** access and paste it into "API key". The
   module needs nothing more, and such a key cannot withdraw money.
3. Leave "API address" empty: the module then uses `https://coinspay.app/api/v1`.
4. Under "Webhook" enter the address the panel shows, save, and copy the `whsec_…` secret into
   "Webhook secret".
5. Save. The panel checks the connection: it shows the name of your CoinsPay shop and the mode.
6. Press "Send test event" in the dashboard: the panel shows when the event arrived.

## Order statuses

Each invoice event does something to the order; the fields "Order placed…", "Paid"… pick the status:

| Invoice event | Default status |
|---|---|
| Order placed, waiting for the payment | Awaiting crypto payment |
| Payment on its way, partially paid | not changed (a comment only) |
| Paid (on time, late, with overpayment, marked paid by hand) | your first status in the Processing state; the payment date and the transaction are recorded |
| Expired unpaid, invoice cancelled | Cancelled: the reserved stock is free again |
| Amount differs, the paid order was cancelled, a test payment not by the test buyer, expired or cancelled partially paid | Crypto payment review |

A status field left empty keeps the order status and writes a comment only; for "Paid after the
invoice expired" and the like it means "the same as Paid". A comment with amounts, coins and
transaction hashes goes to the order at every event, hidden from the customer.

When you put an order into one of the "Order cancel statuses", the module cancels its open invoice
at the next run of the job "CoinsPay" (every 5 minutes with a cron job, otherwise about once an
hour), and the buyer can no longer pay it.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins
move. By default the method then shows only to the test buyer: open the store with "Open the shop as
the test buyer" from the panel. The link works for a day in the browser you open it in. A test
payment completes only orders of the test buyer.

## When webhooks do not arrive

The job "CoinsPay" asks CoinsPay about open invoices. Without a cron job LiteCart runs its jobs
from page visits about once an hour. For checks every 5 minutes add the cron address the panel
shows to the cron of your hosting.

## Good to know

- The order is saved before the payment. If the buyer goes back to the checkout and confirms again,
  LiteCart makes a new order; the unpaid one is cancelled when its invoice expires.
- LiteCart sends the order confirmation e-mail when the buyer comes back in the browser of the
  checkout. To e-mail the customer on payment too, turn on "Notify" for your paid status.
- A buyer who closed the payment page can pay later: the "order placed" page shows "Go to payment"
  while the invoice is open.
- The invoice is made in the order currency when CoinsPay accepts it, otherwise in the store currency.
- The log is `logs/coinspay.log`. Errors are logged always; keys and secrets never.
