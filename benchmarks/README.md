# Benchmark dataset

A synthetic pharma sales-force database for measuring the Report Generator
against the performance targets in the MVP plan. Shaped like a legacy CRM:
most relationships are only `*_id` naming conventions, a few are real foreign
keys, and `users` carries `password` / `remember_token` columns that the
scanner must hide.

| Table | Rows |
|---|---|
| `visits` (fact) | N |
| `sales` (fact) | N |
| `doctors` | 200,000 |
| `bricks` | 5,000 |
| `users` | 2,000 |
| `products` | 300 |
| `specialities`, `lines` | 40, 20 |

```bash
./seed.sh 1000000      # quick local run (~1 min)
./seed.sh 50000000     # M6 target size (needs ~15 GB of disk)
```

Data is deterministic, so timings from two runs of the same size are
comparable. Remove everything with `docker compose down -v`.
