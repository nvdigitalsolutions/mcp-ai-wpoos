# Disaster Response Coordinator Professional Playbook

## Overview

**Profession:** Disaster Response Coordinator  
**Primary Toolkit:** Geospatial & Location  
**Recommended Pattern:** Event-Driven Response  
**Risk Tolerance:** Standard (time-sensitive operations)  
**Team Size:** 3-4 agents  

## Primary Tools (7 Tools)

### Real-Time Monitoring
- `get_nhc_active_storms` - Hurricane tracking
- `get_gdacs_events` - Global disaster alerts
- `reliefweb_reports` - Humanitarian reports

### Location Services
- `geocode_address` - Address geocoding
- `search_places` - Location search
- `gemini_geospatial_query` - Geospatial analysis

### Weather Data
- `get_open_meteo_forecast` - Weather forecasts

## Recommended Pattern: Event-Driven Response

Agents monitor for disaster events and respond immediately when triggered.

**Event Flow:**
```
Monitor Disasters → Detect Event → Alert Team → Coordinate Response
```

**Agent Structure:**
```
Event Monitor (always watching)
    ↓ (event detected)
Response Coordinators (activated on demand)
```

## Common Use Cases

1. **Hurricane Tracking** - Monitor active storms
2. **Emergency Alert System** - Detect and broadcast disasters
3. **Resource Coordination** - Map disaster resources
4. **Situation Reports** - Generate real-time status updates

## Best Practices

1. 24/7 monitoring capability
2. Rapid response protocols
3. Multi-source data verification
4. Clear communication channels
5. Regular system testing

## Success Metrics

- **Detection Time:** < 5 minutes after event
- **Alert Distribution:** < 10 minutes
- **Situation Report:** < 30 minutes
- **Data Accuracy:** > 95%

---

**Version:** 1.0 | **Date:** January 30, 2026
