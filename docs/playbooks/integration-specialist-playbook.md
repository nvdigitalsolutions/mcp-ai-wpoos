# Integration Specialist Professional Playbook

## Overview

**Profession:** Integration Specialist  
**Primary Toolkit:** Integration & External Services  
**Recommended Pattern:** Skill Router  
**Risk Tolerance:** Standard  
**Team Size:** 3-4 agents  

## Primary Tools (9 Tools)

### MCP & API Integration
- `probe_remote_mcp` - Test MCP servers
- `probe_chat` - Test chat endpoints
- `query_remote_site` - Query external sites

### Third-Party Services
- `get_jetengine_items` - JetEngine data
- `invoke_jetengine_route` - JetEngine routes
- `list_jetengine_routes` - Route discovery
- `search_drive` - Google Drive integration
- `search_gmail` - Gmail integration

### Workflow Integration
- `query_mesh_intelligent` - Mesh queries

## Recommended Pattern: Skill Router

Router directs integration requests to appropriate specialized agents based on the service type.

**Router Logic:**
```
Integration Request
    ↓
Skill Router
    ├→ MCP Specialist (for MCP servers)
    ├→ API Specialist (for REST APIs)
    ├→ JetEngine Specialist (for JetEngine)
    └→ Google Services Specialist (for Drive/Gmail)
```

## Common Use Cases

1. **API Integration Setup** - Connect to external services
2. **Data Synchronization** - Sync data across platforms
3. **Webhook Configuration** - Set up event notifications
4. **Third-Party Authentication** - OAuth flows

## Best Practices

1. Test all integrations thoroughly
2. Implement error handling
3. Monitor API rate limits
4. Document integration endpoints
5. Version control for configurations

---

**Version:** 1.0 | **Date:** January 30, 2026
