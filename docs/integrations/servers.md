# Control panels and VPS

Nuvabill sets up hosting accounts and virtual servers the moment the first invoice of an order is paid, suspends them
when invoices are overdue, unsuspends them when they are paid, and removes them when a service is terminated.

## Adding a server

1. **Servers → Add server**. Choose the module, then fill in the hostname, port, username and password or API token.
   Each module shows what to enter under the form (the same notes are below).
2. Press **Test connection**.
3. **Products** → open a plan → choose **Automatic setup** and the server, then fill in the module's plan settings.

Connections use HTTPS by default (**Connect with HTTPS**), and the server password and API token are stored encrypted.

Dedicated servers on your own hardware have no server record: you add each machine under **Hardware** instead. See
[Dedicated servers](../hardware.md).

## The client server panel

Modules that support it (Virtualizor, Dedicated servers, Hetzner Cloud and Hetzner Dedicated) show clients a **Manage
your server** card on the service page:

- a header with the server's state, the power buttons, **Console**, and the button that opens the control panel when
  the product allows it;
- tabs such as Overview (with live use), Graphs, Reinstall, IPs and rDNS, Firewall, Backups, Snapshots, Rescue, SSH
  keys, ISO and boot, Settings, and Tasks and logs. Each module shows only the tabs its server supports.

Proxmox, VirtFusion and SolusVM keep their simpler panel card.

**Settings → General → Client server panel**:

| Setting | Default |
|---|---|
| In-browser console | On |
| Refresh live figures every (seconds) | 10 (from 5 to 60; a longer time puts less load on your servers) |
| Email clients when their server changes | On: the **Server change** email, for example after a new root password or reverse DNS name |

**For each product**: **Products** → open the plan → the **Client panel** card has a switch for each tab. A tab marked
**Off unless you turn it on** is new or not tested with every version of that control panel: try it on a test server
first.

How the panel keeps servers safe:

- Clients reach only their own active services. A suspended service shows no panel actions.
- Each action has its own limit, for example 3 reinstalls an hour.
- Only one change runs on a server at a time. While Nuvabill sets up, suspends, resizes or removes a server, a client
  action is refused with "Another change is running on this server. Try again in a moment."
- A password the server shows once, such as a new root password or VNC details, appears only on the page. It is never
  emailed, logged or kept in the session. Clients need JavaScript for these actions.
- Every change is written to the service's activity log, and the **Server change** email says when it happened and from
  which IP address. It is sent at most once in 10 minutes for the same change.

### The console

**Console** opens the server's screen in a new browser tab, with noVNC (included with Nuvabill). It has buttons for
Ctrl+Alt+Del, paste text, fit to window, full screen and disconnect.

- The link works once, for one minute, in the browser that opened it. Only the console page may connect to the server's
  console address, and only for that link.
- The client's browser connects straight to the server's console address (for Virtualizor: port 4083 of the master; for
  Hetzner Cloud: Hetzner's console address). That address must be reachable from the internet with a valid certificate.
- Turn the console off for everyone in **Settings → General → Client server panel**, or for one product with its
  **Console** switch.

### Staff tools

For modules that support them, the admin service page has a **Client access** card:

- **Test panel sign-in** checks, step by step, whether the client can open their control panel and use the server
  panel, and says what to fix. Nothing is changed on the server.
- **Link an existing account** links the service to an account made by hand on the server, or moved from another
  billing system.

## cPanel & WHM

- **Connection**: username `root` (or a reseller), and a WHM API token from **WHM → Development → Manage API Tokens**.
  Port 2087.
- **Plan setting**: the WHM package.
- **What it does**: create, suspend, unsuspend, terminate and change package. Clients open cPanel with one click,
  without a password.

## DirectAdmin

- **Connection**: your admin or reseller name, and a login key from **DirectAdmin → Login Keys** (or the account
  password; a login key is safer). Set **IP address** to the shared IP for new accounts. Port 2222.
- **Plan setting**: the DirectAdmin package.
- **What it does**: create, suspend, unsuspend, terminate and change package, with one-click login for clients.

## Plesk

- **Connection**: an API key made on the Plesk server with
  `plesk bin secret_key -c -ip-address <this server's IP>`, or the admin username and password. Also fill in the
  server's IP address. Port 8443.
- **Plan settings**: the service plan and the IPv4 address.
- **What it does**: create, suspend, unsuspend and remove Plesk customers and subscriptions, change plan, and
  one-click login for clients.

## Proxmox VE

- **Connection**: an API token from **Proxmox → Datacenter → Permissions → API Tokens**. Put the token ID in
  **Username** (for example `root@pam!nuvabill`) and the secret in **API token**. Port 8006, with a valid SSL
  certificate (Proxmox can get one with ACME).
- **Plan settings**: node, template VM ID (a cloud-init template), storage, CPU cores, RAM, disk size, disk device and
  network (`ipconfig0`).
- **What it does**: creates KVM virtual servers from the template; suspend, unsuspend, terminate and change plan.
- **In the client area**: start, shut down, restart, power off and change the root password, with live CPU and memory
  use and the IP addresses.
- **Extra resources** (module 1.1): clients can buy extra CPU cores, RAM and disk space with
  [resource options](../resource-options.md). CPU and RAM change after the next restart of the server. Disk space only
  grows, and is offered only when the plan has a disk size; the client grows the partition inside the server.

## Virtualizor

Virtualizor module 2.0 needs Nuvabill 0.7.0 or newer.

### Connection

- **Host name**: the Virtualizor master, as a name with a valid SSL certificate (for example `vz.example.com`), not an
  IP address.
- **API token** and **Password**: the Admin API key and the API password from **Virtualizor admin → Configuration →
  Server Info**. Allow Nuvabill's server IP there too.
- Or make an API credential under **Configuration → API Credentials**, limited to Nuvabill's server IP, that allows
  these acts: `vs`, `addvs`, `managevps`, `rebuild`, `vnc`, `users`, `plans`, `ostemplates`, `ips`, `sso`, `listvs`,
  and the end-user acts of the panel. If sign-in or the panel tabs fail with such a key, test with the Server Info key.
- **Port** 4085, with **Connect with HTTPS** on. Without it the API key and password travel unencrypted, and the
  client panel sign-in stays off.
- Virtualizor's API takes the key in the address of each call, the same as Virtualizor's own billing module, so it can
  show in the master's web server logs. Keep those logs private.

**Test connection** checks the Admin API on port 4085, and adds a line about the end-user port 4083: reachable, key
refused, or could not connect.

### Ports, certificate and Cloudflare

| Port | Who connects | Used for |
|---|---|---|
| 4085 | Nuvabill's server | Staff tasks: create, suspend, resize, power, reinstall |
| 4083 | Nuvabill's server **and** your clients' browsers | The panel tabs, the console in the browser, and the panel sign-in |

- Open port 4083 on the master for Nuvabill's server and for everyone else: clients connect from anywhere.
- Port 4083 needs a valid certificate for the master's hostname. A browser refuses a self-signed certificate for the
  console without saying why, so the console just fails to connect.
- Cloudflare does not carry port 4083. In Cloudflare, set the master's hostname to **DNS only** (grey cloud).
- Some clients' networks block port 4083. Virtualizor's **Standard Web Ports** guide makes the end-user panel answer on
  port 443 too. Then set the product's **Client panel port** to 443, and check the sign-in and the console with **Test
  panel sign-in** and a test VPS.
- Never use an admin port as the client panel port. Nuvabill keeps the sign-in off on 4081, 4082, 4084, 4085 and the
  server's API port, because a sign-in there could open the admin panel.
- When Nuvabill cannot reach port 4083, clients keep a basic panel through port 4085 (Overview, Power, Console,
  Reinstall and Settings). Staff see why in the activity log, once a day.

### Plan settings

Virtualization type, Virtualizor plan ID, operating system ID, IPv4 addresses, RAM, disk, CPU cores and bandwidth (only
needed without a plan), recipe, control panel to install, IPv6 addresses and subnets, network and upload speed, and the
server group or server for new VPS. Also:

- **OS reinstalls a month**: 0 means no limit.
- **OS templates clients may choose**: OS IDs separated by commas. Empty allows every template of this virtualization
  type.
- **IP pool for extra IPv4**: optional. Extra IPv4 addresses must come from this pool; they are checked after each
  change.
- **Client panel sign-in**: On gives clients an **Open Virtualizor panel** button (see below).
- **Client panel port**: 4083 by default.

### What it does

Create, suspend, unsuspend, terminate and change plan. A new VPS gets its paid extras at once.

When a Virtualizor user with the client's email already exists, the new VPS goes to that user only when the client has
confirmed their email in Nuvabill. Admin and cloud users are never used. Otherwise the create stops before anything is
made, and says why.

### In the client area

| Tab | What clients can do |
|---|---|
| Overview | See the state, live CPU, RAM, disk and traffic use, and the IP addresses |
| Power (header) | Start, shut down, restart and power off |
| Console | Use the screen in the browser, or **Show VNC details** for a VNC app |
| Graphs | See CPU, RAM, disk, network and daily traffic, month by month |
| Reinstall | Install a new system from the allowed templates with a new root password, and run the recipe again |
| IPs and rDNS | Set the reverse DNS name of the VPS's own addresses |
| Firewall | Choose, add or delete firewall plans |
| Backups | Make, restore and delete backups |
| Rescue | Turn rescue mode on or off |
| SSH keys | Add keys and put them on the server |
| ISO and boot | Mount an ISO and set the boot order (KVM, Xen HVM and Proxmox KVM) |
| Settings | Change the hostname and the root password, and the console password on KVM, Xen HVM and Proxmox KVM |
| Tasks and logs | See Virtualizor's tasks for the VPS and the account's logs |

- **Virtualizor's own switches win.** A feature turned off in Virtualizor (**Configuration → Enduser settings**, or on
  the VPS) stays hidden. A VPS managed by the admin shows only Overview and Power.
- **Switched off by default**: Firewall, ISO and boot, and Backups (except on OpenVZ). They are not tested with every
  Virtualizor version yet. Try them on a test VPS, then turn them on in the product's **Client panel** card.
- **SSH keys, Firewall and the account logs** belong to the whole Virtualizor user. They show only when that user has
  no VPS of other clients, so give each client their own Virtualizor user.
- Clients get the **Server change** email after a reinstall, a new root or console password, rescue mode, a restored
  backup, a firewall plan or new SSH keys.

### Client panel sign-in

With **Client panel sign-in** on, clients get an **Open Virtualizor panel** button. It signs them in to their own VPS
in Virtualizor's panel, without a password.

Before you turn it on:

1. In Virtualizor, turn off end-user features that skip your billing, such as creating VPS or changing resources
   (**Configuration → Enduser settings**).
2. Give each client their own Virtualizor user.
3. Check that port 4083 is open with a valid certificate, and that **Connect with HTTPS** is on.
4. If Nuvabill runs behind Cloudflare or another proxy, set `NUVABILL_TRUSTED_PROXIES` (see
   [Configuration](../configuration.md#behind-cloudflare-or-another-proxy)). Virtualizor ties the sign-in to the
   client's IP address, so Nuvabill must know the real one. Behind Cloudflare without this setting, the sign-in is
   refused.

When a client presses the button, Nuvabill first checks that the VPS is theirs:

- the service is active in Nuvabill, and the VPS is not suspended in Virtualizor;
- the VPS's Virtualizor user is a normal end user, never an admin or cloud user;
- every VPS of that Virtualizor user belongs to this client's services.

A VPS of that user that no service is linked to is allowed only when the user's email is the client's **confirmed**
email. Nuvabill notes it in the activity log once a day, so you can link or delete that VPS. Every refusal is in the
activity log with the reason; the client only sees that the panel is not available right now.

### Test panel sign-in and Link an existing account

On the admin service page, the **Client access** card has:

- **Test panel sign-in**: one line for each check, with what to fix. It checks the product setting, the server
  settings, the client panel port, the hostname, the VPS and its Virtualizor user, the end-user port, who owns the
  VPS, the console (VNC on), the client's IP address and IPv6. It never signs anyone in and changes nothing. It also
  warns **Proxy in front of this site** when your own request came through a proxy that Nuvabill does not trust.
- **Link an existing account**: for a VPS made by hand in Virtualizor, or moved from another billing system. Enter its
  **VPS ID** (**Virtualizor → Virtual Servers → List VS**). Nuvabill refuses a VPS that another service on the same
  master uses. When the Virtualizor user's email is not the client's, tick the box to link it anyway. The service status
  does not change: set it to Active yourself when it is ready.

### Extra resources

Clients can buy extra CPU cores, RAM, disk, IPv4 and IPv6 addresses, traffic, IPv6 subnets and network speed with
[resource options](../resource-options.md). Nuvabill sets the plan plus the extras on the VPS and checks the result;
the VPS keeps its Virtualizor plan.

- Disk space only grows. The client grows the partition inside the server.
- CPU and RAM changes may need a restart of the VPS. The client is told.
- Extra IPv4 addresses come from the **IP pool for extra IPv4** when you set one. When a client removes addresses,
  the main address stays.
- Network speed is not offered when the plan has no speed limit, and extra traffic does nothing on a plan with
  unlimited traffic.

### Updating from module 1.x

- Products that sold extras with the resource add-on JSON move to resource options by themselves when you update. See
  [Resource options](../resource-options.md#moving-from-virtualizor-resource-add-ons).
- VNC details are shown once on the page, and never kept in the session.
- The panel sign-in checks the owner of the VPS more strictly than before. When a client says the button fails, use
  **Test panel sign-in** on their service.

## Dedicated servers

Built in. Sell your own dedicated servers: stock comes from free machines under **Hardware**, and every machine is
wiped before it is sold again. The Dedicated Server Power add-on switches the power through each machine's BMC
(Redfish or IPMI); without it your team switches machines by hand and Nuvabill reminds them by email.

- **Connection**: none. Add your machines under **Hardware**, not under **Servers**.
- **Plan settings**: power buttons for clients, reinstall requests, what happens on suspend, the default system and
  the login name.
- **What it does**: reserves a free machine at order, emails your team to install the system after payment, activates
  the service when staff press **Server is ready**, switches the power off on suspend and on at unsuspend (or emails
  your team to do it by hand), and moves the machine to **Needs wipe** at the end.
- **In the client area**: overview, power (with Dedicated Server Power), IP addresses and a reinstall request that
  opens a ticket.

The full guide: [Dedicated servers](../hardware.md).

## Free modules on the Marketplace

These modules are free. Install them with one click from **Marketplace** in the admin area: after that they show in
the module list on **Servers → Add server**, like the built-in ones.

### CyberPanel

- **Connection**: a CyberPanel admin username and password, with API access turned on for that user
  (**Users → API Access**). Port 8090, with a valid SSL certificate for the one-click sign-in.
- **Plan settings**: the CyberPanel package, the PHP version (empty uses PHP 8.3) and how many websites the client may
  add.
- **What it does**: creates a website and its own CyberPanel user; suspend, unsuspend, change package and remove.
  Clients open CyberPanel with one click, without a password. Needs Nuvabill 0.4.10 or newer.

### HestiaCP

- **Connection**: an access key in **API token**, written as `ACCESS_KEY:SECRET_KEY` (in Hestia: your admin user →
  **Access keys**, or `v-add-access-key admin '*' nuvabill` on the server). The admin username and password also
  work. Allow Nuvabill's server IP under **Server settings → Security → API**. Port 8083.
- **Plan setting**: the Hestia package.
- **What it does**: creates a Hestia user with the client's domain (website, DNS and email); suspend, unsuspend, change
  package and remove. If the domain cannot be added, the new user is removed again. Clients open Hestia with one
  click.

### VirtFusion

- **Connection**: your VirtFusion panel as hostname (for example `panel.example.com`) and an API token from
  **Settings → API** in VirtFusion. The panel needs a valid SSL certificate.
- **Plan settings**: the VirtFusion package ID, the hypervisor group ID, the operating system ID (empty lets the client
  choose in VirtFusion) and the number of IPv4 addresses.
- **What it does**: makes a VirtFusion user for each client and builds the server; suspend, unsuspend, change package
  and remove.
- **In the client area**: start, shut down, restart and power off, with the state, operating system and resources, and
  one-click sign-in to VirtFusion.

### SolusVM

- **Connection**: the SolusVM master as hostname, the API ID in **Username** and the API key in **API token**
  (**Configuration → API Access** in SolusVM). Allow Nuvabill's server IP there too. Port 5656 with HTTPS. For
  SolusVM 1.
- **Plan settings**: virtualization type (KVM, OpenVZ or Xen), node group or node, the SolusVM plan name, the OS
  template file name and the number of IPv4 addresses.
- **What it does**: create, suspend, unsuspend, terminate and change plan.
- **In the client area**: boot, shut down, reboot, change hostname, change root password and open the VNC console,
  with the state, IP addresses and memory and disk use, and one-click sign-in to the SolusVM client panel.

When you import from WHMCS, servers and products that use CyberPanel, HestiaCP, VirtFusion or SolusVM come across once
the module is installed. Install it first, then run the import (or run it again).

### Hetzner Cloud

Needs Nuvabill 0.7.0 or newer. Not made or supported by Hetzner.

- **Connection**: **Host name** `api.hetzner.cloud` (the address is built in). **API token**: a **Read & Write** token
  from one Hetzner Cloud project that only this Nuvabill site uses (**Hetzner Console → your project → Security → API
  tokens**). The token can change and delete everything in that project, so keep your own servers in another project.
  **Maximum accounts**: the project's server limit; ask Hetzner to raise it.
- **One project for each Nuvabill server.** For more servers than one project allows, make more projects and add each
  as another server in Nuvabill.
- **Plan settings**: server type (for example `cx23`), locations, operating system, the systems clients may reinstall,
  IPv4 and IPv6, Hetzner firewalls to apply, included backups, delete protection, whether a bigger type also grows the
  disk, the server sizes for the size slider, snapshot slots, ISO images, and **Wait for payment** (on by default,
  because Hetzner bills every hour).
- **What it does**: makes the server once the order is paid; suspend (shut down, then power off), unsuspend, change
  plan in the background (the server restarts; this takes a few minutes), and terminate (removes the server with the
  disks, IP addresses and snapshots Nuvabill made for it).
- **Labels**: everything Nuvabill makes carries this site's labels (`nuvabill.com/site` and `nuvabill.com/service`).
  It never changes or removes anything without them, so other servers in the project are safe. The server help and
  **Test connection** show this site's label.
- **In the client area**: power (start, shut down, restart, power off and force restart), the console in the browser,
  graphs, reinstall, rescue mode, a new root password, reverse DNS, backups and the server name. Snapshots and ISO
  images are off by default; turn them on in the product's **Client panel** card.
- **Extra resources**: a bigger server type from the sizes you list, an extra disk (steps of at least 10 GB; it only
  grows), extra IPv4 addresses (Floating IPs, up to 10), daily backups (turning them off deletes the backups) and
  snapshot slots. See [Resource options](../resource-options.md).
- **API limit**: Hetzner allows 3,600 API requests an hour for each project. Nuvabill shares them: creates and staff
  actions first, then client actions, then graphs. Live figures refresh at most every 30 seconds.

### Hetzner Dedicated (Robot)

Needs Nuvabill 0.7.0 or newer. Not made or supported by Hetzner.

- **You buy the servers in Robot.** The module gives them to your clients. It never orders servers, and it cancels one
  at Hetzner only when you choose that for the end of a service.
- **Connection**: **Host name** `robot-ws.your-server.de` (the address is built in). **Username** and **Password**: a
  Robot webservice user (**Robot → Settings → Web service and app settings**). Keep **Ordering** off there.
- **Careful with the password.** Hetzner blocks your site's IP address for 10 minutes after 3 wrong logins. Nuvabill
  stops after the first refused login and waits, and **Test connection** makes exactly one call.
- **The pool.** To give a server to this site, rename it in Robot to `nb-{site}-pool` (this site's ID is in the server
  help and in **Test connection**). Each paid order takes a free server that matches the product, renames it to
  `nb-{site}-s{service number}` and installs the chosen Linux. Set each product's **Stock** to the number of servers you
  bought for it.
- **Plan settings**: Robot products (for example `AX42`, or `*` for any), datacenters, the Linux to install, more
  systems clients may reinstall, the system language, what happens at the end of a service, the cancellation date, how
  to suspend, and **Wait for payment**.
- **What it does**: installs the server (about 15 minutes); suspends by closing the server's Robot firewall, or by
  switching it off (a product setting for servers without a Robot firewall); unsuspends. At the end, as the product
  says: reinstall it and put it back in the pool; block it and hold it for your team (wipe the disks, then rename it
  back to the pool); or block it and cancel it at Hetzner. A reinstall is not a secure erase: choose hold when disks
  must be wiped.
- **In the client area**: force restart, restart (Ctrl+Alt+Del), press the power button, power off, Wake on LAN,
  reinstall, rescue mode, reverse DNS, and this month's traffic. There is no console and no extra resources.
- **Plan changes** work only to a plan with the same Robot product and datacenter.

## Not listed here?

Other control panels can be added as extensions. See the
[developer guide](https://nuvabill.com/docs/developers/) (server modules extend `App\Extensions\Servers\Module`).

## For module authors: panels, consoles, resources and hardware

Since Nuvabill 0.7.0 a server module can implement the optional contracts below, from `App\Contracts`. Core does the
routing, owner checks, billing, limits, locks, logging and emails; the module only talks to its server. Set
`"requires": ">=0.7.0"` in `extension.json` when you use them. The value objects are in
`App\Extensions\Servers\Panel` and `App\Extensions\Servers\Resources`. The test fixture in
`tests/Fixtures/extensions/servers/panelfake` implements all of them.

Rules for every contract:

- Never read `request()`. Who asks comes as a `ClientContext`: `clientId`, `ip`, `ipTrusted` (false when the address is
  not valid, or is a Cloudflare address because proxies are not trusted), `returnUrl` and `locale`.
- Use a fixed API address, keep TLS checks on, and do not follow redirects.
- Never put a password, key or token in a message or an exception. Core reports only the class of an unexpected error,
  but your messages are shown to staff, and sometimes to clients.
- Core removes data keys that look like secrets from panel data: `pass`, `password`, `passwd`, `secret`, `token`,
  `apikey`, `api_key`, `apipass` and `sid`, as the whole key or as a part between underscores. Do not use these words in
  normal keys: write `credential_mode`, not `password_mode`.

### Panel sections: `HasPanelSections`

| Method | What it does |
|---|---|
| `panelSections(Product $product): array` | The sections the module offers for this product, in order, as `PanelSection` objects. It must not call the server: the product form and every page use it. |
| `availablePanelSections(Service $service, ClientContext $context): ?array` | The section keys the live server allows now, or null for all of them. Core keeps the answer 60 seconds. If it throws, only Overview and Power show. |
| `readPanelSection(Service $service, string $section, array $input, ClientContext $context): PanelResult` | The data of one section. `$input` holds only the keys of the section's read rules. |
| `runPanelAction(Service $service, string $section, string $action, array $input, ClientContext $context): PanelResult` | One action. `$input` holds only the keys of the action's rules. Refuse it again when your data said it is not allowed. |

Declare standard sections with `PanelSection::standard($key, actions: [...], readRules: [...], cacheSeconds: 0,
enabledByDefault: true, pollSeconds: null, staffHelp: null)`. The standard keys use the core views: `overview`,
`console`, `graphs`, `reinstall`, `network`, `firewall`, `backups`, `snapshots`, `rescue`, `ssh-keys`, `media`,
`settings`, `activity`, and `power` (the power buttons in the header, never a tab). A section with
`enabledByDefault: false` stays off until staff turn it on for the product; `staffHelp` is shown next to its switch.

Any other key needs its own view from your extension, so declare it with the constructor, not with `standard()`
(which cannot set a view, so core skips the section and reports it):

```php
new PanelSection(key: 'notes', label: __('Notes'), icon: 'list', view: 'ext-{your-slug}::panel.notes', actions: [...])
```

The view lives in your extension's `views` folder (here `views/panel/notes.blade.php`). For a working example, see
`customSection()` and `views/notes.blade.php` in the test fixture `tests/Fixtures/extensions/servers/panelfake`.

Each action is a `PanelAction`:

| Field | Meaning |
|---|---|
| `rules` | Laravel validation rules. Only these keys reach your module. |
| `changesServer` | True by default: core takes the service lock, logs the change and clears the read cache. |
| `throttle`, `bucket` | `[max, seconds]` per service. Actions with the same bucket share one limit. Standard actions get safe defaults. |
| `confirm`, `destructive` | The question clients answer first. A destructive action also needs the rule `'confirm' => ['accepted']`. |
| `reveals` | The result holds a secret shown once (`PanelResult::secret()`). It needs JavaScript and is never cached, logged or kept in the session. |
| `notify` | English text for the **Server change** email to the client. |
| `secretFields` | Input fields that are never logged or kept in the session. |

For the power buttons, use `StandardSections::powerAction('start')` and the other keys `stop`, `restart`,
`poweroff`, `reset` and `power-cycle`. Other keys (for example `wol`) are allowed, but the panel cannot wait for their
result state.

Answer with `PanelResult::ok($message, $data)`, `PanelResult::secret($message, [['label' => …, 'value' => …]])`,
`PanelResult::fail($message, $staffDetail, $status)` or `PanelResult::unavailable(…)`, or throw
`PanelException($clientMessage, $staffDetail, $status)`. The staff detail goes to the activity log, never to the client.

Before core calls your module, it checks, in this order: the owner; an active service with a server (or a module that
uses the hardware inventory); the section is declared and switched on for the product; the live server allows it; the
action is declared; the input rules; the limits; the read cache; and, for an action that changes the server, the
service lock (another change gets a 409 answer). After a change it logs it, clears the cache and emails the client.

**Data shapes.** The core views expect these keys in `PanelResult` data. Times are Unix seconds, and sizes are bytes
unless a `unit` is given.

| Section | Data |
|---|---|
| `overview` | `state` (running, stopped, suspended, busy or unknown), `cpu` (0–100), `ram`, `disk` and `bandwidth` as `{used, total, unit}`, `network` as `{in, out}` per second, `facts` as `[{label, value}]`, `ips` as `[{ip, version, primary}]`, `power_note`, `live` |
| `console` | `available`, `details` |
| `graphs` | `period` (`{kind, value, options}`), `series` as `[{key, label, unit, points}]` with at most 400 points each |
| `reinstall` | `mode` (run or request), `templates` as `[{id, name, group}]`, `credential_mode` (set, generate or none), `recipe`, `left_this_month`, `note`, `open_request`, `warning` |
| `network` | `ips` (with `rdns`), `subnets` as `[{cidr}]`, `rdns` (whether names can be set) |
| `backups`, `snapshots` | `items` as `[{id, created_at, size_bytes, kind, status}]`, `used`, `limit`, `credential_mode` |
| `rescue` | `enabled`, `blocked_reason`, `credential_mode` |
| `media` | `isos` as `[{id, name}]`, `mounted`, `boot`, `restart_needed` |
| `settings` | `hostname`, `credential_mode`, `can` |

For `firewall`, `ssh-keys` and `activity`, follow the core views. Any shape may add `can` (`{action: true or false}`;
the panel hides refused actions, and your module must refuse them again) and `notices` (`[{tone: info, warn or crit,
text}]`). Never put prices or panel logins in the data.

**Fields the core forms send**: `reinstall.run`: `template`, `password` and `password_confirmation` (when
`credential_mode` is set), `run_recipe`, `confirm`. `reinstall.request`: `template`, `note`, `confirm`.
`network.rdns-set`: `ip`, `ptr`. `network.rdns-delete`: `ip`. `firewall.apply`: `plan_id`. `firewall.create`: `name`,
`default_policy`, `rules[]`. `firewall.delete`: `plan_id`, `confirm`. Backups and snapshots: `create` (`description`),
`restore` and `delete` (`id`, `confirm`). `rescue.enable`: `password`, `reboot`, `confirm`. `rescue.disable`:
`reboot`. `ssh-keys.add`: `name`, `key`. `ssh-keys.delete`: `id`. `ssh-keys.apply`: `keys[]`. `media.mount`: `iso`.
`media.boot`: `order`. `settings.hostname`: `hostname`. `settings.root-password`: `password` and
`password_confirmation`, or `confirm` when the password is generated. `settings.vnc-password`: `password`.

A custom view is shown inside its tab with the `panelSection` Alpine component: show `data` with `x-text` (never
`x-html`), and send actions with `submit('action', $event)`. The core views in
`themes/nova/views/client/services/panel` are examples.

### Console: `HasConsole`

`consoleSession(Service $service, ClientContext $context): ConsoleSession` returns a fresh session, for example
`new ConsoleSession(url: 'wss://console.example.com/path', credentials: ['password' => $password], expiresAt: $time)`.

- The address must use `wss://` with a DNS name or an IPv4 address: no IPv6 address and no user name in it. Without a
  port it uses 443.
- `protocol` is `'vnc'` (the default: the server's screen with the bundled noVNC) or, since 0.7.0, `'terminal'`
  (a text terminal with the bundled xterm.js 5.5.0). Any other value is refused. See **Terminal console** below for
  what a terminal speaks.
- Throw `PanelException` when the console is not available.
- Core keeps the session as a single-use ticket for at most 60 seconds and opens the core console page; only that page
  may connect to the address. Never add console addresses to your extension's Content-Security-Policy list: that list
  applies to every page.

### Panel sign-in: `SignsInWithContext`

`signInUrl(Service $service, ClientContext $context): ?string` returns a one-time `https://` link to the client's own
panel, or null. Core uses it instead of `loginUrl()` for the client's button, checks that the client owns the service,
and never stores or logs the link. If your panel ties the session to an IP address, use `$context->ip` only when
`$context->ipTrusted` is true.

### Staff tools: `ChecksClientAccess` and `LinksExistingAccount`

- `checkClientAccess(Service $service): array` returns rows of `['label' => …, 'status' => 'ok'|'warn'|'fail'|'skip',
  'detail' => …]` for **Test panel sign-in**. Never make a sign-in session, and never put a secret in a detail.
- `linkFields(): array` declares the fields of the **Link an existing account** form, each with `label`, `type`, and
  optionally `help` and `required`. `linkExistingAccount(Service $service, array $input): ModuleResult` checks the
  account and returns the values for the service's `module_data` in `data['module_data']`. Only the declared fields reach
  your module, and password fields are never kept in the session.

### Resource options: `HasResourceOptions`

| Method | Called from | What it does |
|---|---|---|
| `resourceKinds(Product $product): array` | Staff screens, option saves, jobs | The kinds the module can sell for the product, as `ResourceKind` objects. Throw `InvalidArgumentException` with a message for staff when the product settings are wrong. Store and client pages never call it: they read what was saved with each option. |
| `baseResources(Product $product, ?Server $server): array` | Staff screens | What the plan gives without extras, in units for each kind, or null when it is not known. `$server` is null for modules that use the hardware inventory. |
| `resourceLimits(Service $service): array` | Client and staff pages | Live limits for one service, as `ResourceLimit(minUnits, maxUnits, reason)` in extra units. Core keeps them 60 seconds; a throw pauses additions. |
| `applyResources(Service $service, ResourceExtras $extras): ModuleResult` | A background job | Set the server to the plan plus these extras, then check it. Running it twice must change nothing. Result data may hold `'restart' => true`, `'manual' => true` (staff finish by hand) or `'pending' => true` (still running; core checks again later). |
| `checkResources(Service $service, ResourceExtras $extras): ResourceCheck` | Jobs and **Check server** | Compare the server with the plan plus the extras. Change nothing. |

- `create()` and `changePackage()` must include the extras. Read them with `$service->resourceExtras()`;
  `get($kind)` gives the extra units.
- The built-in kinds keep their units: `cpu` (core), `ram` (MB), `disk` (GB, cannot shrink), `ipv4` and `ipv6` (IP),
  `bandwidth` (GB) and `backups` (on or off). A built-in kind declared with another unit is skipped.
- Your own kinds start with your slug, with `_` instead of `-`, for example `hetzner_cloud_size`. Keys match
  `^[a-z][a-z0-9_]{1,39}$`.
- `ResourceKind` has `key` (required, and the same as its key in the array you return; core skips a kind whose key
  differs), `label`, `unit`, `minStep`, `stepMultiple`, `maxExtra`, `canShrink`, `restarts`, `note` (shown to clients)
  and `ladder` (named sizes for your own kinds; step 0 is the plan itself). For example
  `'ram' => new ResourceKind(key: 'ram', label: __('RAM'), unit: 'MB', minStep: 128)`.
- Core handles the payment: a service with unpaid extras is not created until its order invoice is paid.

### Lifecycle contracts

| Contract | Use it when |
|---|---|
| `UsesMachineInventory` | Services run on machines from **Hardware** instead of a server record. Stock comes from free machines, and Nuvabill does not pick a server. |
| `FinishesSetupLater` | Someone finishes the setup after `create()`, for example staff who install a system. While `setupPending(Service $service)` is true, the service stays Pending and no welcome email is sent; the next `create()` activates it. |
| `ChoosesWelcomeEmail` | The module sends its own "ready" email: `welcomeEmail(Service $service)` returns `['key' => …, 'context' => […]]`. Core removes context keys that look like secrets, and sends the usual welcome email when this throws. |
| `LimitsPlanChanges` | Only some plan changes can work: `allowsPlanChange(Service $service, Product $target)`. Other plans are not offered. |
| `QueuesPackageChanges` | `changePackage()` is slow, for example a cloud server resize. Core runs it in a background job, never inside a payment. |

`HasClientPanel`, `HasPanelState`, `WaitsForPayment` and `KeepsCreateServer` work as before.

## For module makers: staff pages, schedules, order fields, long settings and terminals

Since Nuvabill 0.7.0 a server module can also have pages for staff, run on a schedule, ask clients for details when
they order, keep long product settings and offer a text terminal. Each hook needs its marketplace permission in
`extension.json`, which staff see before they install the module; without it core ignores the contract. The test
fixture `tests/Fixtures/extensions/servers/hookfake` uses all of them.

| Hook | Contract | Permission in `extension.json` |
|---|---|---|
| Admin pages and dashboard items | `HasAdminPages` | `admin-page` |
| A card on the admin service page | `HasStaffPanel` | `admin-page` |
| Scheduled work | `RunsOnSchedule` | `schedule` |
| Fields on the order form | `HasOrderFields` | `order-fields` |
| Long and secret product settings | `productFields()` (no contract) | none |
| Terminal console | `HasConsole` with `protocol: 'terminal'` | `client-page` |

### The module's admin permission: `HasAdminPermission`

`HasAdminPages` and `HasStaffPanel` both extend it:

| Method | What it does |
|---|---|
| `adminPermission(): string` | The permission key: your slug, a dot and a short name of lowercase letters, digits or dashes, for example `proxmox-ve-servers.manage`. Any other key, or a slug that is one of core's permission groups (`billing`, `services`, `hardware` and so on), is refused and the module gets no staff pages. |
| `adminPermissionLabel(): string` | What it allows, already translated. **Settings → Roles** lists it under the module's name. The owner role always has it. |

Staff need this permission **and two-factor login on their own account** for the module's pages, dashboard items,
staff card and actions, also when your company does not require two-factor login for everyone, and this session must
have passed it: a code or a passkey at sign-in, or a code asked again (after a remember-me sign-in, say). Without it
they are sent to their profile to turn it on, or asked for a code; the staff card then shows only how, and your module
is not asked for it. The menu link to your `index` page shows for everyone with the permission.

### Admin pages: `HasAdminPages`

| Method | What it does |
|---|---|
| `adminRoutes(): void` | Register pages with short names, for example `Route::get('/', …)->name('index')` and `Route::post('nodes/{node}/maintenance', …)->name('maintenance')`. They are served under `/admin/server-modules/{slug}/` with the route names `admin.server-modules.{slug}.{name}`; build them with `ModuleAdmin::routeName($slug, $name)` (`App\Extensions\Servers\Staff`). A page named `index` is listed in the admin menu under **Setup** with the module's name. |
| `attentionItems(Admin $admin): array` | Items for the dashboard's **Needs attention** list (and the phone app's Today screen), in the same shape as `App\Support\AttentionItems`: `key` (starts with `ext.`), `tone` (`warn` or `crit`), `text`, `url` (on this site) and `action`. Core asks only for staff with your permission, keeps at most 5, checks each one and leaves out bad ones. It runs on every dashboard view: read saved state, never the server. |

Core puts every page behind the staff sign-in, your admin permission, two-factor login, the form token (CSRF) and a
limit of 30 requests a minute. In the public demo, every page that changes something answers 403. Use Blade views from
your extension's `views` folder (`ext-{slug}::name`) with `<x-layouts.admin>` and core's classes, escape everything
with `{{ }}`, check every ID you get against your own data again, and log what staff change with
`App\Support\Activity::log()` and the staff member's name. A module that fails while registering its pages is
reported (only the class of the error) and left out.

### A card on the admin service page: `HasStaffPanel`

| Method | What it does |
|---|---|
| `staffPanel(Service $service): StaffPanel` | The card: `new StaffPanel(title: …, rows: [['label' => …, 'value' => …]], actions: [new StaffAction(…)], notice: …)`. Rows are plain text (shown escaped; at most 30). Never put a password, key or token in them. Keep it fast. |
| `runStaffAction(Service $service, string $action, array $input, StaffContext $staff): ModuleResult` | Run one action. `$input` holds only that action's own fields, checked. `StaffContext` has `adminId`, `adminName` and `ipAddress`: write the name into your own logs as the operator. The result's message is shown to staff. |

`new StaffAction(key: 'migrate', label: __('Move to another node'), fields: [...], confirm: __('Move this guest?'),
danger: false)`: the key is 1 to 40 lowercase letters, digits or dashes, and at most 10 actions show. Fields have
`label`, `type` (`text`, `number`, `select`, `checkbox` or `password`), and optionally `help`, `required`, `options`
(for a select, `value => label`) and `max` (for text and password, at most 190). A `password` field (a new root
password, say) is shown empty and is never written back into the form or kept in the session after an error. Core
checks the input with these rules; a select only takes one of its options, so offer only what is allowed for this
service.

Only staff with **Manage services** and your admin permission see the card. Before an action runs, core checks
two-factor login, asks `staffPanel()` again and runs the action only when the card offers it for this service now,
checks the fields, takes the service lock (when other work holds it, nothing runs), limits it to 10 a minute, and logs
`service.staff_action` (or `service.staff_action_failed`) with the action, module and staff member, never the values.
An exception becomes a plain message for staff and is reported by class only. The route is
`POST /admin/services/{service}/staff/{action}` (`admin.services.staff-action`), locked in the demo.

### Scheduled work: `RunsOnSchedule`

`scheduledTasks(): array` returns `ScheduledTask` objects (`App\Extensions\Servers`):

```php
new ScheduledTask(name: 'traffic', callback: fn () => $this->checkTraffic(), frequency: 'hourly', lockMinutes: 30)
```

- `name`: 1 to 40 lowercase letters, digits or dashes, once per module. The scheduler names it
  `server-module:{slug}:{name}`.
- `frequency`: `every-five-minutes`, `every-ten-minutes`, `every-fifteen-minutes`, `every-thirty-minutes`, `hourly`,
  `every-six-hours`, `daily` or `weekly`. Every five minutes is the most often a module may run.
- `lockMinutes` (1 to 1440): a run never starts while the last one still runs (`withoutOverlapping`); the lock ends
  after this long if a run dies, and core makes it at least one interval of the frequency. With a cache that can hold
  locks (files, the database, Redis), it also runs on only one server (`onOneServer`).

Core's own cron line (`* * * * * php artisan schedule:run`) runs them: no extra cron lines. Only a module in use (with a
server or a product) runs, with at most 10 tasks; the rest, and a bad task, are left out and logged once a day. A
failure is reported as `Scheduled task {slug}:{name}: {class}`, never with its message. Keep a run shorter than its
frequency, do work per service under `Provisioner::lockFor($service)`, and work out again inside the
lock what is due. Scheduled work does not run on the public demo.

### Fields on the order form: `HasOrderFields`

`orderFields(Product $product): array` returns the fields for one product, keyed by a name of a lowercase letter, then
lowercase letters, digits or underscores (at most 20 fields). It must not call the server: the store shows them on
every visit.

| Key | Meaning |
|---|---|
| `label` | Already translated. |
| `type` | `text` (one line, at most 190 characters), `textarea` (up to `max`, default 4000, at most 16384), `select` (one of `options`, `value => label`) or `ssh-key` (OpenSSH public keys, one per line, at most 10: `ssh-ed25519`, `ssh-rsa`, `ecdsa-sha2-nistp256/384/521` and the security key types; core checks that each key's body names its type). |
| `required`, `help`, `max` | As on other forms. |
| `rules` | More Laravel rules, for example `['regex:/^[a-z0-9.-]+$/']`. Core's rules always run first, so yours can only make the check stricter. |
| `secret` | The value is never shown again: not to the client, not to staff, and never in a log or a form after an error. Core removes it once `create()` has set the service up, so read it in `create()` only. |

Core shows the fields on the product's order page (theme view `store/partials/order-fields`) and checks them when the
product is added to the cart, and on one-page orders before a new account is made. Plain fields post as
`fields[name]`, secret ones as `secret_fields[name]`. The values ride in the cart encrypted and are kept on the service
in `order_fields`, encrypted with the app key and never in JSON. Read them in `create()` with
`$this->orderField($service, 'hostname')` from `App\Extensions\Servers\Module` (SSH keys come one per line). Staff see
the plain values under **Order details** on the service page; secret ones show as hidden. A theme or order form that
replaces the product page shows the fields with `@include('theme::store.partials.order-fields')` and the
`orderFields` the store gives the page; a one-page order form posts the same names to `order.store`. A cancelled
or terminated service keeps no order fields.

### Long and secret product settings

In `productFields()`, a `textarea` may set `max` up to 16384 characters, for example a cloud-init script:
`'user_data' => ['label' => __('User data'), 'type' => 'textarea', 'max' => 16384]`. Core checks each field's own
limit; every other field, and a textarea without `max`, stays at 190 characters, and all settings of a product together
at most 64 KB. Mark a field `'secret' => true` (a `password` field counts as secret too) to store it encrypted with the
app key: the product form never shows it again, and left empty it keeps the saved value. Read settings with
`$this->productSetting($service, 'user_data')`, which gives secret ones in plain text; a module that reads
`module_config` directly gets the encrypted value (`App\Extensions\Servers\ModuleConfig::read()` decrypts it).

### Terminal console

Return `new ConsoleSession(url: 'wss://console.example.com/term?token=…', credentials: ['username' => …, 'password' =>
…], expiresAt: $time, protocol: ConsoleSession::TERMINAL)` from `consoleSession()`. The security model is the same as
for VNC: a single-use ticket for at most 60 seconds, bound to the client, the service and the browser session; the page
holds no address or password and alone may connect to the address. The browser opens the WebSocket with the
subprotocol `nuvabill.terminal.v1` (a server may answer without one) and speaks:

- Server to browser: binary or text frames are terminal output in UTF-8.
- Browser to server: binary frames are what the client types, in UTF-8. Text frames are JSON control messages: first
  `{"type":"auth","username":…,"password":…}` when the session has credentials, then
  `{"type":"resize","cols":80,"rows":24}` at the start and whenever the window changes (at most 500 × 200).

Your console proxy must check the token itself (single use, short-lived, bound to the service), check the `Origin`
header against your site, and limit frame sizes and idle time.
