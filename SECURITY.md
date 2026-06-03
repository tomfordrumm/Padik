# Security Policy

## Reporting Vulnerabilities

Please do not open public issues for security vulnerabilities.

Email the maintainer or repository owner with:

- affected version or commit
- reproduction steps
- expected impact
- any logs, screenshots, or proof-of-concept details that help verify the issue

The project is early-stage. Security reports for authentication, authorization,
message privacy, secret-chat key handling, and deployment defaults are especially
important.

## Secret Chat Scope

Padik secret chats are designed so plaintext secret-chat messages are not stored
on the server. The current implementation still depends on the browser runtime,
IndexedDB key persistence, server-mediated public-key exchange, and users
manually verifying safety numbers.

Do not treat the current secret-chat implementation as independently audited
cryptography. Production deployments should document their threat model and run
a dedicated security review before relying on it for high-risk communication.
