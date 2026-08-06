# AI agent operations

## Roles

- Codex owns backend contracts, permissions, integration and acceptance tests.
- Claude Code is the frontend and implementation reviewer. It uses the normal
  Claude account unless a provider-specific launcher is used.
- Hercules owns bounded UX briefs. Its repository contract lives under
  `.ai-collaboration/`; it is not treated as a code executor.
- DeepSeek has two independent modes: the portal uses a read-only, no-tools
  assistant; developers may use the local DeepSeek-backed Claude Code profile.

## DeepSeek credential

Never reuse a key copied into chat, source code, Git, logs or screenshots.
Revoke that key in the DeepSeek panel and create a replacement. Store the new
key for the current Windows user with:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/windows/Set-DeepSeek-AgentCredential.ps1
```

The script prompts without echo and stores only a Windows DPAPI ciphertext in
`%APPDATA%\SuperOptic`. The plaintext exists only in the launcher process while
Claude Code starts with the official DeepSeek Anthropic-compatible endpoint.

Read-only review is the default:

```powershell
powershell -ExecutionPolicy Bypass -File tools/invoke-deepseek-agent.ps1 -Prompt "Review this module and list P0/P1 findings."
```

Repository edits require the explicit `-AllowEdits` flag. Even in that mode the
third-party model is restricted to `Read`, `Grep`, `Glob`, `Edit` and `Write`;
shell execution and permission bypass are not supported by the launcher.

## Production boundary

Do not install or run a coding agent inside the production web service account.
The Ubuntu service receives only the portal's read-only DeepSeek configuration
and the provider key from `/etc/superoptic-mgs/superoptic.env`. Provider calls,
router changes, OLT writes and message delivery remain behind their existing
approval and live-operation guards.
