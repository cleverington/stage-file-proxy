# Stage File Proxy

Mirror (or header to) uploaded files from a remote production site on your local development copy. Saves the trouble of downloading a giant uploads directory without sacrificing the images that accompany content.

## Installation

1. Add the following snippet **above** the `"type": "composer"` entry within the `"repositories":` section of your `composer.json` file:

```json
{
  "type": "vcs",
  "url": "git@github.com:cleverington/stage-file-proxy.git"
},
```

2. Install via composer using `--dev` so it only installs on non-production deployments:

```bash
composer require cleverington/stage-file-proxy:"*" --dev
```

3. Ensure that you're "not in Production":

Stage File Proxy only runs when `WP_ENVIRONMENT_TYPE` is **explicitly** set to `local`, `development`, or `staging`. It will not run when unset, invalid, or `production`.

```php
define( 'WP_ENVIRONMENT_TYPE', 'development' );
```

## Environment configuration (recommended)

WordPress projects can configure the plugin via environment variables. In `wp-config-local.php`, `LIVE_URL` (site root) is mapped to the `SFP_REMOTE_BASE_URL` constant (uploads base URL).

| Variable | Required | Purpose |
|---|---|---|
| `WP_ENVIRONMENT_TYPE` | Yes | Must be `local`, `development`, or `staging` |
| `LIVE_URL` | To enable | Site root URL, e.g. `https://example.com` |
| `STAGE_FILE_PROXY_MODE` | No | `download` or `header` (defaults to `download` when basic auth is in the URL) |

Example `.env`:

```dotenv
WP_ENVIRONMENT_TYPE=local
LIVE_URL="https://example.com"

# Basic auth (download mode is used automatically):
# LIVE_URL="https://user:pass@example.com"
# STAGE_FILE_PROXY_MODE=download
```

When `SFP_REMOTE_BASE_URL` is defined, the Settings screen shows the resolved URL as read-only.

## Setup

Stage File Proxy runs when WordPress is serving a 404 response for a request to the uploads directory. If your server intercepts these requests instead of passing them to WordPress, the plugin will not work.

There are four options for this plugin, though only two are currently available via the UI. WP-CLI can be used to tweak the setting though, such as adjusting the mode to `header`.

```shell
wp option update sfp_mode header
```

## Available options

* `sfp_mode`: The method used to retrieve the remote image. Default is `header` without auth, `download` when credentials are embedded in the remote URL. One of:
  * `download` (downloads the remote file to your machine)
  * `header` (serves the remote file directly)
  * `local` (like `download` but serves an image from a directory in the current parent theme if the download fails)
  * `photon` (like `header` but uses arguments compatible with []() to size the image)

* `sfp_url`: The absolute URL to the uploads directory on the source site (manual fallback when `SFP_REMOTE_BASE_URL` is not defined).

### No Composer Installation

1. Clone this repository into your `plugins/` directory.
2. If using version control, delete the `.git/` directory to prevent issues within parent Git history.

* `sfp_local_dir`: The name of the directory in the parent theme where images are stored for `local` mode.
