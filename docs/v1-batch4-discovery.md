# V1 Batch 4 discovery profile

`reservations:diagnose-v1-batch4` is a read-only profile of the remaining historical reservations. It does not attribute a reservation, approve a candidate, or change Batch 1, Batch 2, or Batch 3.

The command has no execute, confirm, or rollback option. It is not scheduled. It does not call `HistoricalReservationAttributionService` or any Batch 3 prepare, approve, apply, or rollback class.

Exit 0 means the read finished. It does not mean the live database matches the last verified production snapshot, and it does not mean a discovery category is safe to attribute.

## Cohort and sources

The historical cohort is `reservations.created_at < 2026-06-18 00:00:00`, the same `ReservationOrigin::IMPORTED_BEFORE` boundary used by the earlier batches. `user_id` is the current agency link. It does not decide whether the row is historical.

Rows with `created_at` on or after that instant, and rows with no `created_at`, are reference evidence only. They are not discovery candidates.

Identity fields that exist and are read:

| Source | Fields used | What it is not |
|---|---|---|
| `reservations` | `user_id`, `user_name`, `email`, `license_plate`, `status`, `created_at`, `vehicle_id`, `free_reservation_request_id` | `country`, fiscal fields, and payment fields are not identity evidence |
| `users` | `id`, `name`, `email` | There is no separate agency-alias table |
| `vehicles` | `user_id`, `license_plate`, `status` | Only `active` and `removed` plates enter the registry map. A null `user_id` is not an owner |
| `reservation_attributions` | `reservation_id`, `active_reservation_id`, `agency_user_id`, `evidence` | A missing audit is not proof that the reservation was created by that account |

`vehicle_id` and `free_reservation_request_id` are counted. A vehicle foreign key can support or contradict a sample. Neither column is its own discovery category. The free-request table is not joined.

The last verified production snapshot, stored as constants and compared rather than assumed, is:

- historical 21,342
- attributed 9,361
- unattributed 11,981
- Batch 1 active 4,609
- Batch 2 active 2,696
- Batch 3 active 2,056, of which regular 2,052 and reviewed-name 4

The four held Batch 3 reservations are part of that unattributed remainder. This command does not look them up by agency id and does not reopen them.

## How the read runs

Users, vehicles, attribution rows, and reservations are read in chunks of 500 inside one transaction. This repository does not set a transaction isolation level. MariaDB's default REPEATABLE READ is the assumed snapshot. SQLite tests run on one connection and do not prove MariaDB isolation. A concurrent write on another connection can change a later chunk relative to the first if the server is not on that default.

No statement in this command inserts, updates, or deletes.

## Normalization

Canonical email: `HistoricalAttributionBatchDryRun::normalizeEmail` (`trim`, then ASCII lowercase). Dots and plus-tags are not folded.

Canonical agency name: `HistoricalAttributionBatchDryRun::normalizeAgencyName` (`trim`, collapsed whitespace, `mb_strtolower`). Diacritics stay.

Plate: `DuplicateReservationAttemptService::normalizeLicensePlate` (trim, upper case, Ž/Š/Č/Ć/Đ folded, spaces and other non-alphanumeric characters removed). That is the existing plate normalizer. It is not a new name rule.

Invalid email shape is an observation only: a non-empty canonical email that does not match `local@domain.tld`. Matching still uses the canonical string. The shape check does not remove a row from a category.

## Who may be a discovery candidate

An unattributed historical row (`user_id` null) is not automatically eligible.

The discovery population is historical, `user_id` null, and has no attribution row of any kind. A row with an active audit, or only a closed audit, is counted under inconsistencies or exclusions and is not placed in B4-A through B4-E.

## Evidence strength

These tiers are deliberately unequal.

| Provenance | Used as an anchor for another reservation |
|---|---|
| `users.email` and the vehicle registry | Yes. These are account records, not historical attributions |
| Active Batch 1 (`v1-phase2-batch1:exact_email_unique_exact_name_exclusive_plate`) | Yes, as an email anchor and as a plate reference. The plate was part of that batch's own rule |
| Active Batch 2 (`v1-phase2-batch2:exact_email_unique_exact_name:plate_*`) | Yes, as an email anchor. Its plate is a label, not proof of purchase, and a sample says `batch2_plate_label_only` when no Batch 1 plate agrees |
| Active Batch 3 regular (`v1-phase2-batch3:name_unique_email_unregistered`) | No. A name heuristic must not become evidence for another reservation |
| Active Batch 3 reviewed name | No. Human review of a name heuristic is not independent email or plate proof |
| Historical `user_id` with no active audit | No. Provenance is uncertain |
| Post-cutoff row with `user_id` and no attribution audit | No. The admin label calls this a V2 agency reservation, but absence of an audit does not prove native account creation |

Batch 3 disagreement is an ambiguity flag on the sample. It does not create a second proposed agency.

## Discovery categories

These labels are exploratory. None of them authorizes a write.

**B4-A.** The canonical reservation email belongs to exactly one `users.email`, and the canonical reservation name differs from that user's canonical name. An exact name match is not B4-A. It is reported as `registered_email_exact_name` and stays out of this category. Two users whose stored emails normalize to the same string are a conflict, not a proposal.

**B4-B.** The canonical email matches no user, and that same email occurs on active Batch 1 or Batch 2 reservations of exactly one agency. Batch 3 emails, reviewed-name emails, and post-cutoff user links do not qualify. They are counted separately as unused weak signals.

**B4-C.** The exploratory alias of the reservation name matches exactly one registered user, the canonical names differ, and an independent signal supports that same user. Independent support is one of: the reservation email is that user's email, the email has a trusted Batch 1 or Batch 2 history for only that user, or the plate is exclusive to that user in the vehicle registry. An alias with no such support is counted and is not B4-C. An alias that matches two users is ambiguous and is not B4-C.

The exploratory alias is not canonical. It removes dots and apostrophes, turns other punctuation into spaces, drops the tokens `doo`, `ltd`, `llc`, `inc`, and `gmbh`, sorts the remaining tokens, and refuses a key of fewer than two tokens. Diacritics are not folded. `d.o.o.` becomes `doo` and is then dropped. A reordered multi-word name can match. That match is not proof of ownership.

**B4-D.** The plate occurs on active Batch 1 or Batch 2 reservations of exactly one agency, the name is compatible with that agency, and the reservation email differs from that agency's current `users.email`. Compatible means the canonical names are equal, or the exploratory alias uniquely identifies that same agency. An empty email differs from a registered email and is flagged `email_missing`. Batch 3 plates are not used. A plate shared by trusted attributions of two agencies is a conflict, not a proposal.

**B4-E.** The open discovery row has no B4-A, B4-B, B4-C, or B4-D proposal. That includes missing data, conflicts that leave no single agency, weak signals, and aliases without support.

A row with two proposals for different agencies stays in each matching category and is also counted as contradictory. It is not moved into B4-E.

## Overlap

Category reservation counts are not disjoint. Do not add B4-A through B4-D together.

Each category reports two numbers:

- `reservations`: distinct reservation ids in that category
- `distinct agencies`: distinct proposed `users.id` values in that category

A pairwise overlap is a count of reservations that fall in both categories, including pairs that propose different agencies. `any_of_abcd` is the distinct reservation count in at least one of B4-A through B4-D. `no_credible_candidate` equals B4-E. Together, `any_of_abcd` and B4-E partition the discovery population. They do not partition every unattributed row, because rows with an audit are excluded before classification.

`multiple proposed agencies` means the proposals disagree. `contradictory evidence` means at least one proposal exists and another signal disagrees (another proposal, a vehicle foreign key, or an exclusive registry plate pointing elsewhere). A conflict that produces no proposal remains B4-E.

## Samples and the optional CSV

The console prints at most five reservations per category, in reservation-id order. Each sample has the reservation id, one proposed user id when the proposals agree, the signals, supporting evidence types, conflicting evidence types, anchor provenance, the count of independent references, ambiguity flags, and a masked email.

The independent-reference count is the number of distinct trusted Batch 1 and Batch 2 anchor reservations, plus one when the registered email matches the single proposal, plus one when the registry plate is exclusive to that proposal, plus one when `vehicle_id` points at that proposal. Batch 3 rows are not references. One anchor reservation that supplies both the email and the plate counts once.

The mask keeps the first character of the local part and the domain (`a***@example.com`). An address without `@` is printed as `***`. Names are not printed. The default console output is not a personal-data export. A fuller review of a specific id can be done with a separate read-only query.

`--report` writes a new CSV. The parent directory must already exist, must be outside the repository, and must be outside the public document root. An existing file is not overwritten. The CSV repeats the summary counts and the same masked samples. It does not list every unattributed reservation and does not contain a raw email or name.

## Console fields

- `Historical reservations`, `Attributed historical reservations`, `Unattributed historical reservations`: cohort counts. Attributed means `user_id` is not null.
- `Active Batch 1`, `Active Batch 2`, `Active Batch 3`, `Active Batch 3 regular`, `Active Batch 3 reviewed`, `Active other evidence`: active attribution rows whose reservation is inside the cohort, grouped by evidence. An active row whose `user_id` disagrees is still included here and is also an inconsistency.
- `Post-cutoff reservations`, `Post-cutoff user-linked reservations`, `Post-cutoff guest reservations`, `Reservations with no created_at`: outside the cohort.
- `Baseline` and `Baseline deviation`: live count against the verified constants above. A deviation is printed. It does not stop the profile.
- `Inconsistencies` and the five lines under it: unattributed with an active audit, unattributed with only a closed audit, attributed with no active audit, `user_id` different from the active audit's agency, and an active audit on a missing or non-cohort reservation. The result also keeps up to 20 ids for each of those groups. Those ids are not printed on the console.
- `Discovery population`: unattributed historical rows with no attribution row.
- `Excluded from discovery by an audit`: unattributed historical rows that have at least one attribution row.
- `Status ...`: status distribution of all unattributed historical rows, including rows excluded from discovery.
- `Nonempty email`, `Empty email`, `Invalid email shape`, `Nonempty name`, `Empty name`, `Nonempty plate`, `Empty plate`: same unattributed set. Invalid shape is a subset of nonempty email. Empty canonical values are empty, not invalid.
- `Distinct emails`, `Distinct names`, `Distinct plates`: distinct non-empty canonical values in that set. The values themselves are not printed.
- `Repeated emails`, `Repeated names`, `Repeated plates`: how many distinct values occur at least twice, how many reservations those values cover, and the size of the largest group. The repeated text is not printed.
- `Email owned by multiple users`: unattributed rows whose canonical email maps to two or more users.
- `Plate registered to multiple agencies`: unattributed rows whose plate has two or more positive owners in the active/removed vehicle registry.
- `Plate on trusted attributions of multiple agencies`: the same, using Batch 1 and Batch 2 anchors only.
- `Canonical name owned by multiple users` and `Exploratory alias owned by multiple users`: row counts, not a candidate list.
- `Unattributed rows with vehicle_id` and `Unattributed rows with free_reservation_request_id`: presence counts only.
- `B4-A` through `B4-E reservations, distinct agencies`: category sizes as defined above. B4-E's agency count is 0 because it has no proposal.
- `B4-B batch 3 email only, not used`, `B4-B reviewed-name email only, not used`, `Uncertain post-cutoff email only, not used`, `Alias without independent support, not used`: weak signals that did not create a proposal.
- `Overlap b4a_b4b` through `Overlap b4c_b4d`, `In three or more categories`, `Reservations in at least one of B4-A through B4-D`, `Reservations with multiple proposed agencies`, `Reservations with contradictory evidence`, `Reservations with no credible candidate`.
- `Batch 3 plate groups were not used as plate evidence`: how many distinct plates occur on Batch 3 attributions. The number is a guardrail, not a candidate count.
- `Sample ...`: one limited review row, as described above.

## Production command

Do not run this as part of deployment. When the owner chooses to profile production, the read-only Artisan command is:

```text
reservations:diagnose-v1-batch4
```

Through the Plesk PHP binary used for this application:

```text
/opt/plesk/php/8.3/bin/php /var/www/vhosts/kotor.me/bus-v2.kotor.me/artisan reservations:diagnose-v1-batch4
```

In Plesk Laravel Toolkit, run that same Artisan command with no arguments for the console summary. An optional private CSV, outside the repository and outside `bus-v2.kotor.me/public`, is:

```text
/opt/plesk/php/8.3/bin/php /var/www/vhosts/kotor.me/bus-v2.kotor.me/artisan reservations:diagnose-v1-batch4 --report=/var/www/vhosts/kotor.me/tmp/v1-batch4-discovery-YYYYMMDD.csv
```

The directory must already exist. The file must not. The command does not create the directory and does not overwrite a file.

## Limitations

- This profile was not executed against production. The verified totals above are the owner's last snapshot, not a new measurement.
- Fuzzy name similarity, shared email, and plate equality are not proof of ownership.
- The exploratory alias can equate reordered words and a short legal-suffix variant. It cannot see diacritic folding, translations, or abbreviations outside the five suffix tokens.
- A Batch 2 plate match is a labeled historical attribution, not an independent plate registry.
- A post-cutoff user link remains uncertain provenance.
- `free_reservation_request_id` is only counted. A request that itself stores a user id is not followed.
- The console and CSV are samples. They are not the full unattributed population.
- SQLite tests do not establish MariaDB's REPEATABLE READ behavior.
