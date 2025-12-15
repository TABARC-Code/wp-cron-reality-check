# wp-cron-reality-check
Looks at what WordPress cron says it is doing, compares it to reality, and shows where things are late, stuck or orphaned.  it shou

<p align="center">
  <img src=".branding/tabarc-icon.svg" width="180" alt="TABARC-Code Icon">
</p>

# WP Cron Reality Check

WordPress calls this thing "cron". It is not. It is a polite suggestion taped to the wall that sometimes gets ignored for hours.

This plugin does not pretend to fix it. It just tells the truth about what WordPress scheduled tasks are doing, or failing to do.

## What it does

On one screen under Tools you get:

### Cron health summary

A blunt little score across:

- Overdue events  
- Heavy repeating jobs  
- Orphaned cron hooks  
- Total event volume  
- Whether WP Cron is disabled  

Plus a few sentences explaining why the score is what it is.

It is not scientific. It is a sanity check.

### Configuration and locks

Shows:

- `DISABLE_WP_CRON` status  
- `ALTERNATE_WP_CRON` status  
- Cron lock information  
  - When the current or last lock was taken  
  - Whether the lock looks stale  
- The `wp-cron.php` URL you should be hitting if you are using real server cron  

This tells you quickly whether cron is even allowed to work.

### Overdue and past scheduled events

Lists cron events where:

- Scheduled time is already in the past  
- A small grace period has passed  

For each event you see:

- Hook name  
- Scheduled time in UT,C  
- How late it is  
- Whether it is a one shot or repeating job  
- A sample of its arguments  

If posts are missing their schedules or background jobs never seem to run, this is where you look.

### Suspicious repeating events

Highlights repeating events where:,

- The interval between runs is very short  
- By default, less than or equal to five minutes  

For each hook you see:

- The hook name  
- The schedule name  
- The interval in human readable form  
- A few sample timestamps and args  

Short interval jobs are not always bad. Dozens of them at once can be.

### Orphaned cron hooks

Shows hooks that:

- Have events scheduled in the cron array  
- Have no callbacks currently attached to that hook  

That usually means:

- A plugin scheduled events while active  
- Then was deactivated or removed  
- And nobody cleaned up the events  

These are safe to stare at with suspicion.

### Raw snapshot summary

A quick number dump:A

- Total number of scheduled events  
- Top hooks by number of events  

If you have three plugins and somehow 1600 cron entries, this will at least raise an eyebrow.

## What it does not do

Important:

- It does not run cron for you  
- It does not reschedule tasks  
- It does not delete cron entries  
- It does not call `wp-cron.php` automatically  
- It does not fix hosting level cron misconfigurations  

This is inspection only. Diagnosis, not treatment.

## Requirements

- WordPress 6.0 or newer  
- PHP 7.4 or newer  s
- Administrator access (manage options)  

On very busy sites with huge cron queues, the first load might be a little heavy. This tool prefers honest inspection to pretending everything is light.

## Installation

Clone or download:

```bash
git clone https://github.com/TABARC-Code/wp-cron-reality-check.git
xx
