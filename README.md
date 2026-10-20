# RogueDex Labs Agent Skills

Shared [Agent Skills](https://github.com/vercel-labs/skills) for RogueDex Labs
projects, installed with the `skills` CLI. This is a **monorepo**: each skill is a
folder under [`skills/`](skills/), so you choose which one(s) to install.

## Prerequisites

- **Access to the `roguedex-labs` org** — this repo is private. The CLI
  clones over **HTTPS** using your GitHub credentials (Git Credential Manager, or
  a `GITHUB_TOKEN`). SSH also works if your key is added to GitHub.
- Node.js (the commands below run via `npx`, no global install needed).
- Run the commands from your **project's root** (where you want
  `.agents/skills/`). On Windows + WSL, run inside WSL so the working directory
  resolves correctly.

## Install a skill

All commands use the HTTPS source
`https://github.com/roguedex-labs/roguedex-skills.git` (works with Git
Credential Manager). Prefer SSH? Swap in
`git@github.com:roguedex-labs/roguedex-skills.git` once your key is on
GitHub.

**1. Browse what's in the repo (installs nothing):**
```bash
npx skills add https://github.com/roguedex-labs/roguedex-skills.git --list
```

**2. Install one specific skill (recommended — no prompts):**
```bash
npx skills add https://github.com/roguedex-labs/roguedex-skills.git --skill sage-blade-escaping
```

**3. Install interactively (choose from a menu):**
```bash
npx skills add https://github.com/roguedex-labs/roguedex-skills.git
```
In a normal terminal you get a checklist to pick skills. Run inside an AI agent
(e.g. Claude Code) it detects the agent and installs non-interactively.

**4. Install everything in the repo:**
```bash
npx skills add https://github.com/roguedex-labs/roguedex-skills.git --all
```

Installed skills are written to your project's `.agents/skills/<name>/` and pinned
in `skills-lock.json` — **commit `skills-lock.json`** so teammates get the same
versions via `npx skills update`.

> Tip: `--skill` and `--agent` accept `*` for "all", and `-y` skips confirmation
> prompts. `--global` (`-g`) installs at the user level instead of the project.

## What it looks like

Real output from running these inside a project repo (cleaned up for readability).

**Browsing the repo** with `--list`:

```text
$ npx skills add https://github.com/roguedex-labs/roguedex-skills.git --list

●  claude-code agent detected — installing non-interactively
◇  Source: https://github.com/roguedex-labs/roguedex-skills.git
◇  Cloning repository… done
◇  Found 1 skill

◇  Available Skills
│
│    sage-blade-escaping
│
│      Enforce correct output escaping in Sage 11 / Acorn Blade templates and
│      prevent double-encoding bugs. MUST be invoked before writing or editing
│      any `.blade.php` output that emits a URL, attribute, JavaScript, JSON,
│      or HTML …
│
└  Use --skill <name> to install specific skills
```

**Installing it** with `--skill sage-blade-escaping`:

```text
$ npx skills add https://github.com/roguedex-labs/roguedex-skills.git --skill sage-blade-escaping

◇  Repository cloned
◇  Installation complete

◇  Installed 1 skill
│
│  ✓ .agents/skills/sage-blade-escaping
│    universal: Antigravity, Cursor, Gemini CLI, GitHub Copilot, OpenCode +12 more
│    symlink → Claude Code
│
└  Done!  Review skills before use; they run with full agent permissions.
```

**What lands in your project** — the whole skill (the helper script keeps its
executable bit), plus a pin in `skills-lock.json`:

```text
.agents/skills/sage-blade-escaping/
├── SKILL.md
├── references/escaping-mechanics.md
├── scripts/
│   ├── audit-escaping.sh            # executable; requires PHP CLI
│   ├── audit-escaping.php
│   └── test-audit-escaping.php
└── examples/category-subcategory-cards.blade.php
```

```jsonc
// skills-lock.json (commit this file)
{
  "version": 1,
  "skills": {
    "sage-blade-escaping": {
      "source": "roguedex-labs/roguedex-skills",
      "sourceType": "github",
      "skillPath": "skills/sage-blade-escaping/SKILL.md",
      "computedHash": "…"
    }
  }
}
```

## Use a skill

Once installed, the skill lives in `.agents/skills/<name>/` and your AI agent picks
it up automatically per the project's skill gates — e.g. **`sage-blade-escaping`
is invoked before editing any Blade output** (see the project's `AGENTS.md`).
There's no separate "run" step.

Preview a skill's guidance **without installing** it (one-off):
```bash
npx skills use https://github.com/roguedex-labs/roguedex-skills.git --skill sage-blade-escaping
```

## Manage installed skills

```bash
npx skills list                            # list installed skills
npx skills update -y                       # update all skills to latest
npx skills update sage-blade-escaping      # update just one
npx skills remove sage-blade-escaping      # remove one
```

## Available skills

| Skill | Description |
|---|---|
| [`sage-blade-escaping`](skills/sage-blade-escaping/SKILL.md) | Enforce correct output escaping in Sage 11 / Acorn Blade templates and prevent double-encoding bugs. |

## Verify the escaping guard

Run the CLI regression checks as an unprivileged user so unreadable-file and
unreadable-directory cases exercise real permission failures:

```bash
php skills/sage-blade-escaping/scripts/test-audit-escaping.php
skills/sage-blade-escaping/scripts/audit-escaping.sh skills/sage-blade-escaping/examples
```

The guard uses PHP's tokenizer to find helper calls in complete escaped echoes,
ignoring Blade comments, verbatim blocks, and escaped echoes. It fails closed on
read, traversal, or scan errors. See the skill's audit section for exit codes and
the limits of a clean result.

## Add a new skill

Create a directory under `skills/` containing a `SKILL.md` with YAML frontmatter
(`name` + `description`) followed by the body, then commit it:

```
skills/
  <skill-name>/
    SKILL.md          # required
    references/       # optional supporting docs
    scripts/          # optional helper scripts (keep shell scripts LF + executable)
    examples/         # optional examples
```

You can scaffold one with `npx skills init <skill-name>`. After pushing, it's
immediately installable via `npx skills add … --skill <skill-name>`.
