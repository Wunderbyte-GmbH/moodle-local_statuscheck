# Moodle System Status Check Web Service

A Moodle local plugin that provides web service API endpoints for retrieving system status checks. This plugin exposes the same checks available via the CLI `admin/cli/checks.php` command through a REST API, making it easy to integrate Moodle health monitoring into external monitoring systems.

## Features

- **Comprehensive Status Checks**: Access all Moodle system checks via REST API
- **Filtered Results**: Retrieve specific check types (status, security, performance)
- **Simple Health Endpoint**: Lightweight endpoint for uptime monitoring
- **JSON Output**: Clean, structured JSON responses
- **Secure Access**: Requires proper authentication and capabilities
- **Moodle Coding Standards**: Follows all Moodle development best practices

## Requirements

- Moodle 4.5 or later
- PHP 8.1 or later
- Web services enabled
- User with `report/status:view` capability

## Installation

### Method 1: Manual Installation

1. Download or clone this repository
2. Extract the contents to `{moodle_root}/local/statuscheck/`
3. Log in as administrator
4. Navigate to **Site administration → Notifications**
5. Follow the installation prompts

### Method 2: Git Installation

```bash
cd {moodle_root}/local/
git clone https://github.com/yourusername/moodle-local_statuscheck.git statuscheck
```

Then visit **Site administration → Notifications** to complete installation.

## Configuration

### 1. Enable Web Services

1. Navigate to **Site administration → Advanced features**
2. Enable **Enable web services** checkbox
3. Save changes

### 2. Enable REST Protocol

1. Navigate to **Site administration → Server → Web services → Manage protocols**
2. Enable **REST protocol**

### 3. Create Service User

1. Create a dedicated user account for API access (recommended)
2. Navigate to **Site administration → Users → Permissions → Assign system roles**
3. Assign **Manager** role or create custom role with `report/status:view` capability

### 4. Generate Web Service Token

1. Navigate to **Site administration → Server → Web services → Manage tokens**
2. Click **Add**
3. Select the service user created in step 3
4. Select **Status Check Service** from the service dropdown
5. Save and copy the generated token

### 5. Enable the Service

1. Navigate to **Site administration → Server → Web services → Manage services**
2. Find **Status Check Service**
3. Click the **Enable** icon
4. Optionally configure **Authorised users only** for additional security

## Usage

### Web Service Functions

The plugin provides three web service functions:

#### 1. `local_statuscheck_get_system_status`

Retrieves comprehensive system status checks.

**Parameters:**
- `type` (string, optional): Type of checks to retrieve
    - `all` (default): All checks
    - `status`: Status checks only
    - `security`: Security checks only
    - `performance`: Performance checks only

**Returns:**
- `summary`: Summary statistics (total, ok, warning, error, critical, info, unknown)
- `checks`: Array of individual check results
- `timestamp`: Unix timestamp of the check
- `moodleversion`: Moodle version number
- `moodlerelease`: Moodle release version

#### 2. `local_statuscheck_get_health_status`

Simple health check endpoint for monitoring systems.

**Parameters:** None

**Returns:**
- `healthy`: Boolean indicating overall system health
- `status`: String status (ok, warning, error, critical)
- `timestamp`: Unix timestamp of the check

#### 3. `local_statuscheck_get_plugin_inventory`

Read-only inventory of installed plugins. Added to the **Status Check Service**, so
tokens issued before the upgrade can call it without being reissued.

**Parameters:**
- `plugins` (array of component names, optional): e.g. `mod_booking`, `auth_saml2`
    - **Omit** the parameter to return **all** installed plugins.
    - Provide a **one-item** list for a single-plugin lookup.
    - Provide **several** items to look up multiple plugins.
    - An **explicitly empty** list returns a validation error.

**Returns:** `plugins` — an array of entries, each with:
- `component`: Moodle component name (e.g. `mod_booking`)
- `displayname`: Human-readable plugin name (`""` when not installed)
- `version`: Installed version number (`0` when not installed)
- `status`: One of:
    - `installed` — code present on disk and registered in the database
    - `not_installed` — a valid component that is absent from this site
    - `missing_from_disk` — recorded in the database but the code is gone (the recorded `version` is still reported)
- `installation_source`: One of `git`, `zip`, `unknown` (see [Installation source](#installation-source-determination))

The response is intentionally limited to inventory fields. Absolute paths, Git
remote URLs, configuration values and credentials are never included.

### API Examples

#### Get All Status Checks

```bash
curl "https://your-moodle.com/webservice/rest/server.php?wstoken=YOUR_TOKEN&wsfunction=local_statuscheck_get_system_status&moodlewsrestformat=json&type=all"
```

#### Get Security Checks Only

```bash
curl "https://your-moodle.com/webservice/rest/server.php?wstoken=YOUR_TOKEN&wsfunction=local_statuscheck_get_system_status&moodlewsrestformat=json&type=security"
```

#### Simple Health Check

```bash
curl "https://your-moodle.com/webservice/rest/server.php?wstoken=YOUR_TOKEN&wsfunction=local_statuscheck_get_health_status&moodlewsrestformat=json"
```

#### Plugin Inventory

> **Pass the token in the POST body, not the URL.** Tokens in the query string
> can leak into web-server logs, proxy logs and browser history. The examples
> below read the token from the `MOODLE_WS_TOKEN` environment variable and send
> it in the request body over HTTPS. Never hard-code a real token.

Set the token once (keep it out of code and shell history):

```bash
export MOODLE_WS_TOKEN="your_token_here"
```

Look up a **single plugin**:

```bash
curl -X POST "https://your-moodle.com/webservice/rest/server.php" \
  --data-urlencode "wstoken=${MOODLE_WS_TOKEN}" \
  --data-urlencode "wsfunction=local_statuscheck_get_plugin_inventory" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "plugins[0]=mod_booking"
```

Look up **several plugins**:

```bash
curl -X POST "https://your-moodle.com/webservice/rest/server.php" \
  --data-urlencode "wstoken=${MOODLE_WS_TOKEN}" \
  --data-urlencode "wsfunction=local_statuscheck_get_plugin_inventory" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "plugins[0]=mod_booking" \
  --data-urlencode "plugins[1]=auth_saml2"
```

Return the **full inventory** (omit the `plugins` parameter):

```bash
curl -X POST "https://your-moodle.com/webservice/rest/server.php" \
  --data-urlencode "wstoken=${MOODLE_WS_TOKEN}" \
  --data-urlencode "wsfunction=local_statuscheck_get_plugin_inventory" \
  --data-urlencode "moodlewsrestformat=json"
```

### Sample Response

#### Detailed Status Check Response

```json
{
  "summary": {
    "total": 15,
    "ok": 12,
    "warning": 2,
    "error": 1,
    "critical": 0,
    "info": 0,
    "unknown": 0
  },
  "checks": [
    {
      "id": "debugdb",
      "name": "Debugging database queries",
      "type": "performance",
      "status": "ok",
      "summary": "Not showing database debug messages",
      "details": "",
      "component": "core",
      "actionlink": null
    },
    {
      "id": "passwordpolicy",
      "name": "Password policy",
      "type": "security",
      "status": "warning",
      "summary": "Weak password policy configured",
      "details": "Consider enabling password complexity requirements",
      "component": "core",
      "actionlink": "https://your-moodle.com/admin/settings.php?section=sitepolicies"
    }
  ],
  "timestamp": 1728000000,
  "moodleversion": "2024042200",
  "moodlerelease": "4.5"
}
```

#### Health Check Response

```json
{
  "healthy": true,
  "status": "warning",
  "timestamp": 1728000000
}
```

#### Plugin Inventory Response

A request for `mod_booking` and a not-installed `auth_saml2`, where `mod_booking`
is deployed as a Git submodule:

```json
{
  "plugins": [
    {
      "component": "mod_booking",
      "displayname": "Booking",
      "version": 2024093000,
      "status": "installed",
      "installation_source": "git"
    },
    {
      "component": "auth_saml2",
      "displayname": "",
      "version": 0,
      "status": "not_installed",
      "installation_source": "unknown"
    }
  ]
}
```

A plugin that is still recorded in the database but whose code has been removed
from disk is reported explicitly, with its recorded version preserved:

```json
{
  "plugins": [
    {
      "component": "block_oldwidget",
      "displayname": "Old widget",
      "version": 2023061500,
      "status": "missing_from_disk",
      "installation_source": "unknown"
    }
  ]
}
```

### Installation source determination

`installation_source` is derived only from **local filesystem evidence** resolved
through Moodle's plugin manager. No shell commands are run and no remote Git
requests are made while serving the request.

| Value | How it is determined |
|-------|----------------------|
| `git` | The plugin's own directory contains Git metadata — a `.git` **directory** (its own checkout) or a `.git` **file** (a Git submodule). The enclosing Moodle repository alone is **not** evidence, because that `.git` lives at the Moodle root, not inside the plugin directory. |
| `zip` | A trusted deployment manifest, `.moodle_plugin_source.json`, exists in the plugin directory and contains `{"source": "zip"}`. This file is written by a ZIP-based deployment process; it is the only signal treated as proof of a ZIP install. |
| `unknown` | None of the above could be verified. The **absence** of Git metadata is deliberately **not** treated as a ZIP installation. |

## Integration Examples

### Monitoring with Nagios/Icinga

```bash
#!/bin/bash
# Nagios plugin for Moodle health check

TOKEN="your_token_here"
URL="https://your-moodle.com/webservice/rest/server.php"

RESPONSE=$(curl -s "${URL}?wstoken=${TOKEN}&wsfunction=local_statuscheck_get_health_status&moodlewsrestformat=json")
STATUS=$(echo $RESPONSE | jq -r '.status')

case $STATUS in
  "ok")
    echo "OK - Moodle system is healthy"
    exit 0
    ;;
  "warning")
    echo "WARNING - Moodle has warnings"
    exit 1
    ;;
  "error"|"critical")
    echo "CRITICAL - Moodle has critical issues"
    exit 2
    ;;
  *)
    echo "UNKNOWN - Unable to determine status"
    exit 3
    ;;
esac
```

### Monitoring with Prometheus

```python
from prometheus_client import start_http_server, Gauge
import requests
import time

# Define metrics
moodle_health = Gauge('moodle_health_status', 'Moodle health status', ['instance'])
moodle_check_total = Gauge('moodle_checks_total', 'Total checks', ['instance'])
moodle_check_ok = Gauge('moodle_checks_ok', 'OK checks', ['instance'])
moodle_check_warning = Gauge('moodle_checks_warning', 'Warning checks', ['instance'])
moodle_check_error = Gauge('moodle_checks_error', 'Error checks', ['instance'])

def fetch_moodle_status():
    url = "https://your-moodle.com/webservice/rest/server.php"
    params = {
        'wstoken': 'your_token_here',
        'wsfunction': 'local_statuscheck_get_system_status',
        'moodlewsrestformat': 'json',
        'type': 'all'
    }
    
    response = requests.get(url, params=params)
    data = response.json()
    
    # Update metrics
    moodle_check_total.labels(instance='production').set(data['summary']['total'])
    moodle_check_ok.labels(instance='production').set(data['summary']['ok'])
    moodle_check_warning.labels(instance='production').set(data['summary']['warning'])
    moodle_check_error.labels(instance='production').set(data['summary']['error'])
    
    # Health status (1 for healthy, 0 for unhealthy)
    health_response = requests.get(url, params={**params, 'wsfunction': 'local_statuscheck_get_health_status'})
    health_data = health_response.json()
    moodle_health.labels(instance='production').set(1 if health_data['healthy'] else 0)

if __name__ == '__main__':
    start_http_server(8000)
    while True:
        fetch_moodle_status()
        time.sleep(300)  # Update every 5 minutes
```

### Dashboard with Grafana

Use the Prometheus exporter above with Grafana queries:

```promql
# Show health status
moodle_health_status{instance="production"}

# Show error count over time
rate(moodle_checks_error{instance="production"}[5m])

# Alert when health is down
moodle_health_status{instance="production"} < 1
```

## Security Considerations

1. **Token Protection**: Keep your web service tokens secure. Never commit them to version control.
2. **HTTPS Only**: Always use HTTPS in production to protect tokens in transit.
3. **IP Restrictions**: Consider restricting access by IP address in your firewall.
4. **Dedicated User**: Use a dedicated service account with minimal required permissions.
5. **Token Rotation**: Regularly rotate web service tokens.
6. **Audit Logging**: Monitor web service usage in Moodle logs.

## Troubleshooting

### "Access denied" Error

**Problem**: Web service returns access denied or authentication error.

**Solution**:
- Verify web services are enabled
- Check the token is valid and not expired
- Ensure the user has `report/status:view` capability
- Verify the service is enabled

### No Results Returned

**Problem**: Empty or minimal response from API.

**Solution**:
- Check Moodle error logs: `{moodle_root}/moodledata/`
- Enable debugging: **Site administration → Development → Debugging**
- Verify the check type parameter is valid
- Ensure checks are registered in Moodle

### Performance Issues

**Problem**: API responses are slow.

**Solution**:
- Some checks may be resource-intensive
- Consider implementing caching (future feature)
- Use the simple health check endpoint for frequent polling
- Optimize check frequency in monitoring systems

## Development

### File Structure

```
local/statuscheck/
├── classes/
│   └── external/
│       ├── get_system_status.php
│       ├── get_health_status.php
│       └── get_plugin_inventory.php
├── db/
│   └── services.php
├── lang/
│   └── en/
│       └── local_statuscheck.php
├── version.php
└── README.md
```

### Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Follow Moodle coding standards
4. Add tests for new functionality
5. Submit a pull request

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see <http://www.gnu.org/licenses/>.

## Support

- **Issues**: Report bugs and request features on [GitHub Issues](https://github.com/Wunderbyte-GmbH/moodle-local_statuscheck/issues)
- **Documentation**: Full documentation available in the [Wiki](https://github.com/Wunderbyte-GmbH/moodle-local_statuscheck/wiki)

## Credits

- **Author**: David Bogner
- **Copyright**: 2025 Wunderbyte GmbH
- **Maintainer**: David Bogner <david.bogner@gmail.com>

## Changelog

### Version 1.1 (2026-11-17)

- Added read-only `local_statuscheck_get_plugin_inventory` web service function
- Reports component, display name, installed version, install status and installation source (`git`/`zip`/`unknown`) per plugin
- Supports single-plugin, multi-plugin and full-inventory lookups
- Added to the existing **Status Check Service** (service identity preserved — pre-upgrade tokens keep working)
- No change to the existing system status and health check endpoints

### Version 1.0 (2025-10-03)

- Initial release
- Comprehensive system status check endpoint
- Simple health check endpoint
- Support for filtered check types
- JSON API responses
- Full Moodle 4.5 compatibility
