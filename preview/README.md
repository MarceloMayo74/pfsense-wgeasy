# Preview local de la pestaña Provision

Levanta la página `vpn_wg_provision.php` en el navegador, en Windows, sin pfSense.

## Correr

```powershell
powershell -ExecutionPolicy Bypass -File preview\serve.ps1
```

Y abrir <http://127.0.0.1:8088/>

| URL | Qué es |
|---|---|
| `/wgeasy/vpn_wg_provision.php` | La página |
| `/_state` | `config.json` (hace de config.xml), los `.conf` de túnel generados, los comandos que el paquete ejecutó, y la bandeja de salida de mails |
| `/_reset` | Borra el estado del firewall simulado y vuelve a sembrarlo |

## Verificación automática (sin navegador)

```powershell
.tools\php\php.exe -f preview\verify.php
```

52 chequeos de punta a punta: derivación de claves, guardado del peer, `.conf`
de túnel, direcciones duplicadas, doble stack IPv6, split tunnel, email, rechazo
de entradas inválidas, y autodetección de Dynamic DNS / RFC 2136 / IPs de
interfaz / reglas de firewall.

El firewall simulado incluye 2 túneles, 3 entradas de Dynamic DNS (una
deshabilitada, una de tipo split domain), 1 entrada RFC 2136, su archivo de
caché de IP, y reglas de firewall que cubren un túnel pero no el otro.

```powershell
.tools\php\php.exe -f preview\smoke.php post   # provisiona uno y muestra el .conf
```

## Qué es real y qué está simulado

**Real, se ejecuta tal cual se ejecutaría en el firewall:**

- `wgeasy/usr/local/www/wgeasy/vpn_wg_provision.php` y `wgeasy/usr/local/pkg/wgeasy.inc`
  (los archivos que se instalan, servidos desde su ubicación en el repo, sin copias)
- Del paquete nativo: `wg.inc`, `wg_api.inc`, `wg_globals.inc`, `wg_validate.inc`,
  `wg_guiconfig.inc` y `wgconfig.class.php` — sin modificar
- La derivación de claves: `fakebin.php` usa libsodium, así que `genkey`/`pubkey`
  producen un par Curve25519 auténtico igual que `wg(8)`

**Simulado (`preview/stubs/`):**

| Stub | Por qué |
|---|---|
| `config.inc` | `config.xml` → `preview/var/config.json` |
| `util.inc`, `interfaces.inc`, `filter.inc`, `gwlb.inc`, … | Primitivas de pfSense (`is_subnet`, `gen_subnet`, `ip_after`, …) |
| `guiconfig.inc` + `classes/Form.class.php` | Reimplementación de las clases `Form_*` de pfSense en Bootstrap 3 |
| `head.inc` / `foot.inc` | Cromo de página, menú VPN leído del mismo `menu/wgeasy.xml` que instala el paquete |
| `notices.inc` | `send_smtp_message()` escribe a `preview/var/mail/*.eml` en vez de enviar |
| `wireguard/includes/wg_install.inc` | El real instala earlyshellcmds, grupos de interfaz y ACLs de Unbound |
| `wireguard/includes/wg_service.inc` | El real usa `posix_kill()`, que no existe en Windows |
| `fakebin.php` | Reemplaza `wg`, `ifconfig` y `pkg` |

**Diferencias visuales esperadas contra el firewall real:** el CSS es una
aproximación de Bootstrap 3, no el tema de pfSense, y no hay Font Awesome (los
íconos de los botones no se ven). El layout, los estados y el flujo sí son
representativos.

## Limpiar

```powershell
Remove-Item -Recurse -Force preview\var    # estado del firewall simulado
Remove-Item -Recurse -Force .tools         # PHP portable (32 MB)
```
