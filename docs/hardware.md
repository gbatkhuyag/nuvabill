# Dedicated servers (your own hardware)

Sell the dedicated servers you own or rent in a datacenter. You add each machine under **Hardware** in the admin area.
Free machines are the stock of your dedicated products, so a machine is never sold twice, and a machine is never sold
again until your team confirms its disks were erased. All of this is free in Nuvabill.

Your team installs the system on each server, then presses **Server is ready**. Without power control, your team also
switches machines on and off by hand: Nuvabill emails them when a server must be switched off or on.

Add-ons from the marketplace do more:

| Add-on | What it does |
|---|---|
| **Dedicated Server Power** ($29) | Switches the power through the machine's management board (the BMC: iDRAC, iLO, Supermicro and similar) with Redfish or IPMI. Clients get power buttons, suspended servers are switched off by themselves, and staff can choose the next boot device and read the health and power use. |
| **Switch Port Control** | Turns a machine's switch port off while its service is suspended or ended. |
| **Automatic OS Install** | Installs the system from the network by itself, then marks the server ready. |

## How it works

1. **Order.** A client orders a dedicated product. Nuvabill reserves a free machine that is sold as that product (the
   one added first). If none is free, the product shows as sold out.
2. **Payment.** When the order is paid, your team gets the **Staff: install a dedicated server** email with the machine,
   its rack and the system to install. The client sees "Your server is being prepared".
3. **Install.** Your team installs the system on the machine, opens the service in the admin area and presses **Server
   is ready** with the host name, the login name and, optionally, the root password.
4. **Ready.** The service becomes Active. The client gets the **Dedicated server ready** email with the IP addresses and
   the login name. The password is never emailed: the client sees it in the client area.
5. **Suspend.** With Dedicated Server Power, the server gets a shutdown signal. If it is still on after the wait time
   (10 minutes by default), Nuvabill forces the power off, and checks every hour that it stays off. Without it, your
   team gets a **Switch it off by hand** email.
6. **Unsuspend.** The machine is in use again, and Nuvabill switches the power on (you can turn this off per product).
   Without power control, your team gets a **Switch it on by hand** email.
7. **End.** When the service is terminated or cancelled, Nuvabill forces the power off (or emails your team to switch
   it off by hand) and the machine moves to **Needs wipe**. Your team gets the **Staff: wipe a machine** email. After
   you erased its disks, press **Confirm wipe**, and the machine is for sale again.

With the Automatic OS Install add-on, steps 2 and 3 happen by themselves: your team gets no install email, and the
add-on marks the server ready when the install is done. If it cannot finish, your team gets an email and installs by
hand as usual.

A machine has one of these statuses:

| Status | Meaning |
|---|---|
| Available | Free. The next order of a product it is sold as takes it. |
| Reserved | Held by an order that is not paid or not set up yet. |
| Setting up | Paid. Your team installs the system. |
| In use | The client's server. |
| Suspended | The service is suspended and the power is kept off. |
| Needs wipe | The last client's data may still be on it. It is never sold until staff confirm the wipe. |
| Maintenance | Not for sale. |
| Retired | Not for sale, kept for its history. |

Staff can set only **Available**, **Maintenance** and **Retired** by hand, and only on a machine that no service holds.
A machine that needs a wipe leaves that status only through **Confirm wipe**. The other statuses follow the service, so
the "never sold before a wipe" rule cannot be skipped.

## Before you start

### Who can do what

Two staff permissions (**Settings → Roles**):

- **See and switch the power of dedicated servers** (`hardware.power`): the Hardware list, machine pages and power
  buttons.
- **Manage dedicated server hardware and BMC logins** (`hardware.manage`): add and edit machines, BMC logins and
  certificates, confirm wipes.

When you update, roles that can manage services get the power permission, and roles that can manage products get both.

### Reaching the BMCs safely

You need this only for power control (the Dedicated Server Power add-on). A BMC controls the machine below its
operating system. Never put a BMC on the open internet.

- **Network.** Nuvabill's server must reach each BMC: port 443 for Redfish, or UDP port 623 for IPMI. Keep the BMCs on
  a private management network, and connect Nuvabill to it: run Nuvabill in the same datacenter, or link its server to
  the management network with a VPN such as WireGuard. A public BMC address is acceptable only when its firewall allows
  Nuvabill's server alone. Check that your host's firewall allows these outgoing connections.
- **If Nuvabill cannot reach the BMCs** (for example on shared hosting), choose **No power control** for the machines.
  Everything else works; your team switches the power by hand, and Nuvabill reminds them by email when a server must
  be switched off or on.
- **A BMC user for Nuvabill.** Make a separate BMC user with the **Operator** role (or the "Virtual Power and Reset"
  right on HPE iLO), not Administrator. It only needs to read the system and switch the power. After **Test
  connection**, try a power button on a free machine once, to be sure the role may switch the power.
- **Logins are safe at rest.** BMC user names and passwords are stored encrypted, are never shown again, and never
  appear in logs, emails or the client area.

## 1. Make a dedicated product

1. **Products** → new product. Choose the type **Dedicated server**.
2. Under **Automatic setup**, choose the server module **Dedicated servers**. These products have no server and no stock
   number: their stock is the number of free machines sold as them.
3. Set the module settings:

| Setting | What it does |
|---|---|
| Power buttons for clients | All (on, shut down, restart, force restart, force off), basic (on, shut down, restart) or none. Needs Dedicated Server Power |
| Clients can ask for a reinstall | Clients can ask for a new system; this opens a support ticket |
| When suspended | Shut down, then force off after the wait time; force off at once; or do not touch the power |
| Email staff to block the network port | Also email your team when a server is suspended |
| Power on again when unsuspended | Switch the power on when the client pays |
| Start the setup before the order is paid | Off by default: the install email goes out only after payment |
| System to install by default | Shown to your team in the install email |
| Login name shown to the client | Usually `root` |

Clients can also add extra IPv4 addresses and monthly traffic with [resource options](resource-options.md). Extra IPv4
addresses come from the free extra IPs of the client's machine, so a client can never buy more than the machine has.
Traffic is billing only: Nuvabill emails your team to set the new limit, and the change waits until staff press
**Mark done** on the service page. Only then does the client get the **Resources changed** email. A service with no
machine in use cannot add extras until you assign one.

## 2. Add the machines

**Hardware → Add machine**:

- **Machine**: your own name (for example `DS-101`; clients never see it), location, rack, specs (CPU, cores, RAM,
  storage, port speed) and staff notes. Clients see the specs, and the location when **Hardware settings** allow it.
- **Sold as**: tick the products this machine can be sold as. A free machine is stock for every product you tick; one
  order takes it.
- **IP addresses**: the main IP with its prefix and gateway, the included addresses that come with every plan, and the
  extra addresses clients can rent. Write one per line, for example `203.0.113.10/29 via 203.0.113.9`.
- **Power control (BMC)**: the types come from the Dedicated Server Power add-on. Without it, choose **No power
  control**. A machine you set up before keeps its BMC details, and they work again once the add-on is on.
  - **Type**: **Redfish (HTTPS)** for iDRAC, iLO, Supermicro and most new servers; **IPMI** for older boards (only when
    it is turned on in the add-on's settings); or **No power control**.
  - **BMC address**: an IP address or a host name only, without `https://` or a port. Private addresses are fine.
    Nuvabill refuses loopback addresses (127.0.0.1, ::1, localhost), link-local and cloud metadata addresses, and
    multicast and reserved ranges, and checks every address a host name points to each time it connects. It does not
    refuse its own LAN or public address, or other hosts on its network, so enter only BMC addresses.
  - **BMC port**: empty uses 443 for Redfish and 623 for IPMI.
  - **BMC user name** and **BMC password**: the Operator user from above.
  - **Certificate check**: see the next step.

When you change the type, address or port, enter the login again and trust the new certificate.

## 3. Test and trust the BMC certificate

Most BMCs use a certificate they made themselves, which browsers and Nuvabill cannot check against a known authority.
Nuvabill uses **certificate pinning** instead: you check the certificate once, and from then on Nuvabill talks only to a
BMC that has exactly that key. It never sends the BMC login before you trust the certificate.

1. Keep **Certificate check** on **Trust the certificate I check (most BMCs)**, and save the machine.
2. On the machine page, press **Test connection**. Nuvabill reads the BMC's certificate without sending any login, and
   shows its SHA-256 fingerprint.
3. Open the BMC's web page in your browser, view its certificate, and compare the fingerprints. You can paste the one
   from your browser into **Fingerprint from the BMC page**, and Nuvabill compares them for you.
4. When they match, press **Trust this certificate**. Then press **Test connection** again: Nuvabill signs in, finds the
   system and reads which power actions it supports.

If the BMC has a certificate from a trusted authority, choose **The certificate is from a trusted CA** instead.

If a BMC shows several systems (a blade or multi-node chassis), choose which one this machine is. There are no power
buttons until you choose.

**After a firmware update or a BMC reset** the certificate often changes. Power actions then stop with "The BMC's
certificate changed". Press **Test connection**: Nuvabill shows the new certificate. Check it on the BMC page and trust
it again.

Pinning needs curl 7.39 or newer in your site's PHP. Without it, Nuvabill refuses pinned mode with "This site's PHP
cannot check BMC certificates": ask your host to update curl, or give the BMC a certificate from a trusted authority.

### IPMI

IPMI is older and less safe than Redfish. Use it only when a board has no Redfish, and only on a private network.

- Turn it on in **Marketplace → Dedicated Server Power → Settings → Offer IPMI for power control**. It needs the
  `ipmitool` program on Nuvabill's server, and PHP must be allowed to run it (`proc_open`). The settings page shows
  whether this site can use Redfish and IPMI.
- Nuvabill uses cipher suite 17 (or 3 for older boards), never 0. The password is handed to `ipmitool` privately, never
  on its command line.
- The BMC user needs the **Operator** privilege on the BMC's LAN channel (on most boards the user's "IPMI LAN
  privilege" or "Network privilege"). Nuvabill asks for an Operator session, which is enough to switch the power.
  With a user whose LAN privilege is only User or No Access, Nuvabill cannot connect.
- IPMI has no certificate to check, which is one more reason to keep it on a private network.

## Hardware settings

Staff with both the settings and the hardware permissions see **Hardware settings** on the Hardware page:

| Setting | Default |
|---|---|
| Systems clients can ask for (one per line) | Ubuntu 24.04, Debian 12, AlmaLinux 9, Rocky Linux 9 |
| Department for reinstall requests | The first visible department |
| Minutes to wait before forcing the power off | 10 |
| Show the location to the client | On |

## Day to day

### On the admin service page

A dedicated service has a **Dedicated server** card:

- **While the service is pending**: the steps, the **Server is ready** form, and **Assign machine** to choose another
  free machine.
- **Once it is in use**: the machine, its IP addresses and power buttons, and **Replace machine** to move the service
  to another machine (tick that you moved the data or the client agreed to start fresh). The old machine then waits
  for a wipe.
- **Services you had before**, for example imported from WHMCS: add the machine as **Available**, then use **Assign
  machine** on the active service. The machine goes straight into use, with no power action and no ready email.

### The Hardware page

**Hardware** lists every machine with its status, client, main IP and power state, and the free stock of each product.
Filter by status, product or location, or search by name or IP address. A machine's page shows its BMC, power buttons,
status buttons and its last 20 history entries, and cards that add-ons add (for example the switch port).

With Dedicated Server Power, a machine's page also has:

- **Next boot from**: start from the local disk, the network (PXE) or the BIOS setup on the next start only. Restart
  the server to use it.
- **Health**: press **Read now** to read the system, processor and memory health (Redfish) or the power, cooling and
  drive faults (IPMI), and the power use in watts where the BMC reports it.
- **Power history**: the last 10 power actions and boot device choices, by clients, staff, add-ons or the scheduler,
  and whether each worked.

The admin dashboard tells you when dedicated servers wait for an OS install, when machines wait for a disk wipe, and
when a suspended machine is still on.

### The five-minute check

The [cron job](installation.md#the-cron-job) runs `nuvabill:hardware` every five minutes once you have a machine. It:

- keeps each machine's status in step with its service, for example after a service was ended by hand;
- forces off suspended servers that are still on after the wait time, and checks them again every hour;
- switches machines that wait for a wipe off once.

It handles at most 20 machines and 2 minutes per run. If a BMC does not answer 3 times in a row, your team gets one
**Staff: dedicated server alert** email a day for that machine.

## Dedicated Server Power

1. **Marketplace** → **Dedicated Server Power** → buy and install it, then switch it on.
2. Under its **Settings**, check that Redfish shows **Ready**. Turn on IPMI only if you have older boards.
3. For each machine, choose the power control type, enter the BMC address and login, and test the connection
   (sections 2 and 3 above).

When you switch the add-on off, machines keep their BMC details and your team switches the power by hand again.

## What clients see

- **While their server is prepared**: "Your server is being prepared", with the CPU, RAM, storage and location.
- **Overview**: plan, host name, location, CPU, cores, RAM, storage, port speed and login name, and the last power
  action.
- **Power** (with Dedicated Server Power): the buttons the product allows. A forced action asks first. To protect the
  BMC, clients can press at most 6 power buttons in 10 minutes, and one action runs at a time. Without power control
  there are no buttons, and the overview tells clients to open a ticket for a restart.
- **IPs**: the main, included and rented extra addresses.
- **Reinstall**: a request for one of the systems in **Hardware settings**. It opens a support ticket; one open request
  at a time.

Clients never see the BMC, its address or login, the machine's name or its rack.

## Plan changes

A client can move to another dedicated product only when their machine is also sold as that product. Other plans are
not offered, because they would need other hardware.

## When something goes wrong

| Message or problem | What to do |
|---|---|
| We could not reach the BMC. | Check the BMC address and that Nuvabill's server can reach the management network (VPN, firewall). |
| The BMC refused the user name or password. | Check the BMC user and its role, then enter the login again. |
| Trust the BMC certificate first: run Test connection and check it. | Press **Test connection** and trust the certificate. |
| The BMC's certificate changed. | Press **Test connection**, compare the new fingerprint with the BMC page, and trust it again. |
| This site's PHP cannot check BMC certificates. | Ask your host to update curl, or use a certificate from a trusted authority. |
| No **Restart** button | The BMC does not offer a graceful restart (common on Supermicro). Use Shut down, then Power on. |
| No free machine for this product. | Add a machine sold as this product, or assign one on the service page. |
| Automatic power control needs the Dedicated Server Power add-on. | Install and switch on the add-on, or switch the machine by hand. |

## For add-on makers

Dedicated servers have these extension points. Register them in your add-on's `boot()`, and ask only for the
permissions you use ("hardware" for anything under Hardware, "database" for your own tables).

| Extension point | What it is for |
|---|---|
| `App\Provisioning\Hardware\Drivers\PowerDrivers` | `register(PowerDriver)`: a power control type. Optional `ChoosesBootDevice`, `ReadsHealth`, `ReadsPowerUsage`. Only `PowerControl` calls drivers, so the power lock, cool-down, activity log and power history (`machine_power_events`) always apply. |
| `App\Provisioning\Hardware\PowerControl` | The only way to a machine's power: `run()`, `state()`, `setBootDevice()`, `health()`. Pass the client, the staff member or the machine itself (automatic) as the actor. |
| `App\Provisioning\Hardware\MachineSections` | `add(fn (Machine $machine, Admin $admin): ?array)`: a card on a machine's page, with a view of your add-on (`ext-{slug}::name`). |
| `Addon::hardwareRoutes()` | Your pages and forms under `/admin/hardware/addons/{slug}/`, for staff with the hardware management permission and two-factor sign-in. Needs the "hardware" permission. |
| `App\Provisioning\Hardware\DedicatedPanelExtensions` | `add(DedicatedPanelExtension)`: tabs in the client server panel of dedicated servers, offered only while the service has a machine in use. |
| Events `DedicatedServerActivated`, `DedicatedServerSuspended`, `DedicatedServerUnsuspended`, `DedicatedServerReleased` | Sent after the machine's change is saved, with the machine and the service. For example, close a switch port on suspend and release. A listener that fails never stops the change. |
| `App\Provisioning\Hardware\MachineInstallers` | `register(MachineInstaller)`: take over the "Setting up" step. When done, call `ServerSetup::ready()` (the same code as the **Server is ready** button: the password is saved encrypted and never emailed); when it fails, call `ServerSetup::failed()`. |

Keep your own data, such as a machine's switch and port, in your own tables, and never send the BMC, a switch or a
machine's name to clients.
