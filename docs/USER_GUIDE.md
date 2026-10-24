# MAM — Media Asset Management for Koha — User Guide

**Version 1.0.0 (beta)** · Works with Koha 26.05 (minimum 22.11) and the
[Koha plugin](https://github.com/tahir242/koha-media-manager) · Author: Tahir Ali Channa

This guide is for **library staff** (cataloguers, reviewers) and the **administrators** who install and
run the MAM. Developers and system integrators should read the [Developer Guide](DEVELOPER_GUIDE.md).

---

## Contents

1. [What the MAM does](#1-what-the-mam-does)
2. [The two parts: the Koha plugin and the MAM](#2-the-two-parts-the-koha-plugin-and-the-mam)
3. [Signing in](#3-signing-in)
4. [A tour of the screens](#4-a-tour-of-the-screens)
5. [Attaching media to a record or an item](#5-attaching-media-to-a-record-or-an-item)
6. [What happens after upload](#6-what-happens-after-upload)
7. [Review, approval and publishing](#7-review-approval-and-publishing)
8. [Where published media appears](#8-where-published-media-appears)
9. [The public viewer](#9-the-public-viewer)
10. [Views: how they are counted](#10-views-how-they-are-counted)
11. [Replacing, unpublishing and archiving](#11-replacing-unpublishing-and-archiving)
12. [Roles and permissions](#12-roles-and-permissions)
13. [Administration](#13-administration)
14. [Installation for administrators](#14-installation-for-administrators)
15. [Configuration (.env)](#15-configuration-env)
16. [Background workers and the scheduler](#16-background-workers-and-the-scheduler)
17. [Troubleshooting](#17-troubleshooting)
18. [Frequently asked questions](#18-frequently-asked-questions)
19. [Glossary](#19-glossary)

---

## 1. What the MAM does

The **MAM (Media Asset Management)** is the library's home for digital media that belongs to catalogue
records: scanned books and theses, PDFs and Office documents, photographs, lecture recordings, audio
and video. Staff upload files straight from Koha; the MAM checks them, prepares them for safe viewing,
routes them through review and publishes a link back into Koha. Patrons and staff then open the media
in a secure online viewer.

| | |
|---|---|
| **One click from Koha** | Every record has an **Attach Media** button, and every item an **Attach Media** entry in its **Actions** menu. |
| **No extra password** | Staff are signed in with their Koha identity. |
| **Record or item** | Attach media to a title, or to one copy — even before it has a barcode. |
| **Any common format** | Documents (PDF, Word, PowerPoint, Excel, OpenDocument, text), images, audio and video, up to 4 GB per file. |
| **Safe by default** | Every file is checked, virus-scanned and stored privately; originals are never handed out. |
| **Review before publishing** | Configurable approval rules, with a four-eyes rule so nobody approves their own upload. |
| **Published into Koha** | Record media appears as an online resource (MARC 856); item media in the item's **URL** column (952$u). |
| **Secure viewer** | Read and play online — documents with search, adaptive video streaming, audio and zoomable images — with a watermark and no download button. |
| **View statistics** | YouTube-style view counts: real watching or reading, not page loads; repeat views and bots are filtered out. |
| **Complete audit trail** | Every sign-in, upload, decision and publication is recorded. |

## 2. The two parts: the Koha plugin and the MAM

| Part | What it is | Where |
|---|---|---|
| **Koha plugin** — *Media Asset Management (MAM) for Koha* | Adds the **Attach Media** button and menu entries to Koha and signs staff in securely. | [github.com/tahir242/koha-media-manager](https://github.com/tahir242/koha-media-manager) |
| **MAM application** (this project) | Upload, processing, storage, review, publishing, viewer, statistics, administration. | [github.com/tahir242/koha-mam](https://github.com/tahir242/koha-mam) |

Both are needed. The plugin is installed in Koha; the MAM runs on its own web server.

## 3. Signing in

There is **no MAM password**. To open the MAM:

1. Sign in to the Koha staff interface.
2. Open any record and click **Attach Media** (or use an item's **Actions → Attach Media**).
3. The MAM opens in a new tab, already signed in, on that record or item.

Your rights in the MAM come from your Koha permissions and your MAM role (see
[Roles and permissions](#12-roles-and-permissions)). Sessions end after a period of inactivity; open the
MAM from Koha again to continue.

## 4. A tour of the screens

| Screen | What you find there |
|---|---|
| **Dashboard** | Totals (media, published, awaiting approval, failed publications), public views in the last 30 days, **Most viewed**, recent uploads, processing progress, storage use, Koha connection and recent activity. |
| **Record workspace** | The Koha record (title, author, cover, edition, ISBN) with all its media, and the uploader. Opened from Koha. |
| **Item workspace** | The same, opened for one item: shows **Item #number** with barcode, copy and call number. Its media is listed first. |
| **Library** | All media across all records — search (title, record, item number, barcode), filter (type, status, access) and sort (newest, title, record, **most viewed**). Grid or list. |
| **Asset page** | Details, versions, Koha link, history, publishing panel and **Views** statistics for one resource. |
| **Approvals** | Media waiting for a reviewer's decision. |
| **Publications** | The queue of updates sent to Koha, with status and retry. |
| **Administration** | Koha integration, storage, publishing policies, settings, staff and roles, processing tools, audit log. |

## 5. Attaching media to a record or an item

1. In Koha, click **Attach Media** on the record, or **Actions → Attach Media** on an item.
2. In the MAM, drag files onto the upload area (or click **browse**). Several files can be uploaded at once;
   large files are sent in parts and can be cancelled.
3. Each file becomes a **media asset** and starts processing automatically.
4. Open an asset to set its **title**, **description** and **access**:
   - **Public** — anyone can view it once published.
   - **Restricted** — only signed-in staff can view it.
   - **Sensitive** — always needs review before publishing.
5. For record media you can also set the **relationship** (resource, version, related), **link text** and a
   **public note** shown with the link in the catalogue.

**Items without a barcode.** Items are identified by their **item number**, which every saved item has.
Save the item in Koha first; the barcode is shown in the MAM as soon as it is assigned.

## 6. What happens after upload

```
Uploaded ─► Validating ─► Virus scan ─► Processing ─► Ready for review
```

| Type | Formats | Prepared for viewing as |
|---|---|---|
| **Documents** | PDF, DOC/DOCX, PPT/PPTX, XLS/XLSX, ODT, TXT (EPUB optional) | A viewer PDF with thumbnail (optionally page images) |
| **Images** | JPEG, PNG, WebP, TIFF, GIF, SVG | Web-optimised sizes; location data (EXIF) removed |
| **Video** | MP4, MOV, AVI, MKV, WebM | Adaptive streaming (360p / 720p / 1080p) with a poster image |
| **Audio** | MP3, WAV, AAC, FLAC, OGG, M4A | Streaming audio |

Progress is shown live. If processing fails, the reason is shown on the asset page and an administrator can
**Retry processing**. Duplicate files on the same record or item are blocked (or warned about, depending on
settings), and each staff member has a daily upload quota.

## 7. Review, approval and publishing

1. When an asset is **Ready for review**, click **Publish** (or **Submit for approval**).
2. The library's **publishing policy** decides whether it is published straight away or goes to **Approvals**.
   Sensitive media always needs approval; reviewers cannot approve their own uploads.
3. A reviewer **approves** (publishing starts) or **rejects** with a reason that the uploader sees.
4. On publication the MAM updates Koha and **reads the change back to verify it**. Only then is the asset shown
   as **Published**.

If Koha is temporarily unavailable, publishing is retried automatically. Permanent problems (for example a
deleted record) are shown on the **Publications** page with a **Retry** button.

## 8. Where published media appears

| Attached to | Shown in Koha as | Seen in |
|---|---|---|
| **Record** | An online resource link (MARC **856**) | Record details, staff interface and OPAC |
| **Item** | The item's **URL** (MARC **952$u**) | **URL** column of the holdings table, staff interface and OPAC |

- Each item has **one** link, however many files it has. The link opens the file directly, or a list when
  there are several.
- A URL already catalogued on the item is kept; the MAM adds its link after it.
- The MAM only ever changes its own links and never touches any other catalogue data.

## 9. The public viewer

Every published resource has a permanent address (`/view/…`) that never changes, even when the file is
replaced or moved to other storage.

| Media | Viewer |
|---|---|
| Documents | Page navigation, zoom, fit to width, full-text search, full screen |
| Video | Adaptive streaming, seeking, full screen, mobile friendly |
| Audio | Streaming player |
| Images | Fit or actual size, full screen |

The viewer shows the catalogue details and a link back to the OPAC record, the **number of views**, and an
optional watermark. There is no download or print button, media links expire, and other websites cannot
embed the files.

> Viewing online is not copy protection: anything shown on a screen can be captured. For sensitive material,
> use **Restricted** access or do not publish.

## 10. Views: how they are counted

The MAM counts views the way video platforms do — **real engagement, not page loads**:

| Rule | Detail |
|---|---|
| **Watch or read first** | A view counts after **30 seconds** of playback for video and audio (or half the length of a shorter clip), **10 seconds** on a document, **5 seconds** on an image. Opening the page alone is recorded as an *open*, not a view. |
| **Verified by the server** | The viewer reports the view with a single-use token; the server checks that the time really passed. Views cannot be inflated by reloading or scripts. |
| **Repeat views** | The same viewer counts again only after **30 minutes**, and at most **5 times a day** per resource. |
| **Not counted** | Search engines and other bots, library **staff** (recorded separately), and previews of unpublished media. |
| **Private** | No IP addresses are stored. Visitors are recognised with a one-way code that changes every day. |

**Where to see them**

- **Viewer page:** "1,234 views" under the title.
- **Asset page → Views:** total views, last viewed, a 14-day chart, views and daily unique viewers in the
  last 30 days, page opens and staff views.
- **Library:** views on every card and a **Views** column; sort by **Most viewed**.
- **Dashboard:** public views in the last 30 days and the **Most viewed** list.

Administrators can change the thresholds and limits (see [Configuration](#15-configuration-env)).

## 11. Replacing, unpublishing and archiving

- **Replace** uploads a new version. For a published asset the current version keeps serving until the new one
  is processed and someone with publishing rights **makes it live**. The public link never changes.
- **Unpublish** ends public access immediately, then removes the link from Koha.
- **Archive** takes an asset out of daily lists; it can be restored.
- Changing the link text, note, relationship or access of a published record asset re-publishes its link
  automatically.

## 12. Roles and permissions

Your rights are the **combination** of your Koha permissions (sent by the plugin at sign-in) and your MAM role.
Koha is the upper limit.

| Koha permissions | Allows in the MAM (at most) |
|---|---|
| Cataloguer: `catalogue` + `plugins → tool` + `editcatalogue → edit_catalogue` | Dashboard, upload, edit, replace, submit |
| Superlibrarian | Everything: review, approve, publish, unpublish, delete, audit, administration |

| MAM role | Can |
|---|---|
| **Cataloguer** (default) | Upload, edit, replace, submit |
| **Trusted cataloguer** | + publish and unpublish |
| **Reviewer** | + review, approve, view audit log |
| **Administrator** | Everything |
| **Suspended** | Nothing (sign-in refused) |

Administrators assign roles in **Administration → Staff & roles**.

## 13. Administration

| Page | Use it to |
|---|---|
| **Koha integration** | See the connection status, test the Koha API and the sign-in configuration. |
| **Storage** | Add local, NAS, shared-drive or S3/MinIO storage; choose the default; restrict storage by media type; check health and free space. |
| **Publishing policies** | Decide which roles and media types may be published without approval. |
| **Settings** | Default approval rule, watermark, metadata cache time and other non-secret settings. |
| **Staff & roles** | Assign MAM roles; suspend accounts. |
| **Processing** | See which conversion tools are installed, limits, supported formats and failed jobs. |
| **Audit log** | Every sign-in, upload, decision, publication and settings change. |

## 14. Installation for administrators

**Requirements**

- PHP **8.3+** with `fileinfo`, `gd`, `intl`, `zip`, `mbstring` and a database driver; Composer 2; Node 20+.
- A web server (Nginx or Apache) with the document root on `public/`.
- A database: MySQL/MariaDB or PostgreSQL (SQLite for small or test installations).
- Media tools: FFmpeg/ffprobe, Poppler, ImageMagick, LibreOffice; ClamAV for virus scanning.
- Koha 26.05 (minimum 22.11) with the [Koha plugin](https://github.com/tahir242/koha-media-manager) installed.

**Install**

1. Download `koha-mam-v1.0.0-beta.zip` from the
   [Releases page](https://github.com/tahir242/koha-mam/releases) and unpack it (it already contains the PHP
   dependencies and the built front end), or clone the repository and build it (see the
   [Developer Guide](DEVELOPER_GUIDE.md#14-building-and-deploying)).
2. Create the configuration and database:

   ```bash
   cp .env.example .env
   php artisan key:generate
   # edit .env — see the next section
   php artisan migrate --force --seed
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```

3. Point the web server at `public/`, make `storage/` and `bootstrap/cache/` writable, and allow 16–20 MB
   request bodies (uploads are sent in parts).
4. Start the background worker and the scheduler (section 16).

**Koha side**

1. Install and configure the [Koha plugin](https://github.com/tahir242/koha-media-manager) with the same
   Instance ID, Client ID and secret as the MAM.
2. Turn on the system preference **RESTOAuth2ClientCredentials**.
3. Create a staff user for the MAM (for example `mam-api`) with `catalogue`, `editcatalogue → edit_catalogue`
   and `editcatalogue → edit_items`, and generate an **API key** for it (*More → Manage API keys*).
4. In the plugin dashboard, press **Test connection**; in the MAM, run `php artisan dam:sso:doctor`.

## 15. Configuration (.env)

Only these values are needed; everything else has a sensible default in `config/dam.php`.
**Run `php artisan config:cache` after every change.**

| Setting | Example | Purpose |
|---|---|---|
| `APP_NAME` | `"Koha MAM"` | Name shown in the interface |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | Production mode |
| `APP_URL` | `https://mam.example.org` | The MAM's address; used in every published link — **do not change after go-live** |
| `DB_CONNECTION` (+ `DB_*`) | `mysql` | Database |
| `KOHA_INSTANCE_ID` | `mylibrary` | Must equal the plugin's **Instance ID** |
| `KOHA_STAFF_URL` | `https://staff.koha.example.org` | Koha staff interface (REST API and sign-in exchange) |
| `KOHA_OPAC_URL` | `https://koha.example.org` | Koha OPAC (catalogue links and covers) |
| `KOHA_API_CLIENT_ID` / `KOHA_API_CLIENT_SECRET` | | API key of the MAM's Koha user |
| `KOHA_SSO_CLIENT_ID` | `koha-mam` | Must equal the plugin's **Client ID** |
| `KOHA_SSO_CLIENT_SECRET` | 64 hex characters | Must equal the plugin's **Client secret** (`openssl rand -hex 32`) |
| `DAM_BOOTSTRAP_ADMINS` | `librarian1` | Koha userids that always become MAM administrators |
| `DAM_MALWARE_SCANNER` / `DAM_MALWARE_SCAN_REQUIRED` | `clamav` / `true` | Virus scanning (required in production) |
| `DAM_LIBRARY_NAME`, `DAM_WATERMARK_TEXT` | `City Digital Library` | Branding on the public viewer |
| `DAM_ALLOW_INSECURE_HTTP` | `false` | `true` **only** on an isolated test network without HTTPS |

**View counting** (optional):

| Setting | Default | Meaning |
|---|---|---|
| `DAM_RECORD_VIEWS` | `true` | Record opens and views at all |
| `DAM_VIEW_SECONDS_VIDEO` / `_AUDIO` / `_DOCUMENT` / `_IMAGE` | 30 / 30 / 10 / 5 | Engagement needed for a view |
| `DAM_VIEW_REPEAT_AFTER_MINUTES` | 30 | Pause before the same viewer counts again |
| `DAM_VIEW_MAX_PER_VIEWER_PER_DAY` | 5 | Daily cap per viewer and resource |
| `DAM_VIEW_COUNT_STAFF` | `false` | Add staff views to the public total |

## 16. Background workers and the scheduler

Uploads are processed and links published **in the background**. Without a worker, files stay in
*Processing* and publications stay *Pending*.

```bash
php artisan queue:work --queue=publication,ingest,default,media-images,media-documents,media-audio,media-video
```

(On Linux with Redis, `php artisan horizon` runs and supervises all queues.) Keep it running with systemd,
Supervisor or the Windows Task Scheduler. Also run the scheduler every minute:

```bash
* * * * * cd /path/to/mam && php artisan schedule:run >> /dev/null 2>&1
```

## 17. Troubleshooting

| Symptom | What to check |
|---|---|
| "Single sign-on is not configured correctly" | Run `php artisan dam:sso:doctor`; Instance ID, Client ID or secret differ from the plugin; run `config:cache` after editing `.env`. |
| "The sign-in from Koha expired or was already used" | The page was refreshed or more than two minutes passed: click **Attach Media** again. Use the same MAM address everywhere (`APP_URL`). |
| Record shows "Koha is currently unreachable" | **Administration → Koha integration → Test connection**; API key, `RESTOAuth2ClientCredentials`, certificates. |
| Uploads stay in *Processing* | The queue worker is not running, or a conversion tool is missing (**Administration → Processing**). |
| "Malware scanning is required" | ClamAV (`clamd`) is not running. |
| Upload fails with HTTP 500 on Apache/FastCGI | Raise `FcgidMaxRequestLen` (or lower `DAM_UPLOAD_CHUNK_BYTES`). |
| Publication `unauthorized` | The MAM's Koha user lacks `edit_catalogue` (records) or `edit_items` (items). |
| Publication *needs review* | Koha accepted the update but something differs on read-back; compare on **Publications**. |
| Views stay at zero | Views count only after real engagement by non-staff visitors; staff views are shown separately on the asset page. |
| Video stops after a long pause | The media link expired; reload the page. |

Logs: `storage/logs/laravel.log` and **Administration → Audit log**.

## 18. Frequently asked questions

**Do we need two systems?** Yes: the Koha plugin (inside Koha) and the MAM (its own web server). They are
designed and released together.

**Does the MAM change our catalogue?** Only its own links: one 856 per record asset, one URL per item. Every
other field is preserved and every write is verified.

**Can patrons download files?** No download button is offered and originals are never served, but no website
can fully prevent screen capture.

**Where are the files stored?** On storage you control: a local disk, a NAS or shared drive, or S3/MinIO.

**What if Koha is down?** The MAM keeps working with cached record details and publishes later automatically.

**How are views different from page opens?** A view needs real watching or reading and is filtered for repeats,
bots and staff; opens are every page load.

## 19. Glossary

| Term | Meaning |
|---|---|
| **Asset** | One piece of media (with its versions) attached to a record or item. |
| **Version** | One uploaded file of an asset; a replacement creates a new version. |
| **Record / item** | A Koha bibliographic record / one physical copy of it. |
| **856 / 952$u** | MARC fields for online links on a record / on an item. |
| **Publishing policy** | Rule deciding whether media needs approval before publishing. |
| **View / open** | An engaged, counted viewing / any load of the viewer page. |
| **Restricted** | Visible to signed-in staff only. |
