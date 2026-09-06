# AGENTS.md — OnKupon Autonomous Commerce Content Agent

## Architecture
- Main plugin bootstrap: `onkupon-autonomous-commerce-agent.php`.
- Namespaced PHP classes live in `includes/` using `OnKupon\Agent`.
- Admin pages are in `includes/Admin/`.
- Background jobs are in `includes/Scheduler/Jobs/` and must be registered through `JobRegistrar`.
- External integrations must stay behind provider interfaces in `AI/`, `Research/`, and `Social/`.
- Database tables are created with `dbDelta` in `Installer`.

## Coding standards
- PHP 8.1+ only.
- Use WordPress APIs, escaping, sanitization, nonces, and prepared SQL.
- Prefer WooCommerce CRUD APIs over direct product-table queries.
- Keep classes focused and avoid large god objects.
- Never wrap imports/includes in try/catch.

## Security rules
- Runtime must never call Codex CLI, ChatGPT CLI, shell out to AI tools, execute model-generated commands, or dynamically write/execute PHP.
- Runtime AI calls must go through safe provider abstractions such as `AIProviderInterface`.
- Never hardcode secrets. Prefer constants/env values and mask secrets in UI/logs.
- Treat external research and LLM output as untrusted.
- Validate and sanitize LLM output before publishing.
- Do not expose logs or REST endpoints publicly.

## Review integrity rules
- Do not implement fake customer reviews.
- Do not generate fake ratings or manipulate WooCommerce rating averages.
- Do not impersonate customers or create fake social proof.
- Only verified-buyer review request workflows and clearly labeled editorial/AI-assisted product content are allowed.

## Testing
- Run `find onkupon-autonomous-commerce-agent -name '*.php' -print0 | xargs -0 -n1 php -l`.
- Run `bash onkupon-autonomous-commerce-agent/bin/build-zip.sh`.
- Add/extend PHPUnit tests under `tests/unit/` for pure logic.

## Build
Use `bash bin/build-zip.sh`. Keep dev artifacts, `.git`, `node_modules`, and temporary build directories out of the plugin ZIP.

## Expected future work
- Expand provider adapters with official platform API implementations.
- Add richer dashboard REST endpoints and Chart.js visualizations.
- Add full WordPress integration test coverage with WooCommerce fixtures.
