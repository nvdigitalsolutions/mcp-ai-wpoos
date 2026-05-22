@echo off
REM ============================================================
REM  WordPress Studio Test Runner (Windows .bat)
REM
REM  Runs the NV oOS test suite against a WordPress Studio site.
REM
REM  Usage:
REM    bin\test-studio.bat mysite          Run all tests
REM    bin\test-studio.bat mysite --filter "test_logger"
REM
REM  Prerequisites:
REM    - WordPress Studio installed and site "mysite" created
REM    - composer install (run once)
REM    - SQLite Database Integration plugin in the Studio site
REM      (copy from tests/fixtures/sqlite-database-integration/)
REM ============================================================
setlocal enabledelayedexpansion

if "%~1"=="" (
    echo Usage: bin\test-studio.bat ^<site-slug^> [phpunit-args...]
    echo.
    echo Example: bin\test-studio.bat mysite --filter "test_logger"
    exit /b 1
)

set WP_STUDIO_SITE_SLUG=%~1
echo Running tests against WordPress Studio site: %WP_STUDIO_SITE_SLUG%
echo.

REM Pass remaining args to PHPUnit
shift
vendor\bin\phpunit %*
