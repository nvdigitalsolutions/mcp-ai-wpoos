#!/usr/bin/env python3
"""
Automated link fixer for WP oOS documentation
"""

# Map of common file relocations
FILE_MOVES = {
    'MASTER_CONSOLIDATION_2025.md': 'implementation-history/2025/summaries/MASTER_CONSOLIDATION_2025.md',
    'ARCHITECTURE.md': 'architecture/ARCHITECTURE.md',
    'GEMINI_GEOSPATIAL.md': 'features/ai-providers/gemini/GEMINI_GEOSPATIAL.md',
    'HUGGINGFACE_DATASETS_QUICK_START.md': 'features/ai-providers/huggingface/HUGGINGFACE_DATASETS_QUICK_START.md',
    'HUGGINGFACE_TOP_DATASETS.md': 'features/ai-providers/huggingface/HUGGINGFACE_TOP_DATASETS.md',
    'HUGGINGFACE_SETUP.md': 'features/ai-providers/huggingface/HUGGINGFACE_SETUP.md',
    'SYMFONY_PHASE2B_PROCESS_INTEGRATION.md': 'implementation-history/2025/implementations/symfony-phases/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md',
}

print("File relocation map:")
for old, new in FILE_MOVES.items():
    print(f"  {old} -> docs/{new}")
