# pfSense-pkg-wgeasy (WireGuard Easy) — instalación

Agrega **VPN > WireGuard Easy**, una pestaña única "Easy Peers" para crear
clientes de WireGuard y entregarles su `.conf`, sin tocar ni un archivo de
`pfSense-pkg-WireGuard-0.2.9_6`.

Probado contra pfSense 2.8.1 + pfSense-pkg-WireGuard 0.2.9_6.

## Dónde quedan los datos

Los peers se crean con la API nativa (`wg_do_peer_post()`), así que se
administran también desde `VPN > WireGuard > Peers` como cualquier otro.

Para poder volver a entregar el archivo de un cliente, esta página guarda la
clave privada del cliente y sus ajustes en un elemento propio del peer
(`<wgeasy>` dentro de `<item>`). **Eso implica que las claves privadas de los
clientes quedan en `config.xml`, y por lo tanto en los backups y en el ACB.**
Los peers creados a mano en la página nativa no tienen ese elemento y aparecen
en el listado como "Not available": se pueden editar, pero no se les puede
generar un `.conf` sin re-generarles las claves.

## Opción A (recomendada): instalar como paquete .pkg

No hay nada que compilar (es PHP), pero se puede empaquetar como un `.pkg` de
FreeBSD para instalar y desinstalar limpio con `pkg`:

```powershell
# En tu PC (desde la raíz del repo):
powershell -ExecutionPolicy Bypass -File build\make-pkg.ps1
```

Eso genera `dist\pfSense-pkg-wgeasy-0.3.4.pkg` (verificado contra el formato
del paquete de terceros que ya funciona en este firewall). Luego:

```sh
scp dist/pfSense-pkg-wgeasy-0.3.4.pkg root@192.168.1.1:/root/
ssh root@192.168.1.1 pkg add /root/pfSense-pkg-wgeasy-0.3.4.pkg
```

Y entrá a `https://<firewall>/wgeasy/vpn_wg_easy.php` (o VPN > WireGuard
Easy en el menú). Para desinstalar:

```sh
pkg delete -y pfSense-pkg-wgeasy
```

Notas:

- `pkg add` avisa que el paquete no viene de un repositorio — es esperado.
- Las **actualizaciones de pfSense** reinstalan solo los paquetes del repo
  oficial; después de actualizar pfSense, volvé a correr el `pkg add`.
- Si `pkg add` rechazara el archivo por algún motivo, usá la opción B, que
  copia exactamente los mismos archivos.

## Opción B: instalación manual por SCP

## 0. Requisitos

- `pfSense-pkg-WireGuard` instalado y funcionando (la página lo requiere via
  `wireguard/includes/wg.inc`).
- Al menos un túnel creado en `VPN > WireGuard > Tunnels` con clave y listen port.
- SSH habilitado en el firewall (`System > Advanced > Admin Access`).

## 1. Archivos y destinos

| Origen (este repo)                                     | Destino en pfSense                                       | Permisos |
|--------------------------------------------------------|----------------------------------------------------------|----------|
| `usr/local/www/wgeasy/vpn_wg_easy.php`                  | `/usr/local/www/wgeasy/vpn_wg_easy.php`                    | `0644 root:wheel` |
| `usr/local/www/wgeasy/vpn_wg_easy_edit.php`             | `/usr/local/www/wgeasy/vpn_wg_easy_edit.php`               | `0644 root:wheel` |
| `usr/local/www/wgeasy/js/wgeasy_qrcode.js`              | `/usr/local/www/wgeasy/js/wgeasy_qrcode.js`               | `0644 root:wheel` |
| `usr/local/pkg/wgeasy.inc`                              | `/usr/local/pkg/wgeasy.inc`                               | `0644 root:wheel` |
| `usr/local/pkg/wgeasy.xml`                              | `/usr/local/pkg/wgeasy.xml`                               | `0644 root:wheel` |
| `usr/local/share/pfSense/menu/wgeasy.xml`               | `/usr/local/share/pfSense/menu/wgeasy.xml`                | `0644 root:wheel` |
| `usr/local/share/pfSense-pkg-wgeasy/info.xml`           | `/usr/local/share/pfSense-pkg-wgeasy/info.xml`            | `0644 root:wheel` |
| `etc/inc/priv/wgeasy.priv.inc`                          | `/etc/inc/priv/wgeasy.priv.inc`                           | `0644 root:wheel` |
| `tools/wgeasy_menu.php`                                 | `/root/wgeasy_menu.php` (opcional, solo fallback de menú) | `0600 root:wheel` |

## 2. Copiar por SCP

Desde tu máquina, parado en el directorio `wgeasy/` de este repo
(reemplazá `192.168.1.1` por la IP de tu firewall):

```sh
# 1) Crear los directorios destino
ssh root@192.168.1.1 'mkdir -p /usr/local/www/wgeasy/js /usr/local/share/pfSense/menu /usr/local/share/pfSense-pkg-wgeasy /etc/inc/priv'

# 2) Copiar los archivos
scp usr/local/www/wgeasy/vpn_wg_easy.php        root@192.168.1.1:/usr/local/www/wgeasy/
scp usr/local/www/wgeasy/vpn_wg_easy_edit.php   root@192.168.1.1:/usr/local/www/wgeasy/
scp usr/local/www/wgeasy/js/wgeasy_qrcode.js    root@192.168.1.1:/usr/local/www/wgeasy/js/
scp usr/local/pkg/wgeasy.inc                    root@192.168.1.1:/usr/local/pkg/
scp usr/local/pkg/wgeasy.xml                    root@192.168.1.1:/usr/local/pkg/
scp usr/local/share/pfSense/menu/wgeasy.xml     root@192.168.1.1:/usr/local/share/pfSense/menu/
scp usr/local/share/pfSense-pkg-wgeasy/info.xml root@192.168.1.1:/usr/local/share/pfSense-pkg-wgeasy/
scp etc/inc/priv/wgeasy.priv.inc                root@192.168.1.1:/etc/inc/priv/
scp tools/wgeasy_menu.php                       root@192.168.1.1:/root/
```

En Windows (PowerShell) es el mismo comando `scp`; si copiaste los archivos
con un editor, verificá que queden con finales de línea **LF**, no CRLF.

## 3. Permisos

```sh
ssh root@192.168.1.1
chown -R root:wheel /usr/local/www/wgeasy /usr/local/share/pfSense-pkg-wgeasy
chmod 0755 /usr/local/www/wgeasy /usr/local/www/wgeasy/js
chmod 0644 /usr/local/www/wgeasy/vpn_wg_easy.php \
           /usr/local/www/wgeasy/vpn_wg_easy_edit.php \
           /usr/local/www/wgeasy/js/wgeasy_qrcode.js \
           /usr/local/pkg/wgeasy.inc \
           /usr/local/pkg/wgeasy.xml \
           /usr/local/share/pfSense/menu/wgeasy.xml \
           /usr/local/share/pfSense-pkg-wgeasy/info.xml \
           /etc/inc/priv/wgeasy.priv.inc
chmod 0600 /root/wgeasy_menu.php
```

## 4. Verificar sintaxis PHP (recomendado antes de abrir la GUI)

```sh
php -l /usr/local/pkg/wgeasy.inc
php -l /usr/local/www/wgeasy/vpn_wg_easy.php
php -l /usr/local/www/wgeasy/vpn_wg_easy_edit.php
php -l /etc/inc/priv/wgeasy.priv.inc
```

Los cuatro deben responder `No syntax errors detected`.

## 5. Recargar la GUI

No hace falta reiniciar ningún servicio ni el firewall. El menú y los
privilegios se leen en cada request de la GUI:

```sh
# Suficiente en la mayoría de los casos: cerrar sesión y volver a entrar en la GUI.
# Si querés forzar el reinicio del webConfigurator:
/etc/rc.restart_webgui
```

Después entrá a `VPN > WireGuard Easy`, o directo a
`https://<firewall>/wgeasy/vpn_wg_easy.php`.

## 6. Si la entrada de menú no aparece

pfSense construye el menú de paquetes desde `config.xml`. La entrada en
`/usr/local/share/pfSense/menu/wgeasy.xml` funciona en las versiones que
soportan ese directorio; si en tu 2.8.1 no aparece, registrala en `config.xml`:

```sh
php -f /root/wgeasy_menu.php add
```

Para revertir: `php -f /root/wgeasy_menu.php remove`.

La página funciona igual por URL directa aunque no haya entrada de menú.

## 7. Permisos de usuarios no admin

Los usuarios con `WebCfg - All pages` ya tienen acceso. Para el resto, asigná
el privilegio nuevo en `System > User Manager`:

> **WebCfg - VPN: WireGuard Easy**

## 8. Desinstalar

```sh
rm -rf /usr/local/www/wgeasy /usr/local/share/pfSense-pkg-wgeasy
rm -f /usr/local/pkg/wgeasy.inc /usr/local/pkg/wgeasy.xml
rm -f /usr/local/share/pfSense/menu/wgeasy.xml
rm -f /etc/inc/priv/wgeasy.priv.inc
php -f /root/wgeasy_menu.php remove   # solo si usaste el fallback del paso 6
```

Los peers creados con esta página quedan en `config.xml` como cualquier otro
peer del paquete nativo; se administran y borran desde `VPN > WireGuard > Peers`.
