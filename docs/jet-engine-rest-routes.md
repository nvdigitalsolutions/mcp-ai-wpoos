# JetEngine REST API Routes

JetEngine registers its REST controllers under the `jet-engine/v2` namespace. The table below summarises each bundled endpoint, the HTTP methods it supports, and the callbacks that power the route definitions.

| Route (relative to `/wp-json/jet-engine/v2`) | Methods | Callback | Permission callback | Summary |
| --- | --- | --- | --- | --- |
| `/search-posts/` | `GET` | `Jet_Engine_Rest_Search_Posts::callback` | `Jet_Engine_Rest_Search_Posts::permission_callback` | Search published posts or taxonomy terms registered via JetEngine using query arguments such as `query`, `ids`, `post_type`, `tax`, `search_terms`, and `query_context`. |
| `/add-item/` | `POST` | `Jet_Engine_Rest_Add_Item::callback` | `Jet_Engine_Rest_Add_Item::permission_callback` | Create a new JetEngine item by handing the payload to filters on `jet-engine/rest-api/add-item/{instance}`. Requests must provide an `instance` identifier. |
| `/edit-item/(?P<id>[a-z\-\d]+)/` | `POST` | `Jet_Engine_Rest_Edit_Item::callback` | `Jet_Engine_Rest_Edit_Item::permission_callback` | Update an existing item routed through `jet-engine/rest-api/edit-item/{instance}`. Requires both the item `id` in the URL and an `instance` parameter in the body. |
| `/delete-item/(?P<id>[a-z\-_\d]+)/` | `DELETE` | `Jet_Engine_Rest_Delete_Item::callback` | `Jet_Engine_Rest_Delete_Item::permission_callback` | Delete an item via `jet-engine/rest-api/delete-item/{instance}` filters. Requires an item `id` in the URL and an `instance` parameter. |
| `/get-item/(?P<id>[a-z\-\d]+)/` | `GET` | `Jet_Engine_Rest_Get_Item::callback` | `Jet_Engine_Rest_Get_Item::permission_callback` | Fetch a single JetEngine item through `jet-engine/rest-api/get-item/{instance}`. Requires the item `id` and an `instance` parameter. |
| `/get-items/` | `GET` | `Jet_Engine_Rest_Get_Items::callback` | `Jet_Engine_Rest_Get_Items::permission_callback` | Retrieve multiple JetEngine items via `jet-engine/rest-api/get-items/{instance}` with automatic unserialisation of stored arguments. Requires an `instance` parameter. |

All built-in controllers enforce the `manage_options` capability inside their permission callbacks.

## Usage notes

* Each data-modifying route (`add-item`, `edit-item`, `delete-item`) delegates the heavy lifting to instance-specific filters. Make sure the relevant module attaches a handler to the matching `jet-engine/rest-api/{operation}/{instance}` hook.
* The `instance` argument acts as a routing key between JetEngine and your integration. Any request missing this value fails with a descriptive error message.
* Cross-reference the [Crocoblock REST API overview](https://crocoblock.com/knowledge-base/features/rest-api-overview/) for broader context on managing Custom Content Type data with these endpoints.

## AI chat transcript storage

WP oOS seeds an `ai_chat_transcripts` Custom Content Type (CCT) under JetEngine for recording individual chat turns and debugging metadata. The helper `WP_MCP_AI_JetEngine_CCT::get_slug()` returns the slug while `WP_MCP_AI_JetEngine_CCT::get_item_handler()` exposes the JetEngine item handler (equivalent to calling `jet_engine()->cct->item_handler` after the CCT registers) for programmatic inserts or lookups.
