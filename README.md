# PTV API

Provides integration with the Finnish **PTV (Palvelutietovaranto)** API for Drupal, enabling migration of services and service channels into your site via Drupal's Migrate framework.

## Requirements

- Drupal 11+
- [Key](https://www.drupal.org/project/key) module (`^1.22`)
- [Parsedown](https://github.com/erusev/parsedown) PHP library (`^1.8`)
- Drupal core Migrate module

## Installation

1. **Require the module and its dependencies via Composer**
    
    You need the following addition in your `composer.json` under the `repositories` section:

    ```
        {
            "type": "vcs",
            "url": "https://github.com/wunderio/ptv_api"
        },
    ```
    This will also install the required `drupal/key` module and the `erusev/parsedown` library
2. **Enable the module** using Drush:
    ```
    drush en ptv_api
    ```
    Or navigate to **Administration → Extend** (`/admin/modules`), find **PTV API** in the list, check the box, and click **Install**.

## Configuration

1. **Store your API key** using the [Key](https://www.drupal.org/project/key) module at `/admin/config/system/keys`.
  - For ddev environments, store the key in `.ddev/.env` as an environment variable.
2. Navigate to **Administration → Configuration → Web services → PTV API Settings** (`/admin/config/services/ptv-api`).
3. Select the **PTV environment** (Production or Training).
4. Select the **API key** from the Key repository.

## Features

### PTV API Client

The `ptv_api.client` service (`PtvClient`) handles all communication with the PTV API, including:

- Authenticated requests using an API key managed by the Key module.
- Automatic pagination for search endpoints.
- Response caching (4-hour TTL by default) via a dedicated cache bin (`ptv_bin`).
- Service search, service channel search, and connection search.
- Postal code lookups via the Finnish national geo WFS service (`geo.stat.fi`).
  - This will be removed when we get post offices to offical end point.

### Migrate Plugins

The module provides migrate **source** and **process** plugins for building PTV migration pipelines:

**Source plugins:**

| Plugin ID                      | Description                        |
|--------------------------------|------------------------------------|
| `ptv_services_source`          | Fetches services from PTV.         |
| `ptv_service_channels_source`  | Fetches service channels from PTV. |

Both support configurable search parameters, field mappings, language filtering, and optional skipping of missing translations.

**Process plugins:**

| Plugin ID                | Description                                              |
|--------------------------|----------------------------------------------------------|
| `ptv_service_connection` | Resolves service ↔ channel connections from the PTV API. |
| `ptv_get_city_name`      | Looks up city/area name for a postal code via geo WFS.   |
| `parse_md`               | Converts Markdown text to HTML using Parsedown.          |

## Cache

API responses are stored in the `ptv_bin` cache bin. To clear cached PTV data: `drush cr`

## License

GPL-2.0-or-later
