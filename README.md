# Vigil

Public, subscription sports-betting analytics. *We watch the line so you don't have to.*

Vigil is the customer-facing product. The modelling, odds ingestion and value scanning come from
[Edge Lab](https://github.com/primeeventsource-bit/edge-lab), which stays private and is read
through an internal API.

The full build spec is [docs/MASTER_PROMPT.md](docs/MASTER_PROMPT.md). Start with step 1 of its
build order.

Stack: Laravel 12, Livewire 3, Tailwind, PostgreSQL, deployed on Laravel Cloud.
