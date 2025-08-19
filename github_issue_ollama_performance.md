# Ollama Performance Optimization - Further Improvements Needed

## Issue Summary
Despite switching to a lighter model (llama3.2:3b) and optimizing the research-based prompt, Ollama analysis is still taking 117+ seconds for 41 reviews, which is too slow for acceptable user experience.

## Current Performance
- **Analysis Time**: 117 seconds (1 minute 57 seconds) 
- **Reviews Processed**: 41 reviews
- **Model**: llama3.2:3b (2GB)
- **Prompt Size**: 783 characters (optimized research-based)
- **Review Text**: 300 characters per review

## Target Performance
- **Goal**: Under 30-45 seconds total analysis time
- **Acceptable UX**: Maximum 60 seconds

## Proposed Optimizations

### 1. Limit Review Processing
- **Current**: Processing all reviews (41 in test case)
- **Proposed**: Limit to 15-20 reviews maximum
- **Rationale**: Diminishing returns after 15-20 reviews for fake detection

### 2. Reduce Review Text Length
- **Current**: 300 characters per review
- **Proposed**: 150 characters per review
- **Impact**: 50% reduction in text processing load

### 3. Further Simplify Prompt
- **Current**: Research-based methodology (783 chars)
- **Proposed**: Ultra-minimal scientific approach (~400 chars)
- **Keep**: Core scientific principles but remove verbose explanations

### 4. Alternative Model Options
- **Current**: llama3.2:3b (2GB)
- **Investigate**: Even lighter models if available
- **Consider**: Custom fine-tuned smaller model for fake detection

## Implementation Priority
1. **High**: Limit to 20 reviews max (immediate 50% reduction)
2. **High**: Reduce review text to 150 chars (50% text reduction)
3. **Medium**: Simplify prompt further (maintain quality)
4. **Low**: Investigate alternative models

## Success Criteria
- [ ] Analysis completes in under 45 seconds
- [ ] Maintains reasonable fake detection accuracy
- [ ] No degradation in user experience
- [ ] System remains stable under load

## Technical Notes
- Changes should be incremental and tested
- Monitor accuracy vs speed trade-offs
- Consider A/B testing for quality validation
- Ensure changes work across different review volumes

## Related
- Previous work: Switched from qwen2.5:7b to llama3.2:3b
- Optimized research-based prompt from 1200+ to 783 characters
- Fixed product scraping resilience (separate issue)
