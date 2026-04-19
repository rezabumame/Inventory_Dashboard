---
name: "find-skills"
description: "Helps discover and install agent skills. Invoke when user asks 'how do I do X', 'find a skill for X', or wants to extend agent capabilities."
---

# Find Skills

This skill helps you discover and install skills from the open agent skills ecosystem (skills.sh).

## When to Use This Skill
Use this skill when the user:
- Asks "how do I do X" where X might be a common task with an existing skill.
- Says "find a skill for X" or "is there a skill for X".
- Asks "can you do X" where X is a specialized capability.
- Expresses interest in extending agent capabilities.

## Key Commands
- `npx skills find [query]` - Search for skills by keyword.
- `npx skills add <package>` - Install a skill from GitHub or other sources.
- `npx skills list` - List installed skills.

## How to Help
1. **Understand Needs**: Identify the domain (React, testing, design, etc.).
2. **Search**: Use `npx skills find` to look for relevant skills.
3. **Recommend**: Present the skill name, what it does, and how to install it.
4. **Install**: Offer to run the install command for the user.
