# syntax=docker/dockerfile:1.6
#
# NV oOS WordPress plugin — Dev Container image
# ---------------------------------------------
# Built on top of Microsoft's official devcontainers PHP image, which already
# ships PHP 8.2, Composer, Git, sudo, and a non-root `vscode` user. We layer on
# the few OS-level tools that the repo's own scripts expect:
#
#   * subversion        — bin/install-wp-tests.sh downloads the WP PHPUnit
#                         test framework via `svn export` from develop.svn.wordpress.org
#   * default-mysql-client — `mysqladmin` is used by install-wp-tests.sh to
#                            create the test database
#   * jq                — handy for inspecting JSON output from WP-CLI / REST
#   * WP-CLI            — pinned phar binary, also installed at runtime by
#                         post-create.sh, but baking it into the image avoids
#                         a network round-trip on every container rebuild.
#
# Node.js is added through the standard devcontainers feature declared in
# devcontainer.json rather than apt, so the version is easy to bump.

FROM mcr.microsoft.com/devcontainers/php:8.2

# Avoid interactive prompts during apt installs.
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        subversion \
        default-mysql-client \
        jq \
    && rm -rf /var/lib/apt/lists/*

# Install WP-CLI globally and verify it runs. Pinned to the latest stable phar
# from the official wp-cli/builds GitHub Pages mirror.
RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
        -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp \
    && wp --info --allow-root
