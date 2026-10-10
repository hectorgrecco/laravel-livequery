# Contributing

Thank you for considering a contribution. Please review `CODE_OF_CONDUCT.md`,
`SECURITY.md`, and `docs/PROPOSAL.md` before proposing feature changes.

## Local checks

```bash
composer install
composer test
composer format:check
composer analyse
```

## Design rules

1. Keep Laravel's Eloquent events and broadcasting as the integration boundary.
2. Never weaken tenant isolation or serialize arbitrary models to Reverb.
3. Cover transaction behavior, tenancy and reconnection assumptions with tests.
4. Document unsupported operations rather than implying guaranteed CDC.
5. Add a failing regression test when addressing a bug.
6. Keep changes small and avoid dependencies on proprietary services.

Pull requests should state their motivation, supported versions, tradeoffs and
reproduction. AI-assisted contributions must be fully reviewed and understood
by their authors. Follow Laravel's own policies before proposing changes upstream.
