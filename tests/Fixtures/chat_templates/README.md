# Chat-template fixtures

Provenance for the real chat templates used as test fixtures (Phase 13).

## qwen3-next.jinja

- Source model: `/var/lib/llama/models/apetersson/Qwen3.8-Flash-Next-GGUF/Q5_K_M-BF16-Ngrams/Qwen3.8-Flash-Next-Q5_K_M.gguf` (beelink)
- Extracted: 2026-10-08 via `gguf-py` (`GGUFReader.fields['tokenizer.chat_template']`) on beelink
- MD5: `519239a4908bb1f805bbce5fa8c8a242` (8952 bytes)
- Constraint it encodes: `resolved_reasoning_effort not in ('xhigh', 'medium', 'low')` → `raise_exception`; template default is `xhigh`.

This is a verbatim capture of the model's own template, not invented data.
`ChatTemplateFamily`'s fingerprint table must stay consistent with these files.
