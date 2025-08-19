# OLLAMA PROMPT COMPARISON: Before vs After Performance Optimization

## BEFORE (Research-Based Complex Prompt)
**Character Count: ~1,200+ characters**
**Processing: Multiple reviews at once**

```
ROLE: You are a marketplace integrity analyst. Use forensic-linguistic cues and review-metadata heuristics to assess if a review is likely fake or genuine.

OBJECTIVE: Return a fake_likelihood score (0-100; 0=clearly genuine, 100=clearly fake) using scientific methodology.

METHOD: Evaluate using multiple independent signals. Never rely on one signal alone.

SCORING RULE: Initialize S=50. Apply bounded adjustments:
• generic_promotional_tone: +0..+20
• rating_text_mismatch: +0..+15
• metadata_risk: -10..+10 (unverified/+; verified/-)
• inconsistencies_contradictions: +0..+15
• specificity_detail: -0..-20
• balanced_caveats: -0..-10
• usage_time_markers: -0..-10

LABELS: genuine ≤39, uncertain 40-59, fake ≥60. Require ≥2 independent fake signals to label fake.

BIAS GUARDRAILS: Do not penalize non-native writing, brevity, or sentiment extremes alone.

NEGATIVE REVIEWS: Detailed complaints with specific issues are AUTHENTIC, not fake.

GENUINE PATTERNS: Specific problems, balanced criticism, product knowledge, realistic expectations.

Return JSON: [{"id":"X","score":Y,"label":"genuine|uncertain|fake","confidence":Z}]

Key: V=Verified Purchase, U=Unverified, Vine=Amazon Vine

ID:review1 5/5 V
R: [400 characters of review text]

ID:review2 3/5 U  
R: [400 characters of review text]
...
```

## AFTER (Ultra-Minimal Emergency Prompt)
**Character Count: ~80-120 characters**
**Processing: 1 review at a time**

```
Score 0-100: review1:This product is amazing and works perfectly. JSON: {"id":"review1","score":X}
```

## PERFORMANCE COMPARISON

| Metric | BEFORE | AFTER | Improvement |
|--------|--------|--------|-------------|
| **Prompt Length** | ~1,200 chars | ~100 chars | **92% reduction** |
| **Reviews per Request** | 37 reviews | 1 review | **97% reduction** |
| **Review Text Length** | 400 chars | 50 chars | **87.5% reduction** |
| **Estimated Processing Time** | 703 seconds (11+ min) | ~370 seconds (6 min) | **47% faster** |
| **CPU Load** | 1180% sustained | Distributed | **Manageable** |

## TRADE-OFFS

### LOST CAPABILITIES:
- ❌ Scientific methodology guidance
- ❌ Multi-signal analysis instructions  
- ❌ Bias guardrails
- ❌ Detailed scoring rules
- ❌ Context about genuine vs fake patterns
- ❌ Confidence scoring
- ❌ Label classification
- ❌ Batch processing efficiency

### RETAINED CAPABILITIES:
- ✅ Basic 0-100 scoring
- ✅ JSON response format
- ✅ Review ID tracking
- ✅ Core review text analysis

## RECOMMENDATION

**The current ultra-minimal approach may be TOO aggressive.** We've lost most of the research-based intelligence that made the analysis accurate.

**SUGGESTED MIDDLE GROUND:**
```
Fake review score 0-100 (0=real, 100=fake):
• Generic praise = higher score
• Specific details = lower score  
JSON: [{"id":"X","score":Y}]

ID:review1 5/5 V: [150 chars of text]
```

This would:
- Keep essential guidance (50% of original intelligence)
- Reduce prompt by 80% vs original
- Process 2-3 reviews per chunk
- Target ~90 seconds total processing time
