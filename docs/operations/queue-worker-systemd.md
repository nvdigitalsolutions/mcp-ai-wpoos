# Queue Worker — Systemd Unit (Self-Hosted)

The queue worker ([`bin/queue-worker.php`](../../bin/queue-worker.php)) can run
as a long-lived daemon on self-hosted servers. This unit file runs the DB-queue
worker under systemd with automatic restarts and graceful shutdown — the worker
handles SIGTERM by finishing the current job and acknowledging it before
exiting.

```ini
# /etc/systemd/system/nvoos-queue-worker.service
[Unit]
Description=NV oOS Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/html/wp-content/plugins/mcp-ai-wpoos
ExecStart=/usr/bin/php bin/queue-worker.php --daemon --memory-limit=256M
Restart=on-failure
RestartSec=10
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

Notes:

- RabbitMQ variant: add `--rabbitmq` to `ExecStart`.
- Adjust `User`/`Group`/`WorkingDirectory` to your web-server user and plugin path.
- On Cloudways and other managed hosts, prefer cron (`--timeout=55`) or the
  platform's process manager instead — see the deployment notes in the script
  header of `bin/queue-worker.php`.

**Related:**

- [`docs/project/proposals/011-queue-worker-implementation-plan.md`](../project/proposals/011-queue-worker-implementation-plan.md)
- [`docs/project/proposals/009-rabbitmq-integration-proposal.md`](../project/proposals/009-rabbitmq-integration-proposal.md)
- [`bin/README.md`](../../bin/README.md) — Queue Worker section
