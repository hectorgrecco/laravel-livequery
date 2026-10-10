# Launch checklist and reusable materials

> **Not approved for external posting.** Publishing, tagging influential maintainers, upstream PRs and security disclosures require a maintainer to review the actual implementation and its current verification status. Never claim tests passed if they have not.

## Phase A — engineering readiness

- [x] Confirm package identity: `hectorgrecco/laravel-livequery`
- [ ] Enable private vulnerability reporting or configure a private security address
- [x] Run Pest, Pint, Larastan, Composer validation and dependency audit on PHP 8.4
- [x] Install by Composer path in a clean Laravel 13 app; verify package discovery, queued log broadcast, and queued event publication to a local Reverb server
- [x] Validate PHP 8.3/8.4/8.5 and Laravel 12/13 in Actions
- [x] Run end-to-end Reverb/Volt demo with separate authenticated tenants
- [x] Check rollback, tenant move, bulk update omission, and missed-event recovery after disconnect
- [ ] Add an actual demo video or GIF showing records entering/exiting a filtered list
- [ ] Run security review before tagging a release

## Phase B — community launch

- [ ] Publish as **preview** first; clearly label unsupported operations
- [ ] Announce a runnable example (no hype about realtime SQL or CDC)
- [ ] Submit a package link to Laravel News
- [ ] Post a technical implementation write-up on Laracasts and r/laravel (respect rules)
- [ ] Ask for bug reports and practical use cases, not stars
- [ ] After adoption, share lessons and ask narrow questions in Laravel Discussions/Discord #internals
- [ ] Propose upstream PR only when a concrete low-level extension point is necessary

## Draft announcement — for maintainer review

Title: I built an Eloquent query invalidation package using Laravel Reverb

I kept wiring the same Eloquent observers, private broadcast channels and Livewire refresh listeners across projects, so I started extracting the pattern into an open-source package.

The idea is deliberately small: register a model with a tenant authorization policy, subscribe from a Volt component, and let Laravel broadcast invalidation after successful commits. The component still executes its own authorized Eloquent query.

It's a preview, not a SQL changefeed. Bulk SQL updates, offline replay and incremental query diffs aren't supported yet. I'm looking for feedback on authorization boundaries, missing change paths, and whether the extra abstraction is useful enough compared with native broadcasting.

[https://github.com/hectorgrecco/laravel-livequery] (the repository includes a reproducible Reverb/Volt browser smoke test)

## Draft Laravel core discussion — human rewrite required

**Do not copy-paste this as an upstream PR description.** Laravel contribution policy requires a contributor-written PR description and thorough review of AI-assisted changes.

Questions to discuss only after the package sees use:

- Is there a cleaner stable extension point for observer-driven, after-commit invalidation?
- Would first-party examples for model invalidation and query refresh be useful?
- Which portions should remain entirely in community packages?

Provide observed adoption and reproducible code, not abstract feature claims.
