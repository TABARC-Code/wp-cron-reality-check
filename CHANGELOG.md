## `CHANGELOG.md`

```markdown
# Changelog

## 1.0.0.5

- First public release.
- Added Tools  
  Cron Reality Check screen.
- Cron snapshot:
  - Read the internal cron array.
  - Flatten events into a usable structure.
  - Summarise total events and top hooks.
- Classification:
  - Overdue events based on current time and a grace period.
  - Group repeating jobs with very short intervals as "heavy repeating".
  - Mark orphaned hooks where cron events exist but no callbacks are attached.
- Configuration and lock reporting:
  - Display DISABLE_WP_CRON and ALTERNATE_WP_CRON status.
  - Show cron lock information and whether the lock looks stale.
  - Show the wp-cron.php URL for real server cron configuration.
- Cron health summary:
  - Simple score out of 100.
  - Severity tier: good, warning or critical.
  - Short human readable explanation of the score.
- Read only design:
  - No deletion or modification of cron entries.
  - No automatic spawn of wp-cron.php.
- Licensed under GPL-3.0-or-later.
