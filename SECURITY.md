# Security policy

This repository is **public**. Treat everything committed here as readable by anyone.

## Never commit

- `.env` files or any file containing real credentials (the `.env.example` template is the only exception)
- API keys, access tokens, webhook secrets, session secrets
- Private keys or certificates (`*.pem`, `*.key`, `*.p12`, `*.pfx`)
- Production database dumps, customer data, phone numbers, or account credentials
- Server logs that may echo request headers, tokens or internal hostnames

These paths are covered by `.gitignore`, but `git add -f` and new file extensions can still slip through —
review your diff before pushing.

## If a secret is committed

Deleting the file in a later commit is **not** enough: the value remains in the git history, and on a public
repository it must be considered already leaked. Always, in this order:

1. **Revoke / rotate the credential at the provider first.** This is what actually stops the exposure.
2. Remove the value from the code and read it from the environment instead.
3. If the repository must stay public, purge the history (`git filter-repo` or BFG) and force-push, then
   ask collaborators to re-clone.
4. Audit provider logs for use you do not recognise.

## Reporting a vulnerability

Report security issues privately — use GitHub's *Security → Report a vulnerability* tab, or contact the
maintainer directly. Please do not open a public issue for an unfixed vulnerability.
