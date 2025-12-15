# IdiotsGuide  
WP Cron Reality Check

This is for the version of me who is tired of hearing "cron is fine" while scheduled posts silently miss their deadlines.

It assumes low patience and zero appetite for reading source code today.

## What cron is in WordPress, really

On a normal server, cron is:

- A real scheduler in the operating system  
- That runs commands at exact times  

In WordPress, "cron" is:

- A pretend scheduler  
- That only runs when someone loads a page  
- And only if nothing else is blocking it  

So if nobody visits the site at 3am, that 3am job does not run at 3am. It runs whenever the next person shows up, if at all.

This plugin does not fix that design. It just tells you how badly your site is suffering from it.

## Where to find it

After activating:

1. Log in to the admin.  
2. Go to Tools.  
3. Click Cron Reality Check.  

If you cannot see it, you are probably not an administrator.

## The main pieces, in plain language

### Health box at the top

You get:

- A score out of 100.  
- Colour hint: green, amber or red.  
- Short bullet points explaining what looks wrong.  

If the score is in the nineties and there are no overdue events, cron is probably not your main problem. If the score is low and you see words like "overdue" and "disabled", then yes, cron is guilty.

### Configuration and locks

This section answers three questions.

1. Is WP Cron disabled?  
   If `DISABLE_WP_CRON` is true, WordPress will never run cron on its own.  
   That is fine only if you or your host have set up a real server cron job that calls `wp-cron.php` regularly.

2. Is alternate cron enabled?  
   `ALTERNATE_WP_CRON` is a fallback when normal cron spawning fails.  
   If it is on, something may have gone wrong in the past.

3. Is there a stuck lock?  
   WordPress uses a lock to avoid overlapping cron runners.  
   If the lock timestamp is old and never clears, it suggests cron tried to run and may have hung.

If this whole section looks strange, your first stop is your hosting provider or whoever manages the server.

### Overdue events

These are the scheduled tasks that should already have fired. They are late.

The table shows:

- The hook name.  
- When it was supposed to run.  
- How late it is.  
- Whether it is a single run or repeating job.  
- A sample of its arguments.  

If this table is non empty and you are seeing scheduled posts failing, backups missing, emails not going out, this is where the story starts.

Use this list to:

- See which hooks are failing.  
- Work out which plugin owns each hook.  
- Decide where to focus your debugging.

### Suspicious repeating jobs

Jobs that run every few minutes can quietly eat performance.

This section shows repeating events where the interval is very short, like:

- Every minute  
- Every two minutes  
- Every five minutes  

Handful of them might be fine. Many of them on a small site will not be.

This is your "who thought this was a good idea" list.

### Orphaned hooks

These are scheduled tasks with no code attached.

WordPress cron knows:

- "Run hook X at time Y"  

But there is no callback registered for hook X any more. That usually means:

- A plugin scheduled jobs while it was active  
- Then got deactivated or deleted  
- And left its cron entries behind  

These are not actively harmful, but they indicate past sloppiness. They also clutter the cron table.

## How to use it when something is broken

Realistic scenario:

- Scheduled posts not publishing.  
- Backup plugin never runs.  
- Email digests do not send when they should.

Use the plugin like this.

1. Open Cron Reality Check.  
2. Look at the health score and messages.  
3. If cron is disabled, fix that first.  
4. If there are overdue events:
   - Note the hooks that keep appearing.  
   - Find which plugins those hooks belong to.  
   - Check those plugins for settings or known issues.  
5. If there are lots of heavy repeating jobs:
   - Decide whether any of them can run less often.  
   - Reconfigure or replace the plugin that scheduled them.  

If the score is low, the overdue list is full and cron is disabled, the conclusion is simple. Scheduled tasks were never going to run properly.

## Things to be careful about

Some predictable foot guns.

### Do not assume zero events means healthy

If the snapshot summary says "zero events":

- Either nothing is scheduled.  
- Or something wiped your cron array.  

A simple blog might genuinely have almost nothing scheduled. A complex site with zero events is suspicious.

### Do not delete hooks blindly

This plugin does not offer a delete button for a reason.

If you decide to clean cron with another tool:

- Back up first.  
- Only remove events when you know which plugin they belong to.  
- Remember that some hooks might be used by multiple plugins.

### Do not ignore `DISABLE_WP_CRON`

If that constant is set to true and nobody has configured a real server cron, you are running a site with dead scheduling.

Everything that depends on cron will silently fail.

### Short intervals are not always evil

A job that runs every minute might be fine on a high traffic site with plenty of resources.

It might also be overkill on a tiny shared host that barely copes with traffic.

Use judgement, not just the table.

## Simple mental shortcuts

If your brain is tired, remember this:

- Red health score, many overdue events = cron is behind.  
- Disabled cron constant set and no server cron = cron is dead.  
- Lots of heavy repeating jobs = potential performance drag.  
- Orphaned hooks = plugin clean up was lazy.  

Treat this plugin as a radar screen, not a weapon.

## When not to use this

You do not need to live in this screen. It is not a dashboard.

Use it when:

- Scheduled posts fail.  
- Background tasks go missing.  
- A plugin that relies on cron misbehaves.  

Then go back to ignoring cron like everyone else until the next incident.

## Final thought

If WordPress had a decent built in cron inspector, this plugin would not need to exist.

It does not. So you now have this.

Use it to understand the mess before you start randomly deleting hooks and blaming plugins that are probably innocent.
