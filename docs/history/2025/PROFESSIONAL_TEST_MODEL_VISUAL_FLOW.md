# Professional Test Model - Visual Flow Diagram

## Before (OLD) vs After (NEW)

### OLD BEHAVIOR - Required Default Assistant

```
┌─────────────────────────────────────────────────────────────┐
│                    OLD: profession_123                       │
└─────────────────────────────────────────────────────────────┘
                              ↓
                    resolve_assistant_id()
                              ↓
        ┌─────────────────────┴─────────────────────┐
        │                                           │
   Has associated?                            No associated?
        │                                           │
        ↓                                           ↓
   Return 456                              Return DEFAULT (789)
   (associated)                            ⚠️ REQUIRES default set!
        │                                           │
        └──────────────────┬────────────────────────┘
                           ↓
              get_assistant_configuration()
                           ↓
              load_profession_configuration()
                           ↓
              ⚠️ REPLACES assistant data
              assistant_config['system_prompt'] = profession_prompt
              assistant_config['tools'] = profession_tools
                           ↓
              Result: Profession overwrites assistant
```

### NEW BEHAVIOR - Standalone or Append

```
┌─────────────────────────────────────────────────────────────┐
│                    NEW: profession_123                       │
└─────────────────────────────────────────────────────────────┘
                              ↓
                    resolve_assistant_id()
                              ↓
        ┌─────────────────────┴─────────────────────┐
        │                                           │
   Has associated?                            No associated?
        │                                           │
        ↓                                           ↓
   Return 456                                  Return 0
   (associated)                           ✅ No default needed!
        │                                           │
        └──────────────────┬────────────────────────┘
                           ↓
              get_assistant_configuration()
                           ↓
                      ┌────┴────┐
                      │         │
              ID = 456      ID = 0
              Returns       Returns
              config        empty {}
                      │         │
                      └────┬────┘
                           ↓
              load_profession_configuration()
                           ↓
        ┌──────────────────┴──────────────────┐
        │                                     │
   Has assistant base?               No assistant base?
   (system_prompt set)                 (empty config)
        │                                     │
        ↓                                     ↓
   APPEND MODE                          STANDALONE MODE
   assistant_config                     assistant_config
   ['system_prompt'] +=                 ['system_prompt'] =
   "\n\nProfessional...\n"              profession_prompt
   profession_prompt
                                        
   Tools MERGED:                        Tools from profession:
   [...assistant_tools,                 [...profession_tools]
    ...profession_tools]
        │                                     │
        └──────────────────┬──────────────────┘
                           ↓
              Result: Intelligent merge
```

## Side-by-Side Comparison

### Scenario: Tax Advisor Profession

```
┌─────────────────────────────────────────────────────────────────┐
│                        OLD BEHAVIOR                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  profession_tax_advisor (no associated assistant)                │
│         ↓                                                        │
│  resolve_assistant_id() returns default_assistant (789)          │
│         ↓                                                        │
│  get_assistant_configuration(789)                                │
│    → system_prompt: "You are a helpful AI assistant..."         │
│    → tools: [search, general_help]                              │
│         ↓                                                        │
│  load_profession_configuration()                                 │
│    → ⚠️ REPLACES system_prompt with profession                  │
│    → system_prompt: "You are a tax advisor..."                  │
│    → ⚠️ REPLACES tools with profession tools                    │
│    → tools: [calculator, tax_forms]                             │
│         ↓                                                        │
│  ❌ Assistant knowledge lost                                     │
│  ❌ Assistant tools lost                                         │
│  ⚠️ Requires default assistant                                  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                        NEW BEHAVIOR                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  profession_tax_advisor (no associated assistant)                │
│         ↓                                                        │
│  resolve_assistant_id() returns 0                                │
│         ↓                                                        │
│  get_assistant_configuration(0)                                  │
│    → returns empty {}                                            │
│         ↓                                                        │
│  load_profession_configuration()                                 │
│    → ✅ Uses profession as PRIMARY                              │
│    → system_prompt: "You are a tax advisor..."                  │
│    → tools: [calculator, tax_forms]                             │
│         ↓                                                        │
│  ✅ Profession works standalone                                 │
│  ✅ No default assistant needed                                 │
│                                                                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  profession_tax_advisor (WITH associated assistant 456)          │
│         ↓                                                        │
│  resolve_assistant_id() returns 456                              │
│         ↓                                                        │
│  get_assistant_configuration(456)                                │
│    → system_prompt: "You are a helpful AI assistant..."         │
│    → tools: [search, general_help]                              │
│         ↓                                                        │
│  load_profession_configuration()                                 │
│    → ✅ APPENDS profession to assistant                         │
│    → system_prompt: "You are a helpful AI assistant...          │
│                      \n\nProfessional Role & Expertise:\n       │
│                      You are a tax advisor..."                   │
│    → ✅ MERGES tools                                            │
│    → tools: [search, general_help, calculator, tax_forms]       │
│         ↓                                                        │
│  ✅ Assistant knowledge preserved                               │
│  ✅ Profession expertise added                                  │
│  ✅ All tools available                                         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Data Flow Diagram

```
User Action: Click "Test" on Tax Advisor profession
                          ↓
Frontend JS: assistantId = "profession_123"
                          ↓
REST API: POST /wp-json/mcp-ai/v1/chat-client
          body: { assistant_id: "profession_123", messages: [...] }
                          ↓
Backend: handle_chat_request()
                          ↓
┌────────────────────────────────────────────────────────────┐
│ Step 1: Identify Profession                                │
├────────────────────────────────────────────────────────────┤
│  extract_profession_id("profession_123") → 123             │
│  ✓ Valid profession post exists                            │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│ Step 2: Resolve Assistant                                  │
├────────────────────────────────────────────────────────────┤
│  resolve_assistant_id("profession_123")                    │
│    → Check profession meta: _associated_assistant          │
│      ├─ If set & valid → return assistant_id (e.g., 456)  │
│      └─ If not set     → return 0                          │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│ Step 3: Load Assistant Config                              │
├────────────────────────────────────────────────────────────┤
│  get_assistant_configuration(assistant_id)                 │
│    → If assistant_id > 0: Load assistant meta              │
│    → If assistant_id = 0: Return empty array               │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│ Step 4: Merge Profession Config                            │
├────────────────────────────────────────────────────────────┤
│  load_profession_configuration(profession_id, config)      │
│                                                             │
│  Load profession data:                                     │
│    • role_description                                      │
│    • knowledge_base                                        │
│    • playbook (global + category + specific)              │
│    • default_tools                                         │
│    • memory_files                                          │
│    • provider/model/temperature                            │
│                                                             │
│  IF assistant config has system_prompt:                    │
│    → APPEND profession knowledge                           │
│    → config['system_prompt'] += "\n\nProfessional..."     │
│  ELSE:                                                     │
│    → USE profession as primary                             │
│    → config['system_prompt'] = profession_prompt          │
│                                                             │
│  Tools: MERGE both lists                                   │
│  Memory files: MERGE both lists                            │
│  Provider/Model/Temp: Use profession values                │
│                                                             │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│ Step 5: Execute Chat with Merged Config                    │
├────────────────────────────────────────────────────────────┤
│  • Send to AI provider with merged system_prompt           │
│  • Tools available from merged list                        │
│  • Stream response back to frontend                        │
└────────────────────────────────────────────────────────────┘
                          ↓
                    Chat Response
```

## Legend

```
✅ = Good / Success / New feature
❌ = Bad / Problem / Removed issue
⚠️ = Warning / Issue / Old problem
→  = Flow direction
├─ = Branch in logic
└─ = End of branch
```

## Key Takeaways

1. **No Default Required**: Profession testing works without default assistant
2. **Intelligent Merge**: Appends when assistant exists, standalone when not
3. **Tools Combined**: Both assistant and profession tools available
4. **Knowledge Layered**: Assistant base + profession expertise
5. **Backward Compatible**: Existing workflows still work (improved)
