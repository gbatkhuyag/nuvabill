# Resource options (sliders)

Resource options let clients add more CPU cores, RAM, disk space, IP addresses and other extras to a plan with
sliders. They choose them when they order, and they can change them later on their service page. Each step has a
fixed price for each billing cycle and currency, plus an optional setup fee.

Nuvabill does the billing. The server module sets the new size on the server after payment.

## What clients see

- **On the product page**: a **Customize your server** box under the plan, with one slider for each option (an option
  with one step shows as an on/off switch). The price of each step is printed next to it, and the **Extras** total
  changes as the client moves a slider. The page works without JavaScript too.
- **In the cart, at checkout and on the invoice**: each extra is its own line, for example "RAM +2 GB". A setup fee has
  its own line.
- **On the service page**: a **Resources** card lists the extras the service has and their price. With **Upgrade
  resources** the client moves the sliders, sees the price for the days left in the billing period, and pays.
- **On renewal**: the renewal invoice has the plan line and one line for each extra. The **Billing** total on the
  service page is the plan plus add-ons plus extras.

## Which server modules set them on the server

| Module | What clients can add |
|---|---|
| Virtualizor 2.0 | CPU cores, RAM, disk, IPv4 and IPv6 addresses, monthly traffic, IPv6 subnets and network speed |
| Proxmox VE 1.1 | CPU cores, RAM and disk (disk only when the product has a disk size) |
| Dedicated servers | Extra IPv4 addresses and monthly traffic (see [Dedicated servers](hardware.md)) |
| Hetzner Cloud (Marketplace) | A bigger server type, an extra disk, extra IPv4 addresses, daily backups and snapshot slots |
| No module (staff set the service up by hand) | CPU cores, RAM, disk, IPv4, IPv6, traffic and backups. Your team sets them on the server by hand. |

Other modules (such as cPanel or SolusVM) cannot sell resource options yet; their options page says so. On a product
with no module, Nuvabill emails your team a list of what to change, and staff press **Mark done** when it is set.

## Adding an option to a product

1. **Products** → open the plan → **Resource options**. Save a new product first; the tab shows after that.
2. Press **Add option** and choose the kind, for example RAM.
3. Fill in the form. The **What clients see** preview on the right follows the form as you type.
4. Press **Save option**.

| Field | What it does |
|---|---|
| **Name shown to clients** | Optional. Empty uses the kind's name, such as "RAM". |
| **Short description** | Optional. Shown next to the slider, for example "For busy websites". |
| **Each step adds** | The size of one step, for example 1024 MB of RAM (you can type GB). The module may ask for a smallest step or a multiple. |
| **Included in the plan** | Optional. Shown to clients ("The plan includes 4 GB"). **Read from server** fills it in from the plan on the server where the module can. |
| **Fewest extra steps**, **Most extra steps**, **Steps chosen at first** | The slider range on the order form. Clients must take at least the fewest steps. |
| **Stock** | Optional. Extra steps for all clients together. Empty means no limit. |
| **Clients can add more later** | Clients can raise the slider on their service page. Off means "only at order". |
| **Clients can remove extras** | Clients can lower the slider. Always off for disk space, because a disk can only grow. |
| **Show in the store and client area** | Off hides the option. Services that have it keep it and keep paying for it. |
| **Price for each step** | A grid with one tab for each currency. Leave a price empty when that billing cycle is not offered; 0 means free. **Fill other cycles from Monthly** and **Fill from (main currency) at today's rate** fill the grid for you to check. |

The form warns you while you type, for example when a billing cycle has no price ("clients who pay Yearly do not see
this option"), or when the most steps are more than the server type allows.

Once a service uses an option, its kind and step size are locked. To sell another step size, add a new option.
Options are listed in the order clients see them; use **Move up** and **Move down**. An option can be deleted only
while no service uses it; hide it instead.

## Prices and billing rules

- **Price per step.** An option costs its step price times the number of steps, for the billing cycle of the service.
  The setup fee is charged once for each step a client adds, at order or later. It is never refunded.
- **Coupons** take money off the plan price only. They never discount extras, the same as add-ons.
- **Tax** follows the product: if the product is taxed, its extras are taxed too.
- **Currencies.** Prices are set per currency. An option with no price in the client's currency is not offered to
  that client. Nuvabill does not convert prices when it sells; the grid only helps you fill them in.
- **Existing services keep their price.** When you change an option's price, services that already have the extra
  keep paying what they paid. New steps are sold at today's price.
- **A plan with only extras to pay** still renews.

### Changes on a live service

- **Adding steps.** The client pays today's price for the days left in the billing period, plus the setup fee for each
  added step. Nuvabill makes an invoice; the extras are added as soon as it is paid. When the wallet pays invoices
  automatically (on by default) and the client has credit, it is used first.
- **Removing steps.** **Settings → General → Billing → When a client moves to a cheaper plan** decides, the same as for
  plan changes: change now and add the unused days to the client's wallet, or change on the next renewal date. The
  credit is for the unused days of what the client really paid, never more. Setup fees are not refunded. Without a
  wallet in the service's currency, removals always wait for the renewal date.
- **Disk space only grows.** Clients can never lower a disk, because the data on it would be lost.
- **Removing IP addresses** shows a warning ("Sites that use them stop working") and needs a confirmation.
- **One waiting change at a time.** While a resource change or a plan change waits for payment or for the server, the
  client cannot start another one. The client can stop a change that waits for payment.
- **Plan changes keep the extras.** When a client moves to another plan, their extras move with them ("Your extras
  stay"). They can lower them after the move. A server-size slider (Hetzner Cloud) is the exception: plans without the
  same sizes are offered only once that slider is back at 0.
- **Removed options.** When you hide or stop pricing an option, services that have it keep it ("No longer sold").
  Clients can still remove it when **Clients can remove extras** is on for the option (never for disk space).

**Settings → General → Billing → Clients can add and remove resources** turns changes on the service page on or off
for all clients. When it is off, clients see their extras but cannot change them, and staff can still change them.

## How a change reaches the server

1. The billing changes first: right away, when the invoice is paid, or on the renewal date.
2. A background job sets the plan plus the extras on the server. It runs from the [cron job](installation.md#the-cron-job),
   so it starts within a minute or two. The client card says "We are adding your resources. This can take a few
   minutes."
3. When the server is done, the client gets the **Resources changed** email. Some changes need a restart of the server
   (CPU and RAM on most VPS types); the email and the card say so.

If the server does not take the change, Nuvabill tries again after 1 and 5 minutes. After 3 tries, your team gets the
**Staff: resources not set** email, and the client sees "We could not add your resources yet. Our team is on it." The
admin dashboard shows how many resource changes need staff. Changes that some servers make slowly (for example a new
Hetzner Cloud server type) are checked again a few times over about half an hour.

### On the admin service page

The **Resources** card on a service shows its extras, any waiting change and the last result from the server:

- **Apply to server now** sets the plan plus the extras on the server at once.
- **Check server** compares the server with the plan plus the extras, and shows each difference.
- **Try again** runs the job again after a failure. **Mark done** records that your team set it by hand; the client
  gets the email.
- **Change resources** lets staff change the steps of any extra. Choose **Invoice the difference** (removing follows
  the downgrade setting) or **Change now without charging or crediting**. You can also set the renewal price of each
  extra yourself, and choose whether to **Also set it on the server**.

If you change a service's billing cycle, check the prices of its extras: they stay at the price of the old cycle until
you change them.

## Order forms and themes

- **Nova** (the standard theme) shows the sliders on the product page and the service page, and the extras in the
  cart and at checkout.
- **Swift 1.3.0** (Marketplace order form) shows the sliders in its order steps. Older order forms send no slider
  values, so each option gets its **Steps chosen at first**.
- **Aurora 1.6.0** shows the full renewal price, with extras, on the client dashboard.

## Moving from Virtualizor resource add-ons

Virtualizor module 1.x sold extras as product add-ons, set up with a JSON value in the product's module settings. When
you update to Nuvabill 0.7.0, each such product is moved to resource options by itself:

- Each kind in the JSON (CPU, RAM, disk, IPv4) becomes a resource option. The step is the add-on's size, the most
  steps is what clients could buy before (often 1), and the prices are copied from the add-on for every currency and
  billing cycle.
- The old add-ons are no longer sold with the moved product. An add-on that only moved products sold is hidden from
  the store. An add-on that other products also sell (such as one set for every product) stays on sale for them: only
  the moved product is taken off its product list, and the activity log says so. On services, active add-ons become
  extras with exactly the same renewal price, and show as "Moved to resource options" in the admin area.
- Nuvabill checks that every service's renewal total is the same before and after. If it is not, it leaves that
  product as it was and writes the reason to the activity log.

Afterwards, open **Resource options** on each Virtualizor product:

- Check the prices, and raise **Most extra steps** if you want to sell more than before.
- Look for options marked **Hidden**. The move leaves an option hidden when one of its add-ons was hidden, when its
  prices had to be worked out for a smaller step (add-ons of different sizes), or when it has no price in your main
  currency. Check its prices, then press **Show**.

If a product could not be moved, its options page says so, with a **Remove the old add-on settings** button:

1. Set up the product's options by hand.
2. For each service that still has the old add-ons, use **Change resources** with **Change now without charging or
   crediting**. Give it the same extras and set their renewal price to 0, because the old add-ons keep billing for them.
   Untick **Also set it on the server**: the server already has these extras.
3. Press **Remove the old add-on settings**, and confirm.

Until you press it, Nuvabill does not create a server or change the plan of a service that still has those add-ons, so
a server never misses extras the client pays for. This does not stop billing: the old add-ons keep billing as before.
Saving the product keeps the old settings; only the button removes them, and the activity log keeps a copy.

## Good to know

- Store pages never ask your servers anything. They read what was saved with the option, so a slow server never slows
  down the store.
- The same extras rules apply to staff: a disk never shrinks, and the server module's maximums hold.
- In the WHMCS importer, you can map WHMCS configurable options to resource options. See
  [Migrating from WHMCS](whmcs-migration.md#configurable-options).
