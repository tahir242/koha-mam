<?php

use App\Media\Processors\AudioProcessor;
use App\Media\Processors\DocumentProcessor;
use App\Media\Processors\ImageProcessor;
use App\Media\Processors\VideoProcessor;

/*
|--------------------------------------------------------------------------
| SIUT Media Asset Management (MAM) configuration
|--------------------------------------------------------------------------
|
| Every deployment-specific value is read from the environment. Nothing in
| this file is institution-specific apart from neutral defaults.
|
*/

$kohaStaffUrl = rtrim((string) env('KOHA_STAFF_URL', ''), '/');
$allowInsecureHttp = (bool) env('DAM_ALLOW_INSECURE_HTTP', false);

$csv = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [

    // Application release; the release workflow checks Git tags against it.
    'version' => '1.1.0',

    'institution' => [
        'name' => env('DAM_INSTITUTION_NAME', 'Sindh Institute of Urology and Transplantation'),
        'short_name' => env('DAM_INSTITUTION_SHORT_NAME', 'SIUT'),
        'library_name' => env('DAM_LIBRARY_NAME', 'SIUT Digital Library'),
    ],

    'branding' => [
        'primary' => env('DAM_BRAND_PRIMARY', '#063B5C'),
        'secondary' => env('DAM_BRAND_SECONDARY', '#B00A04'),
        'accent' => env('DAM_BRAND_ACCENT', '#00876C'),
        'text' => env('DAM_BRAND_TEXT', '#2D3748'),
    ],

    /*
    | Public base URL used to build the stable viewer links written into
    | MARC 856 $u. It must never change once links have been published; if
    | it does, list the previous prefixes in DAM_LEGACY_VIEWER_PREFIXES so
    | managed 856 fields remain recognisable.
    */
    'public_url' => rtrim((string) env('DAM_PUBLIC_URL', env('APP_URL', 'http://localhost')), '/'),
    'legacy_viewer_prefixes' => $csv(env('DAM_LEGACY_VIEWER_PREFIXES')),

    'koha' => [
        'instance_id' => env('KOHA_INSTANCE_ID', 'koha-main'),
        // Koha staff interface; it also serves the REST API (/api/v1).
        'staff_url' => $kohaStaffUrl,
        // OPAC URL used for local cover images (opac-image.pl).
        'opac_url' => rtrim((string) env('KOHA_OPAC_URL', ''), '/'),
        'auth' => [
            // "oauth" (client credentials, RESTOAuth2ClientCredentials) or "basic" (RESTBasicAuth)
            'method' => env('KOHA_API_AUTH', 'oauth'),
            'client_id' => env('KOHA_API_CLIENT_ID'),
            'client_secret' => env('KOHA_API_CLIENT_SECRET'),
            'username' => env('KOHA_API_USERNAME'),
            'password' => env('KOHA_API_PASSWORD'),
        ],
        'timeout' => (int) env('KOHA_API_TIMEOUT', 15),
        'connect_timeout' => (int) env('KOHA_API_CONNECT_TIMEOUT', 5),
        'verify_tls' => env('KOHA_API_VERIFY_TLS', true),
        'record_schema' => env('KOHA_RECORD_SCHEMA', 'MARC21'),
        'metadata_cache_ttl' => (int) env('KOHA_METADATA_CACHE_TTL', 3600),
        'cover' => [
            'koha_local' => (bool) env('KOHA_COVER_LOCAL', true),
            'cache_ttl' => (int) env('KOHA_COVER_CACHE_TTL', 86400),
        ],
    ],

    'sso' => [
        // Koha Media Manager plugin 2.x hand-off (code-v2 authorization-code exchange).
        // Must equal the plugin's "Client ID".
        'client_id' => env('KOHA_SSO_CLIENT_ID'),
        // 64 lowercase hex characters; the same value entered as the plugin's client secret.
        'client_secret' => env('KOHA_SSO_CLIENT_SECRET'),
        // Fixed, trusted Koha URLs derived from KOHA_STAFF_URL. Never taken from request input.
        'exchange_url' => $kohaStaffUrl.'/api/v1/contrib/media-manager/exchange',
        'return_url' => $kohaStaffUrl.'/cgi-bin/koha/plugins/run.pl',
        'plugin_class' => 'Koha::Plugin::Com::Library::MediaManager',
        'audience' => 'media-manager',
        // Browser state lifetime between /sso/koha/start and the callback.
        'state_ttl' => 120,
        // Plugin capabilities are an upper bound on what any MAM role may do.
        'capability_map' => [
            'media.access' => ['media.view_dashboard'],
            'media.upload' => ['media.upload', 'media.edit', 'media.replace', 'media.submit'],
            'media.manage' => ['media.review', 'media.approve', 'media.publish', 'media.unpublish', 'media.delete', 'media.view_audit'],
            'media.admin' => ['media.manage_storage', 'media.manage_settings'],
        ],
        // Absolute session lifetime; staff must re-enter through Koha after it.
        'absolute_lifetime_minutes' => (int) env('DAM_SESSION_ABSOLUTE_LIFETIME', 480),
        // TEST-ONLY: allow plain-HTTP Koha/MAM URLs. Never enable in production.
        'allow_insecure_http' => $allowInsecureHttp,
        // Production sign-in must arrive over HTTPS unless plain HTTP is allowed.
        'require_https' => env('APP_ENV') === 'production' && ! $allowInsecureHttp,
    ],

    'authorization' => [
        // Koha userids that always receive the administrator role.
        'bootstrap_admins' => $csv(env('DAM_BOOTSTRAP_ADMINS')),
        'default_role' => env('DAM_DEFAULT_ROLE', 'cataloguer'),
        // Reviewers may not approve assets they submitted themselves.
        'enforce_four_eyes' => (bool) env('DAM_ENFORCE_FOUR_EYES', true),
        'roles' => [
            'cataloguer' => [
                'label' => 'Cataloguer',
                'capabilities' => ['media.view_dashboard', 'media.upload', 'media.edit', 'media.replace', 'media.submit'],
            ],
            'trusted_cataloguer' => [
                'label' => 'Trusted cataloguer',
                'capabilities' => ['media.view_dashboard', 'media.upload', 'media.edit', 'media.replace', 'media.submit', 'media.publish', 'media.unpublish'],
            ],
            'reviewer' => [
                'label' => 'Reviewer',
                'capabilities' => ['media.view_dashboard', 'media.upload', 'media.edit', 'media.replace', 'media.submit', 'media.review', 'media.approve', 'media.publish', 'media.unpublish', 'media.view_audit'],
            ],
            'administrator' => [
                'label' => 'Administrator',
                'capabilities' => ['*'],
            ],
            'suspended' => [
                'label' => 'Suspended',
                'capabilities' => [],
            ],
        ],
    ],

    'publishing' => [
        'default_requires_approval' => (bool) env('DAM_DEFAULT_REQUIRES_APPROVAL', true),
        'max_attempts' => (int) env('DAM_PUBLICATION_MAX_ATTEMPTS', 10),
        // Backoff in seconds, indexed by attempt number (last value repeats).
        'backoff' => [60, 120, 300, 900, 1800, 3600],
        'marc' => [
            'link_text' => env('DAM_MARC_LINK_TEXT', 'View digital resource'),
            'restricted_note' => env('DAM_MARC_RESTRICTED_NOTE', 'Access restricted to authorised SIUT staff'),
            // Second indicator by relationship (MARC21 856).
            'relationship_indicators' => ['resource' => '0', 'version' => '1', 'related' => '2', 'unspecified' => ' '],
            // $3 materials specified by media type.
            'materials' => [
                'document' => 'Full text',
                'image' => 'Image',
                'video' => 'Video recording',
                'audio' => 'Audio recording',
            ],
            // Fields ignored when verifying that nothing else changed after a write.
            'volatile_tags' => ['005', '999'],
        ],
    ],

    'storage' => [
        // Quarantine area for files awaiting validation and malware scanning.
        'quarantine_disk' => 'dam_quarantine',
        // Local scratch space for media processing.
        'work_path' => env('DAM_WORK_PATH', storage_path('app/dam-work')),
        'default_local_root' => env('DAM_STORAGE_LOCAL_ROOT', storage_path('app/dam')),
        /*
        | Named S3/MinIO credential profiles. Backends stored in the database
        | reference a profile by name, so secrets stay in the environment and
        | never appear in the database or the administration interface.
        */
        's3_credentials' => [
            'default' => [
                'key' => env('DAM_S3_KEY'),
                'secret' => env('DAM_S3_SECRET'),
            ],
            'secondary' => [
                'key' => env('DAM_S3_SECONDARY_KEY'),
                'secret' => env('DAM_S3_SECONDARY_SECRET'),
            ],
        ],
    ],

    'upload' => [
        'max_bytes' => (int) env('DAM_UPLOAD_MAX_BYTES', 4 * 1024 * 1024 * 1024),
        'chunk_bytes' => (int) env('DAM_UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024),
        'per_minute' => (int) env('DAM_UPLOAD_CHUNKS_PER_MINUTE', 600),
        // Per-user quota of uploaded originals per rolling 24 hours (0 disables).
        'daily_quota_bytes' => (int) env('DAM_UPLOAD_DAILY_QUOTA_BYTES', 50 * 1024 * 1024 * 1024),
        // "block" or "warn" when the same checksum is already attached to the record.
        'duplicate_policy' => env('DAM_DUPLICATE_POLICY', 'block'),
        // Keep unsupported files for administrative review instead of rejecting them.
        'allow_unsupported' => (bool) env('DAM_ALLOW_UNSUPPORTED', false),
    ],

    'scanning' => [
        // "clamav" or "none"
        'driver' => env('DAM_MALWARE_SCANNER', 'clamav'),
        'required' => (bool) env('DAM_MALWARE_SCAN_REQUIRED', env('APP_ENV') === 'production'),
        'timeout' => (int) env('DAM_SCAN_TIMEOUT', 600),
    ],

    'notifications' => [
        // Horizon long-wait / failure notifications.
        'mail' => env('DAM_ALERT_EMAIL'),
    ],

    'processing' => [
        // Queue connection for conversions (needs retry_after > longest timeout).
        'queue_connection' => env('DAM_MEDIA_QUEUE_CONNECTION'),
        'binaries' => [
            'ffmpeg' => env('DAM_FFMPEG_BINARY', 'ffmpeg'),
            'ffprobe' => env('DAM_FFPROBE_BINARY', 'ffprobe'),
            'pdftoppm' => env('DAM_PDFTOPPM_BINARY', 'pdftoppm'),
            'pdfinfo' => env('DAM_PDFINFO_BINARY', 'pdfinfo'),
            'magick' => env('DAM_MAGICK_BINARY', 'magick'),
            'soffice' => env('DAM_SOFFICE_BINARY', 'soffice'),
            'ebook_convert' => env('DAM_EBOOK_CONVERT_BINARY'),
            'clamdscan' => env('DAM_CLAMDSCAN_BINARY', 'clamdscan'),
        ],
        // Prefix commands with `nice -n N` on POSIX hosts so Koha keeps priority.
        'nice' => env('DAM_PROCESS_NICE', 10),
        'threads' => (int) env('DAM_FFMPEG_THREADS', 2),
        'timeouts' => [
            'document' => 900,
            'image' => 300,
            'audio' => 1800,
            'video' => 14400,
        ],
        'image' => [
            'max_megapixels' => (int) env('DAM_IMAGE_MAX_MEGAPIXELS', 150),
            'large' => 2400,
            'medium' => 1200,
            'thumbnail' => 480,
            'memory_limit' => env('DAM_MAGICK_MEMORY', '512MiB'),
        ],
        'video' => [
            'hls_segment_seconds' => 6,
            // Renditions above the source height are skipped.
            'renditions' => [
                ['height' => 360, 'video_bitrate' => '800k', 'audio_bitrate' => '96k'],
                ['height' => 720, 'video_bitrate' => '2800k', 'audio_bitrate' => '128k'],
                ['height' => 1080, 'video_bitrate' => '5000k', 'audio_bitrate' => '160k'],
            ],
            'preset' => env('DAM_FFMPEG_PRESET', 'veryfast'),
        ],
        'audio' => [
            'bitrate' => '160k',
        ],
        'pdf' => [
            // Render page images in addition to the viewer PDF ("strict" viewing mode).
            'page_images' => (bool) env('DAM_PDF_PAGE_IMAGES', false),
            'page_image_dpi' => 110,
            'max_pages' => 2000,
        ],
    ],

    'delivery' => [
        // "php" (Symfony BinaryFileResponse), "x-accel" (Nginx) or "x-sendfile" (Apache mod_xsendfile)
        'driver' => env('DAM_DELIVERY_DRIVER', 'php'),
        'x_accel_prefix' => env('DAM_X_ACCEL_PREFIX', '/_dam_protected'),
        // Lifetime of signed media URLs handed to the viewer.
        'signed_url_ttl' => (int) env('DAM_SIGNED_URL_TTL', 7200),
        'requests_per_minute' => (int) env('DAM_MEDIA_REQUESTS_PER_MINUTE', 600),
        // Reject cross-site media requests (Sec-Fetch-Site / Referer based).
        'block_cross_site' => (bool) env('DAM_BLOCK_CROSS_SITE_MEDIA', true),
    ],

    'viewer' => [
        'watermark' => [
            'enabled' => (bool) env('DAM_WATERMARK_ENABLED', true),
            'text' => env('DAM_WATERMARK_TEXT', 'SIUT Digital Library'),
        ],
        'record_views' => (bool) env('DAM_RECORD_VIEWS', true),

        /*
        | Engagement-based view counting (YouTube-style). Opening the viewer is
        | an "open"; a view counts once the visitor has watched or read for the
        | qualifying time below, verified by the server.
        */
        'views' => [
            // Seconds of engagement that make a view. Audio and video use half
            // the duration instead when that is shorter.
            'qualifying_seconds' => [
                'video' => (int) env('DAM_VIEW_SECONDS_VIDEO', 30),
                'audio' => (int) env('DAM_VIEW_SECONDS_AUDIO', 30),
                'document' => (int) env('DAM_VIEW_SECONDS_DOCUMENT', 10),
                'image' => (int) env('DAM_VIEW_SECONDS_IMAGE', 5),
            ],
            // The same viewer counts again only after this many minutes…
            'repeat_after_minutes' => (int) env('DAM_VIEW_REPEAT_AFTER_MINUTES', 30),
            // …and at most this many times per resource per day.
            'max_per_viewer_per_day' => (int) env('DAM_VIEW_MAX_PER_VIEWER_PER_DAY', 5),
            // Staff views are recorded but not added to the public total.
            'count_staff' => (bool) env('DAM_VIEW_COUNT_STAFF', false),
            // A viewer page's view token stays valid this long.
            'token_ttl_seconds' => 6 * 3600,
        ],
    ],

    'audit' => [
        'retention_days' => (int) env('DAM_AUDIT_RETENTION_DAYS', 730),
        'access_event_retention_days' => (int) env('DAM_ACCESS_EVENT_RETENTION_DAYS', 365),
    ],

    'health' => [
        // Bearer token for machine access to /health.json (empty disables it).
        'token' => env('DAM_HEALTH_TOKEN'),
    ],

    /*
    | Media type registry. Each entry maps detected MIME types and extensions
    | to a processing strategy. Detection uses file contents (finfo); the
    | extension must also be on the allowlist for the detected MIME type.
    */
    'media_types' => [
        'document' => [
            'processor' => DocumentProcessor::class,
            'queue' => 'media-documents',
            'formats' => [
                'application/pdf' => ['pdf'],
                'application/msword' => ['doc'],
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
                'application/vnd.ms-powerpoint' => ['ppt'],
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
                'application/vnd.ms-excel' => ['xls'],
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
                'application/vnd.oasis.opendocument.text' => ['odt'],
                'text/plain' => ['txt'],
                'application/epub+zip' => ['epub'],
                // Legacy Office files are often detected as the generic CDF container.
                'application/CDFV2' => ['doc', 'xls', 'ppt'],
                'application/x-ole-storage' => ['doc', 'xls', 'ppt'],
            ],
        ],
        'image' => [
            'processor' => ImageProcessor::class,
            'queue' => 'media-images',
            'formats' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
                'image/tiff' => ['tif', 'tiff'],
                'image/gif' => ['gif'],
                'image/svg+xml' => ['svg'],
            ],
        ],
        'video' => [
            'processor' => VideoProcessor::class,
            'queue' => 'media-video',
            'formats' => [
                'video/mp4' => ['mp4', 'm4v'],
                'video/quicktime' => ['mov'],
                'video/x-msvideo' => ['avi'],
                'video/x-matroska' => ['mkv'],
                'video/webm' => ['webm'],
            ],
        ],
        'audio' => [
            'processor' => AudioProcessor::class,
            'queue' => 'media-audio',
            'formats' => [
                'audio/mpeg' => ['mp3'],
                'audio/x-wav' => ['wav'],
                'audio/wav' => ['wav'],
                'audio/aac' => ['aac'],
                'audio/x-hx-aac-adts' => ['aac'],
                'audio/flac' => ['flac'],
                'audio/x-flac' => ['flac'],
                'audio/ogg' => ['ogg', 'oga'],
                'audio/mp4' => ['m4a'],
                'video/webm' => ['weba'],
            ],
        ],
    ],
];
