<?php

use App\Mcp\Servers\NvoosServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', NvoosServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp']);
