# corex/tenancy

Laravel tenancy primitives for CoreX's physical PostgreSQL database-per-account
profile.

## Optional wakeup root hints

`corex-tenancy.wakeup.driver` defaults to `local_sweep`. With `corex/wakeup` installed, a cloud
deployment may explicitly select `root_registry`; this enables the advisory `root_wakeup_hints`
table and the `corex:wakeup:root-sweep` command. Hints never replace a tenant-local request or
provide authority to select an account connection.

## Local immutable provider proof

The unreleased `4.0.0-alpha1` tenancy candidate is tested only through the
sealed local Composer transport described in the distribution proof. It is not
a public package channel: consumers must configure an independently approved
root repository before resolving `axiomasoft/stancl-tenancy` and
`axiomasoft/stancl-jobpipeline`. This package does not declare a path, file, or
development-sibling repository and never falls back to the moving Stancl branch.

## Template integrity

The internal verifier accepts a ready template only when it has all of the
following:

- a lowercase SHA-256 `schema_hash` recorded in `root_templates`;
- a configured Ed25519-signed manifest from a configured trusted key; and
- a matching canonical, read-only PostgreSQL catalogue hash.

`tenancy.template_integrity.trusted_keys` and
`tenancy.template_integrity.manifests` are deliberately empty defaults.
Existing ready rows with a NULL hash are not compatible fallback templates.
Consumer write-path adoption is intentionally separate. Operational rollback
preserves the integrity metadata; the additive migration has no destructive
automatic down action.

## Compatibility evidence

The factual compatibility passport, its local proof class, and explicit limits
are in [PACKAGE.md](PACKAGE.md). It does not extend Composer constraints or
constitute a publication promise.
