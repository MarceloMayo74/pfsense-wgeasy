# Screenshots

Drop the PNGs here using exactly these names, and they get picked up by the
main README:

| File | What to capture |
|---|---|
| `easy-peers.png` | **VPN → WireGuard Easy** with a few peers listed, showing the action icons |
| `add-peer.png` | The Add Peer form, ideally the whole page so the three panels are visible |
| `client-file.png` | The result panel after saving: the `.conf`, the QR code and the buttons |
| `widget.png` | The dashboard widget listing connected peers |

Take them from a real firewall so they show the actual pfSense theme. The
preview harness looks close but its CSS is an approximation, so screenshots
taken there would misrepresent the real thing.

## Before publishing them, check what is visible

These are going on a public page. A screenshot of a working VPN page shows more
than it looks:

- **The endpoint / Dynamic DNS hostname.** This is the address of your VPN.
  Publishing it tells anyone where to find it. Type a placeholder like
  `vpn.example.com` into the field before the screenshot, or blur it.
- **Public keys.** Not secret, but they identify your peers. Blurring them costs
  nothing.
- **The private key field** in the Add Peer form. Turn on
  *Hide Secrets* under **VPN → WireGuard → Settings** first: the field becomes a
  password input and the screenshot is safe by construction.
- **Peer names, internal subnets and the tunnel address.** Harmless for most
  people, but they do describe your network.

The safest recipe: create a throwaway tunnel with fake addresses and one or two
peers named `phone`, `laptop`, take the screenshots there, and delete it
afterwards.

## Format

PNG, around 1200 to 1600 pixels wide. GitHub scales them down to the README
width, so anything larger only adds weight to the repository.
