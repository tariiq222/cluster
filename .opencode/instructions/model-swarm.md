# Project Model Swarm

Use the project model-swarm subagents for implementation, investigation, planning, and review work.

- For every non-trivial task, delegate at least three independent work units concurrently when the work can be safely parallelized. Do not delegate trivial tasks or work confined to a single obvious change when delegation adds no useful evidence or speed.
- Select a diverse subset of model families and reasoning efforts instead of repeatedly using one fixed profile. Include MiniMax M3 when an independent second opinion is useful.
- Match effort to complexity: begin with `none` or `low` for quick searches and well-scoped work, use `medium` for routine implementation, `high` or `xhigh` for difficult reasoning, and `max` for the hardest GPT-5.6 work. Escalate effort only when the task remains ambiguous, agents disagree, or verification fails.
- Run independent tool calls and subagents in one parallel batch. Do not launch agents sequentially when they can run concurrently.
- Give each agent a narrow, non-overlapping assignment and request a concise, evidence-based result that cites relevant files, lines or symbols, and verification performed.
- Each swarm agent may create up to ten child workers when its assignment has independent work units. Child workers must receive narrow, non-overlapping scopes, and must not recursively create further workers unless the parent explicitly requires it.
- Partition concurrent implementation agents by non-overlapping files or components. Never let multiple agents edit the same file concurrently; use one writer and parallel read-only reviewers when scopes overlap.
- Synthesize the agents' results, resolve disagreements using repository evidence and tests rather than any agent's opinion alone, then verify the final result.
- Do not spawn the entire roster for one task. Use the smallest diverse swarm that provides useful parallel coverage, from three up to fifteen agents depending on how many independent work units the task supports.
- At the end of each substantial session, capture durable lessons, decisions, project-specific patterns, and surprises in the project's memory or planning artifacts. Keep entries concise, evidence-based, and actionable; do not record transient details or duplicate existing knowledge.
- Reuse applicable general skills first. When the project's workflow, conventions, or tooling requires it, tailor a project-local version under `.opencode/skills/` while preserving the general skill's proven workflow and documenting only the project-specific additions.
- When a project-specific workflow, correction, or multi-step pattern recurs, use the available OpenCode Skill Creator workflow to design, evaluate, and install a focused project skill under `.opencode/skills/`. Do not create a skill for a one-off task; first confirm the recurring trigger, expected outcome, and validation approach.

The generated agent names show the model and effort directly, such as `GPT-5.6-Sol++++` or `MiniMax-M3++`. All configured GPT models and all of their supported effort variants are available, plus MiniMax M3 in `none` and `thinking` variants.
