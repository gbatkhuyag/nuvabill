# Marketplace and "Connect to marketplace"

Themes, order forms and extensions come from the Nuvabill marketplace (**Admin → Marketplace**). Your Nuvabill talks
to the marketplace store at `my.nuvabill.com` to list items, download them and check license keys.

## Why connect

The marketplace store sits behind Cloudflare. Cloudflare checks for bots, and it sometimes stops calls that come from
servers in data centres, which is where most billing sites run. When that happens, the Marketplace page says
"Cloudflare stopped this server from reaching the marketplace".

**Connect to marketplace** fixes this once, for free:

1. Open **Admin → Marketplace** and click **Connect to marketplace**.
2. Your browser goes to the store, which asks "Connect billing.yourname.com to the Nuvabill marketplace?". Click
   **Connect**.
3. The store calls your site back at its own web address to hand over a key for your site. Only the real owner of
   the address can connect it, because the call goes to your site, not to the browser.
4. You land back on your Marketplace page. Within a minute the box shows **Connected**.

The store then tells Cloudflare to let your server's address through. Nothing else changes: sites that never
connect work as before.

### What you need

- Nuvabill 0.7.0 or newer.
- Your site on a public web address with a valid HTTPS certificate, and `APP_URL` in `.env` set to that address
  (for example `https://billing.yourname.com`).
- Staff permission **Install themes and extensions from the marketplace**.

### How the store checks your server's address

Your server notes the address it uses to reach the internet. The store lets that address through by itself when
either:

- your site's DNS points to that address, or
- the store can reach your site directly at that address (this also works when your site is behind Cloudflare).

Otherwise (for example when your server sends its traffic out from another address than the one your site runs
on) the connection waits for marketplace staff, which usually takes less than a day. The box shows **Waiting for
the store** until then.

### After connecting

- Your site checks in with the store once a day. Every 90 days it gets a new key by itself.
- If your server moves to a new address, the box shows **Address changed**: click **Connect again**.
- If the store no longer accepts your key (for example the connection was not used for 60 days), the box shows
  **Connect again**.

### Security

- The key is stored encrypted in your database and is only sent to the marketplace store, never to other
  addresses. The store keeps only a hash of it.
- The one-time code from your click works once and only for 30 minutes.
- Connecting is turned off in demo mode.

## For the marketplace store operator

This part is only for the store itself (`NUVABILL_MARKETPLACE_STORE=true`).

Connected sites are listed under **Marketplace store → Connected sites** in the admin menu: their status, server address, whether the
address is in the site's DNS or was reached directly, and when the site was last seen. **Approve** lets a waiting
site through; **Remove** stops its key and removes its Cloudflare rule. The menu shows how many sites wait.

### Cloudflare token

Bot Fight Mode cannot be skipped with WAF rules. The free exception is an IP Access rule with the action **Allow**,
which the store adds and removes by itself. On the same page, under **Cloudflare**:

1. In Cloudflare, open the `nuvabill.com` zone and copy the **Zone ID** from its Overview page.
2. Under **My Profile → API Tokens**, create a custom token with only the permission
   **Zone > Firewall Services > Edit**, and under Zone Resources include **only the nuvabill.com zone**.
3. Paste the zone ID and token, click **Test connection**, then **Save**.

The token is stored encrypted, never shown again and never logged. Without it, sites still get keys but their
servers are not let through, and the page says that Cloudflare is not set up. Active sites that connected before
get their rules when you save.

One rule serves every site on the same address. A rule is only removed when no active site uses its address any
more. Rules you added by hand are used but never removed.

### Limits

- At most 200 new connections a day (setting `marketplace.connect_daily_limit`).
- At most 5 active sites per server address (setting `marketplace.connect_sites_per_ip`); more wait for staff.
- The connect form can ask for a CAPTCHA: tick **Connect a site to the marketplace** under **Settings → Security**.
- A daily task ends sites not seen for 60 days and waiting sites not approved in 14 days, and removes their rules.
