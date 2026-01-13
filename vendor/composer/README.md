# Composer Autoloader

This directory contains Composer's autoloader files. Some files are auto-generated and not tracked in git.

## After Cloning

After cloning this repository, run the following command to generate the required autoloader files:

```bash
composer install --no-dev
```

This will generate:
- `installed.php`
- `installed.json`
- `autoload_classmap.php`
- `autoload_files.php`
- `autoload_psr4.php`
- `autoload_static.php`
- `autoload_namespaces.php`
- `platform_check.php`

These files are required for the plugin to function properly but are not tracked in version control because they are auto-generated and change with every composer operation.
