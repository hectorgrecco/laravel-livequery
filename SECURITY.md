# Security policy

This repository is a preview and is **not production-certified**.

Please do not disclose vulnerabilities in public GitHub issues. Use GitHub
Security Advisories if private vulnerability reporting is enabled for this
repository. If it is not enabled, contact a maintainer through the repository's
GitHub profile before sharing sensitive details. Include affected versions, a
reproduction, and the possible impact.

Security boundaries:

- Every channel is private and requires the application to define authorization.
- Missing authenticated identities are denied before the application callback runs.
- Broadcaster payloads do not include model attributes.
- Tenant identifiers must be bounded and safe for channel names.
- Model events do not capture raw SQL or bulk updates.
- Notifications are not guaranteed durable. A subscriber must refresh on reconnect.
- A lost broadcast after commit is possible until a transactional outbox is added.
- Rate limiting, connection limits and broadcast fanout require application tuning.
