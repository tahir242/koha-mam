# Try MAM for Koha in one command

This folder runs a complete, preconfigured evaluation setup on your computer:

| What | Address | Sign in |
|---|---|---|
| **Koha staff interface** (with the MAM plugin) | <http://staff.localhost:8081> | `librarian` / `Library2026` |
| **Koha OPAC** | <http://opac.localhost:8080> | — |
| **MAM application** | <http://mam.localhost:8000> | through Koha (*Attach Media*) |

Koha 26.05 comes from the official Koha Debian packages, with sample libraries, item types, patrons and six
sample records. The [Koha plugin](https://github.com/tahir242/koha-media-manager) is installed and connected
to the MAM: sign-in secret, Koha API key and system preferences are all set up for you.

> **For evaluation only.** Plain HTTP, fixed demo passwords and no virus scanning. Do not expose it to a network
> or put real collections in it. For production, follow the [User Guide](../docs/USER_GUIDE.md#14-installation-for-administrators).

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows, macOS) or Docker Engine with the
  Compose plugin (Linux)
- About **4 GB of memory** for Docker and **6 GB of disk**
- Free ports **8000**, **8080** and **8081**

## Start

```bash
git clone https://github.com/tahir242/koha-mam.git
cd koha-mam/demo
docker compose up -d --build
```

The first start builds the images and installs Koha. It takes **10–20 minutes**, depending on your connection.
Follow it with:

```bash
docker compose logs -f koha mam
```

It is ready when the log shows `MAM is ready`.

## Take the tour

1. Open <http://staff.localhost:8081> and sign in as **librarian** / **Library2026**.
2. Search the catalogue for `*` (or open *Alice's Adventures in Wonderland*).
3. Click **Attach Media** on the record toolbar, or **Actions → Attach Media** on one item.
4. The MAM opens in a new tab, already signed in. Upload a PDF, image, audio or video file.
5. Wait a moment while it is processed, then submit it, approve it and publish it.
6. Reload the record in Koha: the link is now in the record (MARC 856) or in the item's URL column (952$u).
   Open the same record in the OPAC at <http://opac.localhost:8080> to see what patrons get.

The plugin's dashboard is under **Administration → Plugins → Media Asset Management (MAM) → Run tool**. The
**Test connection** button there should say the MAM is reachable. Its **Go to MAM** button opens the MAM
dashboard once you have signed in through **Attach Media**; the MAM has no password of its own, so before
that it only shows "Sign in through Koha".

Typing `localhost:8081`, `localhost:8000` or `127.0.0.1` (as Docker Desktop's port links do) is fine: each
service redirects to its `*.localhost` address. Signing in only works on one host name, since Koha's login
cookie belongs to the host it was set on.

## Stop, reset, remove

| Command | Effect |
|---|---|
| `docker compose stop` | Stop; everything is kept |
| `docker compose up -d` | Start again |
| `docker compose down -v` | Delete the containers **and all data**; the next start is a fresh install |
| `docker compose down -v --rmi local` | Also delete the built images |

## Troubleshooting

| Problem | What to do |
|---|---|
| The `*.localhost` addresses do not open | Chrome, Edge and Firefox resolve them automatically. For other browsers, add `127.0.0.1 staff.localhost opac.localhost mam.localhost` to your hosts file. |
| A port is already in use | Stop the program that uses 8000, 8080 or 8081. To use other ports you must change them everywhere in `docker-compose.yml`, since the containers use the same addresses as your browser. |
| `koha` stays *unhealthy* or restarts | Run `docker compose logs koha`. Give Docker more memory (Docker Desktop → Settings → Resources). |
| Uploads stay in *Processing* | Run `docker compose logs mam`; the queue worker runs in the `mam` container. |
| Address pool overlaps | Another Docker network uses `172.30.177.0/24`. Change the subnet and the two fixed addresses in `docker-compose.yml`. |

## What is inside

| Service | Image | Notes |
|---|---|---|
| `db` | `mariadb:11.4` | Koha's database and the MAM's database |
| `koha` | built from `koha/` | Koha packages, Apache, Plack, Zebra, background workers; first boot runs `koha/setup/populate.pl` (database, demo users, records) and every boot runs `koha/setup/link_mam.pl` (plugin settings and credentials for the MAM) |
| `mam` | built from this repository (`mam/Dockerfile`) | PHP-FPM, Nginx, queue worker and scheduler; FFmpeg, Poppler, ImageMagick and LibreOffice |

Koha writes the MAM's credentials (the API key of the `mam-api` staff user and the sign-in secret) to a shared
volume, and the MAM reads them when it starts. They are generated on the first start and kept after that.

**Using a plugin build of your own:** put the `.kpz` in `demo/koha/plugins/` and run
`docker compose up -d --build`. Without a file there, the latest release is downloaded.

**A smaller image:** set `WITH_OFFICE: "0"` in `docker-compose.yml` to leave LibreOffice out (about 500 MB). Office
documents are then not converted for viewing.
