# corex/tenancy compatibility passport

This is factual evidence, not a Composer constraint, runtime registry, or release promise.

| Field | Recorded fact |
| --- | --- |
| Package / license / profile | `corex/tenancy` / MIT / public physical DB-per-account profile, requires `corex/core` |
| Policy / declared constraint | PHP 8.4–8.5 and Laravel 12–13 / `php:^8.4`, Illuminate `^12.0|^13.0` |
| Public local proof | P4.4: PHP 8.4.1 and 8.5.10 against Laravel 12 and 13; cold update, platform check and fresh lock install. The PG16 template-integrity suite passed: three tests, three assertions. PHP 8.3 is explicitly untested and outside the declared constraint. |
| Release-ready status | Not claimed. The P4.4 receipt is local evidence; it is not a current hosted-runner or publication receipt. |
| P4 source and prior candidate | `ea7d0dbdf36b122904abba91e124018c11b939ed`; tree `d2e858e07db69a68b333300eb3ce191c8f8aebb0`; prior ZIP `c85fd4636159a8e165c5053a6956460ae4bb13223d4fe2e49fdb859532814175` |
| P5 docs candidate | Local-only committed candidate and exact ZIP/metadata digests are recorded in `plans/2026.09.12-№2-COREX-APPROVED-SPECS/findings/P5.1-passport-conformance.md`; the self-altering ZIP digest is deliberately not embedded here. |
| Coverage policy | No package coverage floor or current package measurement is claimed by this passport. |
| Limitations | The Stancl candidates remain sealed local transport evidence, not a public channel. No publication or current hosted-runner claim is implied. |

The package README documents its template-integrity and provider limits.
