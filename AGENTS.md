# Development rules

Read docs/business-rules.md before changes. Preserve Laravel, Filament and Livewire architecture and existing role permissions.
Never modify production data, rewrite applied migrations, run destructive migrations or seed a working database. Never publish credentials, personal records, uploads or internal reports.
Do not change Ledger, CashDesk, Money, CrmWriter, SFP rates, rounding, recognition or historical financial operations without explicit approval. Preserve transactions, audit records and idempotency.
Tests require an isolated disposable database: APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, empty DB_URL and no cached configuration. MariaDB trials require a separately approved disposable database.
Use codex/ branches, run relevant Pest tests and full financial regression, build Vite and run tools/check-public.py before every release. Do not publish or deploy without approval. Never import the private repository history.
