# Branding and appearance

**Admin Panel → Settings → Branding** controls how the agent panel and the
client portal look. Everything is applied through CSS custom properties, so
the theme stays consistent across every page and custom CSS stays small.

| Setting | Options | Notes |
|---------|---------|-------|
| Accent colour | any colour | used for primary buttons, active navigation, links, chart series and focus rings; text on the accent is chosen automatically for contrast |
| Theme | light, dark, auto | *auto* follows the operating system; agents can override with the sun / moon toggle in their menu (stored in the browser) |
| Density | comfortable, compact | row heights and spacing in lists and forms; agents can switch per list |
| Navigation style | top, side | horizontal tabs (default) or a left rail on wide screens |
| Display name | text | shown next to the logo and in the browser title (default: the help desk name) |
| Tagline | text | small text under the name |
| Favicon | image upload | PNG / ICO / SVG, served from `logo.php?favicon` for both panels |
| Hide "powered by" | on / off | removes the osTicket badge from the login page and portal footer |
| Custom CSS | text | appended after the theme; `<style>` tags and `@import` are stripped |

The logo itself is still uploaded under **Settings → Company**, as in stock
osTicket; it is placed on a light plate in dark mode so coloured logos stay
legible.

## Design system

* `scp/css/modern.css` holds the tokens (`--ost-accent`, `--ost-surface`,
  `--ost-text`, `--ost-border`, radius, shadows, spacing) and the component
  styles: app bar, navigation, cards, tables, forms, dialogs, pills, KPI
  tiles, charts, thread entries.
* Icons are the Font Awesome glyph set already shipped with osTicket,
  rendered in the current text colour. No coloured icon images are used in
  the agent panel; state is shown with neutral pills, borders and type.
* Dark mode is keyed on `html[data-theme="dark"]`, set before paint by a
  small inline script so there is no flash, and on the body class when the
  theme is forced from settings.
* `scp/js/modern.js` handles the theme toggle, density toggle, user menu
  and keyboard shortcuts; `scp/js/charts.js` draws the SVG charts with the
  accent colour plus neutral greys.

## Custom CSS examples

```css
/* rounder cards and buttons */
:root { --ost-radius: 12px; }

/* a different font */
:root { --ost-font: "IBM Plex Sans", system-ui, sans-serif; }

/* tighter tables everywhere */
table.list td, table.list th { padding-top: 4px; padding-bottom: 4px; }

/* hide the SLA side card for departments that do not use SLAs */
.ticket-side-cards .card.sla { display: none; }
```

Dark-mode overrides can be scoped with `html[data-theme="dark"] … { }`.
