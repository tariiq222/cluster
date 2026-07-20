---
name: cluster-model-routing
description: Route project planning, research, implementation, and review to Webvue models by risk and cost. Use when decomposing non-trivial work or choosing agents.
---

# Cluster Model Routing

All models must use the `webvue` provider.

## Routing Table

| Work | Agent | Model |
|---|---|---|
| Primary orchestration and final integration | `orchestrator` | `webvue/gpt-5.6-sol` |
| Deep plan ownership and final synthesis | `plan` | `webvue/gpt-5.6-sol` |
| Repository and planning research | `plan-researcher-kimi`, `explorer-kimi` | `webvue/kimi-k3` |
| Requirement structure and readiness | `plan-structurer-minimax`, `executor-readiness-minimax` | `webvue/MiniMax-M3` |
| Mechanical changes and focused tests | `mechanical-worker-minimax` | `webvue/MiniMax-M3` |
| Bounded low-to-medium-risk implementation | `economy-worker-kimi` | `webvue/kimi-k3` |
| Routine production implementation | `module-worker-luna` | `webvue/gpt-5.6-luna` |
| Difficult bounded implementation | `complex-worker-luna` | `webvue/gpt-5.6-luna` |
| Independent plan critique | `plan-critic-fable` | `webvue/claude-fable-5` |
| Independent code review | `reviewer` | `webvue/codex-auto-review` |
| Screenshot and image inspection | `visual-inspector` | `webvue/gpt-image-2` |
| Proven high-risk escalation | `risk-reviewer-terra` | `webvue/gpt-5.6-terra` |

## Escalation

1. MiniMax handles deterministic tasks only.
2. Kimi handles research and bounded implementation with explicit acceptance criteria.
3. Luna handles production complexity or a failed Kimi package.
4. Terra is allowed only after evidence shows unresolved architecture, security, migration, transaction, Outbox, or cross-module risk.
5. Sol remains the manager and final integrator rather than the default worker.

## Image Routing

- All configured Webvue text models reject image input.
- Route screenshots and image files to `visual-inspector` using `webvue/gpt-image-2`.
- Use `/inspect-image` or select `visual-inspector` before submitting an image.
- Convert visual findings into an English evidence brief before delegating follow-up code work.

Do not rerun the same failed approach with a more expensive model without changing the evidence, scope, or method.
