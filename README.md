# Hydrate

A PHP REST API for managing versioned HTML templates and generating PDFs from them. Post your data, hydrate the placeholders, get a download link — simple as that.

Built with PHP 8.2 + Apache, [dompdf](https://github.com/dompdf/dompdf), and zero framework dependencies. Includes a [Model Context Protocol (MCP)](https://modelcontextprotocol.io) endpoint for AI agent integration.

---

## Features

- **HTML templates** with `{{placeholder}}` syntax
- **Versioning** — every save creates a new immutable version
- **PDF generation** — renders any template to PDF, returns a 30-minute temp download link
- **MCP endpoint** — connect AI agents (Claude, n8n, etc.) directly to your templates
- **Header authentication** — all endpoints protected by a configurable `X-Auth` secret
- **Swagger UI** at `/swagger` with the auth key pre-filled

---

## Quick Start

```bash
# Clone and run
git clone <repo>
cd hydrate
docker compose up --build
```

The API is now available at `http://localhost`.

**Override the auth secret:**
```bash
X_AUTH=my-secret docker compose up --build
```

---

## Authentication

All endpoints (except `/swagger`, `/openapi.json`, and `/renders/{token}`) require the header:

```
X-Auth: very-strong-secret
```

The default secret is `very-strong-secret`. Override it at runtime via the `X_AUTH` environment variable.

---

## API

Full interactive documentation is available at **`http://localhost/swagger`**.

### Templates

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/templates` | List all templates |
| `POST` | `/templates` | Create a template |
| `GET` | `/templates/{name}` | Get latest version (`?version=N` for specific) |
| `PUT` | `/templates/{name}` | Save a new version |
| `DELETE` | `/templates/{name}` | Delete template and all versions |
| `GET` | `/templates/{name}/versions` | List all versions |
| `DELETE` | `/templates/{name}/versions/{version}` | Delete a specific version |

### Render

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/templates/{name}/render` | Hydrate and generate PDF or HTML |
| `GET` | `/renders/{token}` | Download a generated PDF (public, 30 min TTL) |

### Other

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/mcp` | MCP endpoint (JSON-RPC 2.0) |
| `GET` | `/swagger` | Swagger UI |
| `GET` | `/openapi.json` | OpenAPI 3.0 spec |

---

## Usage Examples

### Create a template

```bash
curl -X POST http://localhost/templates \
  -H "X-Auth: very-strong-secret" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "invoice",
    "description": "Basic invoice",
    "html": "<h1>Invoice for {{client}}</h1><p>Amount due: {{amount}}</p><p>Due: {{due_date}}</p>"
  }'
```

### Generate a PDF

```bash
curl -X POST http://localhost/templates/invoice/render \
  -H "X-Auth: very-strong-secret" \
  -H "Content-Type: application/json" \
  -d '{
    "data": {
      "client": "Acme Corp",
      "amount": "$1,200.00",
      "due_date": "2026-04-30"
    },
    "format": "pdf",
    "paper": "A4",
    "orientation": "portrait"
  }'
```

Response:
```json
{
  "url": "http://localhost/renders/a3f9c1...",
  "expires_at": "2026-03-23T14:30:00+00:00"
}
```

Download the PDF from the returned URL — no auth required, valid for 30 minutes.

### Update a template (new version)

```bash
curl -X PUT http://localhost/templates/invoice \
  -H "X-Auth: very-strong-secret" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Added company logo",
    "html": "<img src=\"logo.png\"/><h1>Invoice for {{client}}</h1><p>Amount: {{amount}}</p>"
  }'
```

### Render a specific version

Add `"version": 1` to the render request body to target any historical version.

---

## MCP Integration

Connect any MCP-compatible client (Claude Desktop, n8n, etc.) to `http://localhost/mcp`.

Available tools:

| Tool | Description |
|------|-------------|
| `list_templates` | List all templates |
| `get_template` | Get template HTML and placeholders |
| `create_template` | Create a new template |
| `update_template` | Save a new version |
| `delete_template` | Delete a template |
| `list_versions` | List all versions |
| `delete_version` | Delete a specific version |
| `render_template` | Hydrate and render — returns a temp PDF URL |

---

## Template Syntax

Use `{{key}}` placeholders anywhere in your HTML:

```html
<!DOCTYPE html>
<html>
<body>
  <h1>Hello, {{name}}!</h1>
  <p>Your order <strong>{{order_id}}</strong> ships on {{ship_date}}.</p>
</body>
</html>
```

All values are HTML-escaped on hydration. Unresolved placeholders are stripped from output.

---

## Project Structure

```
hydrate/
├── public/
│   ├── index.php          # Entry point + routes
│   ├── openapi.json       # OpenAPI 3.0 spec
│   └── .htaccess
├── src/
│   ├── Http/
│   │   ├── Auth.php
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── TemplateController.php
│   │   ├── RenderController.php
│   │   ├── SwaggerController.php
│   │   └── McpController.php
│   ├── Template/
│   │   ├── Template.php
│   │   └── TemplateRepository.php
│   ├── Hydrator/
│   │   └── Hydrator.php
│   └── Pdf/
│       ├── PdfGenerator.php
│       └── TempStore.php
├── storage/
│   ├── templates/         # Versioned HTML + metadata (gitignored)
│   └── renders/           # Temp PDFs (gitignored)
├── Dockerfile
├── docker-compose.yml
└── composer.json
```

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `X_AUTH` | `very-strong-secret` | Auth secret for the `X-Auth` header |

---

## Requirements

- Docker + Docker Compose

Or to run locally:

- PHP 8.1+
- Composer
- Apache with `mod_rewrite`
- PHP extensions: `gd`, `dom`, `xml`, `zip`, `mbstring`
