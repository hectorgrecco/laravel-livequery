# Maintainer / coding agent rules

This package is a **preview**. Treat `docs/PROPOSAL.md`, `SECURITY.md` and the Laravel 13 contribution guide as required references.

- PHP 8.3+, strict typed public APIs when possible; PSR-4 + Laravel Pint.
- Use Pest and Testbench; test authorization, rollback, tenant reassignment.
- Do not infer authentication scopes, read records for broadcasts, or send Eloquent models in payloads.
- Never claim CDC, exactly-once or guaranteed event delivery.
- Avoid new infrastructure requirements and auto-publishing releases.
- Keep the Livewire/Volt adapter optional and do not require `render()` in Volt single-file classes.
- Do not create PRs upstream in Laravel without maintainer review. Laravel prohibits generated PR descriptions.
