# Hotel Hub Module - Housekeeping - Twin Optimiser

A mobile-optimized twin room optimization module for the Hotel Hub App. This module helps housekeeping staff identify twin room opportunities by displaying a visual booking grid with highlighted twin bookings.

## Description

The Twin Optimiser module displays a booking grid that shows all room bookings across a 14-day period. Twin room bookings are automatically detected and highlighted in yellow to help identify optimization opportunities.

## Features

- **Visual Booking Grid**: Clear grid showing all rooms and bookings
- **Twin Detection**: Automatically identifies and highlights twin bookings
- **Mobile Optimized**: Designed for portrait orientation on mobile devices
- **Date Selection**: Choose custom start date for booking view
- **Per-Location Configuration**: Configure custom field detection per workforce location

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- Hotel Hub App plugin (active)
- Workforce Authentication plugin (active)
- NewBook integration configured

## Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/hotelhubmodule-housekeeping-twinoptomiser/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure settings at **Hotel Hub > Twin Optimiser**
5. Assign permissions via Workforce Authentication

## Configuration

### Settings

Navigate to **Hotel Hub > Twin Optimiser** to configure the module:

1. **Custom Field Name**: Specify the NewBook custom field name used to identify bed types (default: "Bed Type")
2. **Per-Location Settings**: Configure custom field names individually for each workforce location

### Twin Detection

Bookings are automatically marked as twins when the configured custom field contains:
- "twin" (case-insensitive)
- "2 x single" or "2x single" (case-insensitive)

### Permissions

The module uses a single permission: `hhtm_access_twin_optimiser`

Assign this permission to staff members via the Workforce Authentication plugin to grant access to the Twin Optimiser module.

## Usage

1. Select a hotel from the Hotel Hub selector
2. Navigate to the Twin Optimiser module
3. Choose a start date (defaults to today)
4. View the booking grid:
   - **Grey cells**: Vacant rooms
   - **White cells**: Regular bookings
   - **Yellow cells**: Twin bookings (optimization opportunities)

## Technical Details

### Plugin Structure

```
hotelhubmodule-housekeeping-twinoptomiser/
├── assets/
│   ├── css/
│   │   └── twin-optimiser.css
│   └── js/
│       └── twin-optimiser.js
├── includes/
│   ├── class-hhtm-frontend.php
│   └── class-hhtm-settings.php
├── hotelhubmodule-housekeeping-twinoptomiser.php
└── README.md
```

### Module Registration

The module registers with Hotel Hub using the `hha_register_modules` action hook:

```php
add_action('hha_register_modules', array($this, 'register_module'));
```

### API Integration

The module uses Hotel Hub's NewBook API client (`HHA_NewBook_API`) to fetch booking data.

## Development

### Prefix Convention

All functions, classes, and database operations use the `hhtm` prefix (Hotel Hub Twin Module).

### Naming Convention

The plugin follows the Hotel Hub module naming convention:
- Format: `hotelhubmodule-{category}-{modulename}`
- Category: `housekeeping`
- Module name: `twinoptomiser`

## Changelog

### 1.0.0
- Initial release
- Twin booking detection and visualization
- Mobile-optimized booking grid
- Per-location custom field configuration
- Date range selection

## Support

For issues, feature requests, or contributions, please visit the GitHub repository.

## License

GPL v2 or later

## Credits

Developed for the Hotel Hub App ecosystem.
