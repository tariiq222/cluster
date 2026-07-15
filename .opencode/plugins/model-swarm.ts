const standardEfforts = ["none", "low", "medium", "high", "xhigh"] as const
const sparkEfforts = ["low", "medium", "high", "xhigh"] as const
const extendedEfforts = [...standardEfforts, "max"] as const
const effortMarks = {
  none: "+",
  low: "++",
  medium: "+++",
  high: "++++",
  xhigh: "+++++",
  max: "++++++",
} as const

const gptModels = [
  ["GPT-5.3-Codex-Spark", "openai/gpt-5.3-codex-spark", sparkEfforts, "#FB923C"],
  ["GPT-5.4", "openai/gpt-5.4", standardEfforts, "#3B82F6"],
  ["GPT-5.4-Fast", "openai/gpt-5.4-fast", standardEfforts, "#60A5FA"],
  ["GPT-5.4-Mini", "openai/gpt-5.4-mini", standardEfforts, "#38BDF8"],
  ["GPT-5.4-Mini-Fast", "openai/gpt-5.4-mini-fast", standardEfforts, "#67E8F9"],
  ["GPT-5.5", "openai/gpt-5.5", standardEfforts, "#A855F7"],
  ["GPT-5.5-Fast", "openai/gpt-5.5-fast", standardEfforts, "#D8B4FE"],
  ["GPT-5.6-Luna", "openai/gpt-5.6-luna", extendedEfforts, "#818CF8"],
  ["GPT-5.6-Luna-Fast", "openai/gpt-5.6-luna-fast", extendedEfforts, "#A5B4FC"],
  ["GPT-5.6-Sol", "openai/gpt-5.6-sol", extendedEfforts, "#FBBF24"],
  ["GPT-5.6-Sol-Fast", "openai/gpt-5.6-sol-fast", extendedEfforts, "#FDE047"],
  ["GPT-5.6-Terra", "openai/gpt-5.6-terra", extendedEfforts, "#34D399"],
  ["GPT-5.6-Terra-Fast", "openai/gpt-5.6-terra-fast", extendedEfforts, "#86EFAC"],
] as const

const workerPrompt = `You are a focused member of a parallel model swarm. Complete only the assignment you receive. Inspect repository evidence before drawing conclusions. You may delegate independent work to at most ten child workers in parallel. Give child workers narrow, non-overlapping scopes and do not allow recursive delegation unless your parent explicitly requires it. Coordinate through scope: do not edit files outside your explicit assignment, and do not overwrite unrelated work. Return concise findings, file references, changes, and verification results to the orchestrator.`

export default async function modelSwarm() {
  return {
    config(config: { agent?: Record<string, unknown> }) {
      config.agent ??= {}

      for (const [name, model, efforts, color] of gptModels) {
        for (const effort of efforts) {
          config.agent[`${name}${effortMarks[effort]}`] = {
            description: `Parallel swarm worker using ${model} with ${effort} reasoning effort.`,
            mode: "all",
            model,
            variant: effort,
            color,
            prompt: workerPrompt,
          }
        }
      }

      for (const variant of ["none", "thinking"] as const) {
        const marks = variant === "none" ? "+" : "++"
        config.agent[`MiniMax-M3${marks}`] = {
          description: `Parallel swarm worker using MiniMax M3 with ${variant} reasoning.`,
          mode: "all",
          model: "minimax-coding-plan/MiniMax-M3",
          variant,
          color: "#F472B6",
          prompt: workerPrompt,
        }
      }
    },
  }
}
