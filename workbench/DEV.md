# Development

This package uses [Orchestra Testbench](https://packages.tools/testbench/) for development.

```bash
# Install dependencies
composer install

# Build workbench
composer build

# Run the generator against the workbench app
composer generate

# Serve the workbench app locally
composer serve
```

> [!NOTE]
> `composer generate` requires a SQLite database with migrations applied. The script handles this automatically.
