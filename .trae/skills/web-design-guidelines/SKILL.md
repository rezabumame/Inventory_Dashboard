---
name: "web-design-guidelines"
description: "Review UI code for compliance with 100+ web interface best practices. Invoke when asked to review UI, check accessibility, audit design, or fix layout issues."
---

# Web Interface Guidelines

Review files for compliance with Web Interface Guidelines.

## How It Works

1. **Fetch Latest Guidelines**: Retrieve the latest rules from the official source:
   `https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md`
2. **Read Files**: Read the specified files or ask the user for a file pattern.
3. **Audit**: Check the code against all 100+ rules covering accessibility, performance, and UX.
4. **Report**: Output findings in the `file:line` format with clear fix suggestions.

## Trigger Phrases
- "Review my UI"
- "Check accessibility"
- "Audit design"
- "Review UX"
- "Check my site against best practices"

## Categories Covered
- Accessibility (aria-labels, semantic HTML, keyboard handling)
- Focus States (visible focus, focus-visible patterns)
- Forms (autocomplete, validation, error handling)
- Animation (prefers-reduced-motion, compositor-friendly transforms)
- Typography (curly quotes, ellipsis, tabular-nums)
- Images (dimensions, lazy loading, alt text)
- Performance (virtualization, layout thrashing)
- Dark Mode & Theming
