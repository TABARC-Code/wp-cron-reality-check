<?php
/**
 * Plugin Name: WP Cron Reality Check
 * Plugin URI: https://github.com/TABARC-Code/wp-cron-reality-check
 * Description: Looks at what WordPress cron says it is doing, compares it to reality, and shows where things are late, stuck or orphaned.
 * Version: 1.0.1.8
 * Author: TABARC-Code
 * Author URI: https://github.com/TABARC-Code
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2025 TABARC-Code
 * Original work by TABARC-Code.
 * You may modify and redistribute this software under the terms of the
 * GNU General Public License version 3 or (at your option) any later version.
 * Keep this notice and be honest about your changes.
 *
 * Why this exists:
 * WordPress cron is not a real cron. It runs when someone visits the site,
 * which is adorable until you realise there was no visitor at 3am when your
 * scheduled job was meant to fire. Then it lies quietly in the options table
 * and pretends everything is fine.
 *
 * This plugin does not fix cron. It just shows:
 * - Which events are overdue.
 * - Which events are scheduled in the past.
 * - Which repeating events are suspiciously heavy.
 * - Which events have no callbacks attached any more.
 * - Whether WP Cron is disabled or misconfigured.
 * - A small, judgmental "health score" for cron.
 *
 * No automatic rescheduling. No forced runs. This is diagnosis, not surgery.
 *
 * TODO: add optional test spawn that calls wp-cron.php and reports status.
 * TODO: add simple CSV export of the cron table.
 * FIXME: massive sites with thousands of cron entries may need pagination.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Cron_Reality_Check' ) ) {

    class WP_Cron_Reality_Check {

        private $screen_slug = 'wp-cron-reality-check';

        // Grace period before I call something overdue, in seconds.
        private $overdue_grace = 60;

        // Thresholds for "heavy" repeating jobs.
        private $heavy_repeat_threshold = 300; // seconds between runs.

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
            add_action( 'admin_head-plugins.php', array( $this, 'inject_plugin_list_icon_css' ) );
        }

        /**
         * Shared icon path pattern I use for all my plugins.
         */
        private function get_brand_icon_url() {
            return plugin_dir_url( __FILE__ ) . '.branding/tabarc-icon.svg';
        }

        public function add_tools_page() {
            add_management_page(
                __( 'Cron Reality Check', 'wp-cron-reality-check' ),
                __( 'Cron Reality Check', 'wp-cron-reality-check' ),
                'manage_options',
                $this->screen_slug,
                array( $this, 'render_tools_page' )
            );
        }

        public function render_tools_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-cron-reality-check' ) );
            }

            require_once ABSPATH . 'wp-admin/includes/cron.php';

            $snapshot   = $this->get_cron_snapshot();
            $classified = $this->classify_events( $snapshot );
            $lock_info  = $this->get_cron_lock_info();
            $health     = $this->compute_health_score( $snapshot, $classified, $lock_info );

            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'WP Cron Reality Check', 'wp-cron-reality-check' ); ?></h1>
                <p>
                    WordPress cron is that colleague who says "yeah, I did it" and you know they did not.
                    This screen shows what cron thinks it is doing and where it is clearly lying or struggling.
                </p>

                <?php $this->render_health_summary( $health ); ?>

                <h2><?php esc_html_e( 'Configuration and locks', 'wp-cron-reality-check' ); ?></h2>
                <?php $this->render_config_and_locks( $lock_info ); ?>

                <h2><?php esc_html_e( 'Overdue and past scheduled events', 'wp-cron-reality-check' ); ?></h2>
                <p>
                    These events have scheduled times that are already in the past. Some are only just late. Some have been sitting
                    there for a while. All of them are worth looking at if things are not firing when they should.
                </p>
                <?php $this->render_overdue_table( $classified['overdue'] ); ?>

                <h2><?php esc_html_e( 'Suspicious repeating events', 'wp-cron-reality-check' ); ?></h2>
                <p>
                    Repeating jobs with very short intervals can quietly hammer your site. Especially when there are many of them.
                    This list shows repeating events that run more often than every
                    <?php echo esc_html( $this->format_interval( $this->heavy_repeat_threshold ) ); ?>.
                </p>
                <?php $this->render_heavy_repeating_table( $classified['heavy_repeating'] ); ?>

                <h2><?php esc_html_e( 'Orphaned cron hooks', 'wp-cron-reality-check' ); ?></h2>
                <p>
                    These events are scheduled in cron, but there are currently no callbacks attached to their hooks.
                    That usually means a plugin was deactivated or removed without unscheduling its events.
                </p>
                <?php $this->render_orphaned_hooks_table( $classified['orphaned_hooks'] ); ?>

                <h2><?php esc_html_e( 'Raw snapshot summary', 'wp-cron-reality-check' ); ?></h2>
                <p>
                    High level numbers. If you see thousands of scheduled events on a tiny site, you know something got carried away.
                </p>
                <?php $this->render_snapshot_summary( $snapshot ); ?>
            </div>
            <?php
        }

        /**
         * Take a snapshot of the current cron array and flatten it into something consumable.
         */
        private function get_cron_snapshot() {
            $cron = _get_cron_array();
            $schedules = wp_get_schedules();

            $now_gmt = current_time( 'timestamp', true );

            $events = array();
            $hook_counts = array();

            if ( ! is_array( $cron ) ) {
                $cron = array();
            }

            foreach ( $cron as $timestamp => $hooks ) {
                foreach ( $hooks as $hook => $instances ) {
                    foreach ( $instances as $key => $details ) {
                        $schedule_name = isset( $details['schedule'] ) ? $details['schedule'] : '';
                        $interval = null;

                        if ( $schedule_name && isset( $schedules[ $schedule_name ]['interval'] ) ) {
                            $interval = (int) $schedules[ $schedule_name ]['interval'];
                        }

                        $events[] = array(
                            'timestamp'     => (int) $timestamp,
                            'hook'          => $hook,
                            'args'          => isset( $details['args'] ) ? $details['args'] : array(),
                            'schedule'      => $schedule_name,
                            'interval'      => $interval,
                            'now_gmt'       => $now_gmt,
                        );

                        if ( ! isset( $hook_counts[ $hook ] ) ) {
                            $hook_counts[ $hook ] = 0;
                        }
                        $hook_counts[ $hook ]++;
                    }
                }
            }

            return array(
                'events'      => $events,
                'hook_counts' => $hook_counts,
                'total'       => count( $events ),
            );
        }

        /**
         * Classify events into:
         * - overdue
         * - heavy repeating
         * - orphaned hooks (cron entries with no attached callbacks)
         */
        private function classify_events( $snapshot ) {
            global $wp_filter;

            $events = $snapshot['events'];

            $overdue         = array();
            $heavy_repeating = array();
            $hooks_seen      = array();

            foreach ( $events as $event ) {
                $ts   = $event['timestamp'];
                $now  = $event['now_gmt'];

                // Overdue events.
                if ( $ts + $this->overdue_grace < $now ) {
                    $age = $now - $ts;
                    $event['age'] = $age;
                    $overdue[] = $event;
                }

                // Heavy repeating jobs.
                if ( $event['schedule'] && $event['interval'] !== null && $event['interval'] > 0 && $event['interval'] <= $this->heavy_repeat_threshold ) {
                    $heavy_repeating[] = $event;
                }

                $hooks_seen[ $event['hook'] ] = true;
            }

            // Orphaned hooks: hooks that exist in cron but have no callbacks registered.
            $orphaned_hooks = array();
            foreach ( array_keys( $hooks_seen ) as $hook ) {
                if ( empty( $wp_filter[ $hook ] ) ) {
                    $orphaned_hooks[] = $hook;
                }
            }

            // Sort overdue events by age, most overdue first.
            usort(
                $overdue,
                function ( $a, $b ) {
                    return ( $b['age'] ?? 0 ) <=> ( $a['age'] ?? 0 );
                }
            );

            // Group heavy repeating by hook for nicer display.
            $heavy_grouped = array();
            foreach ( $heavy_repeating as $ev ) {
                $hook = $ev['hook'];
                if ( ! isset( $heavy_grouped[ $hook ] ) ) {
                    $heavy_grouped[ $hook ] = array(
                        'hook'      => $hook,
                        'schedule'  => $ev['schedule'],
                        'interval'  => $ev['interval'],
                        'examples'  => array(),
                    );
                }

                if ( count( $heavy_grouped[ $hook ]['examples'] ) < 3 ) {
                    $heavy_grouped[ $hook ]['examples'][] = $ev;
                }
            }

            return array(
                'overdue'         => $overdue,
                'heavy_repeating' => $heavy_grouped,
                'orphaned_hooks'  => $orphaned_hooks,
            );
        }

        /**
         * Gather information about cron lock and configuration flags.
         */
        private function get_cron_lock_info() {
            $lock_raw = get_option( 'cron_lock', false );
            $lock = array(
                'raw'       => $lock_raw,
                'timestamp' => null,
                'server'    => null,
                'stale'     => false,
            );

            if ( is_array( $lock_raw ) && isset( $lock_raw[0] ) ) {
                $lock['timestamp'] = (float) $lock_raw[0];
                $lock['server']    = isset( $lock_raw[1] ) ? $lock_raw[1] : null;

                // WordPress uses a 60 second default for lock expiry.
                if ( $lock['timestamp'] > 0 ) {
                    $age = microtime( true ) - $lock['timestamp'];
                    if ( $age > 60 ) {
                        $lock['stale'] = true;
                    }
                }
            }

            $config = array(
                'DISABLE_WP_CRON'   => defined( 'DISABLE_WP_CRON' ) ? ( DISABLE_WP_CRON ? true : false ) : false,
                'ALTERNATE_CRON'    => defined( 'ALTERNATE_WP_CRON' ) ? ( ALTERNATE_WP_CRON ? true : false ) : false,
                'wp_cron_spawnable' => null,
            );

            // Very gentle spawn test: just see if wp-cron.php looks present and reachable locally.
            $cron_url = site_url( 'wp-cron.php' );
            $config['cron_url'] = $cron_url;

            return array(
                'lock'   => $lock,
                'config' => $config,
            );
        }

        /**
         * Compute a cheap "health score" and some comments based on the snapshot.
         */
        private function compute_health_score( $snapshot, $classified, $lock_info ) {
            $score     = 100;
            $messages  = array();
            $severity  = 'good';

            $total_events   = $snapshot['total'];
            $overdue_count  = count( $classified['overdue'] );
            $heavy_count    = count( $classified['heavy_repeating'] );
            $orphaned_count = count( $classified['orphaned_hooks'] );

            // Disabled cron is an immediate heavy hit.
            if ( ! empty( $lock_info['config']['DISABLE_WP_CRON'] ) ) {
                $score -= 50;
                $messages[] = 'WP Cron appears to be disabled via DISABLE_WP_CRON. If you do not have a real server cron calling wp-cron.php, scheduled tasks will not run.';
            }

            if ( $overdue_count > 0 ) {
                $score -= min( 40, $overdue_count * 2 );
                $messages[] = sprintf(
                    _n(
                        'There is %d overdue cron event. Something is running late.',
                        'There are %d overdue cron events. Something is badly behind.',
                        $overdue_count,
                        'wp-cron-reality-check'
                    ),
                    $overdue_count
                );
            }

            if ( $heavy_count > 0 ) {
                $score -= min( 20, $heavy_count * 2 );
                $messages[] = sprintf(
                    _n(
                        'There is %d repeating job with a very short interval. This can add constant load.',
                        'There are %d repeating jobs with very short intervals. This can quietly chew CPU.',
                        $heavy_count,
                        'wp-cron-reality-check'
                    ),
                    $heavy_count
                );
            }

            if ( $orphaned_count > 0 ) {
                $score -= min( 15, $orphaned_count );
                $messages[] = sprintf(
                    _n(
                        'There is %d cron hook with no callbacks attached. Likely from an old plugin.',
                        'There are %d cron hooks with no callbacks attached. Likely leftovers from old plugins.',
                        $orphaned_count,
                        'wp-cron-reality-check'
                    ),
                    $orphaned_count
                );
            }

            if ( $total_events > 500 ) {
                $score -= 10;
                $messages[] = 'There are more than 500 scheduled cron events. This might be normal on a busy site, or it might be a plugin leaking jobs.';
            }

            if ( $total_events === 0 ) {
                $messages[] = 'There are no cron events scheduled at all. Either this is a very simple site, or something wiped the cron array.';
            }

            if ( $score >= 80 ) {
                $severity = 'good';
                if ( empty( $messages ) ) {
                    $messages[] = 'Cron looks reasonably healthy. No obvious disasters detected.';
                }
            } elseif ( $score >= 50 ) {
                $severity = 'warning';
                $messages[] = 'Cron is limping. Nothing is on fire yet, but there are things to fix.';
            } else {
                $severity = 'critical';
                $messages[] = 'Cron health is poor. Expect missed scheduled posts and background tasks.';
            }

            if ( $score < 0 ) {
                $score = 0;
            }

            return array(
                'score'    => $score,
                'severity' => $severity,
                'messages' => $messages,
                'counts'   => array(
                    'total'      => $total_events,
                    'overdue'    => $overdue_count,
                    'heavy'      => $heavy_count,
                    'orphaned'   => $orphaned_count,
                ),
            );
        }

        private function render_health_summary( $health ) {
            $score    = (int) $health['score'];
            $severity = $health['severity'];
            $messages = $health['messages'];

            $colour = '#46b450';
            if ( $severity === 'warning' ) {
                $colour = '#ffb900';
            } elseif ( $severity === 'critical' ) {
                $colour = '#dc3232';
            }

            ?>
            <div style="border-left:4px solid <?php echo esc_attr( $colour ); ?>;padding:12px 16px;margin:16px 0;background:#fff;">
                <p>
                    <strong>Cron health score:</strong>
                    <span style="font-size:18px;font-weight:bold;margin-left:4px;"><?php echo esc_html( $score ); ?>/100</span>
                </p>
                <ul>
                    <?php foreach ( $messages as $msg ) : ?>
                        <li><?php echo esc_html( $msg ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }

        private function render_config_and_locks( $lock_info ) {
            $lock   = $lock_info['lock'];
            $config = $lock_info['config'];

            $disable = ! empty( $config['DISABLE_WP_CRON'] );
            $alt     = ! empty( $config['ALTERNATE_CRON'] );

            ?>
            <table class="widefat striped" style="max-width:800px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'DISABLE_WP_CRON', 'wp-cron-reality-check' ); ?></th>
                        <td>
                            <?php
                            if ( $disable ) {
                                echo '<span style="color:#dc3232;">true</span> ';
                                echo esc_html__( 'WP Cron is disabled in wp-config. You must have a real server cron calling wp-cron.php or scheduled tasks will never run.', 'wp-cron-reality-check' );
                            } else {
                                echo '<span style="color:#46b450;">false</span> ';
                                echo esc_html__( 'WP Cron is enabled. It will attempt to fire on page loads.', 'wp-cron-reality-check' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'ALTERNATE_WP_CRON', 'wp-cron-reality-check' ); ?></th>
                        <td>
                            <?php
                            if ( $alt ) {
                                echo '<span style="color:#ffb900;">true</span> ';
                                echo esc_html__( 'Alternate cron is enabled. This is a fallback mode used when normal spawn attempts fail.', 'wp-cron-reality-check' );
                            } else {
                                echo '<span>false</span> ';
                                echo esc_html__( 'Alternate cron is not enabled. This is normal on most setups.', 'wp-cron-reality-check' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Cron lock status', 'wp-cron-reality-check' ); ?></th>
                        <td>
                            <?php
                            if ( empty( $lock['timestamp'] ) ) {
                                echo esc_html__( 'No active cron lock recorded. Either nothing is running right now or the lock has already been cleared.', 'wp-cron-reality-check' );
                            } else {
                                $age = microtime( true ) - $lock['timestamp'];
                                echo esc_html__( 'A cron process claimed the lock', 'wp-cron-reality-check' ) . ' ';
                                echo esc_html( round( $age, 2 ) ) . ' ';
                                echo esc_html__( 'seconds ago.', 'wp-cron-reality-check' ) . ' ';

                                if ( ! empty( $lock['server'] ) ) {
                                    echo 'Server: ' . esc_html( $lock['server'] ) . '. ';
                                }

                                if ( $lock['stale'] ) {
                                    echo '<br><span style="color:#dc3232;">';
                                    echo esc_html__( 'The lock looks stale. If this persists, cron processes may be getting stuck.', 'wp-cron-reality-check' );
                                    echo '</span>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'wp-cron.php URL', 'wp-cron-reality-check' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $config['cron_url'] ); ?></code>
                            <p style="font-size:12px;opacity:0.8;">
                                <?php esc_html_e( 'If you are using a real server cron, this is the URL it should be hitting on a schedule.', 'wp-cron-reality-check' ); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php
        }

        private function render_overdue_table( $overdue ) {
            if ( empty( $overdue ) ) {
                echo '<p>' . esc_html__( 'No overdue events detected within the current snapshot. Either cron is on time, or nothing meaningful is scheduled.', 'wp-cron-reality-check' ) . '</p>';
                return;
            }

            // Do not drown the admin. Show the top 50, most overdue first.
            $slice = array_slice( $overdue, 0, 50 );

            ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Hook', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Scheduled for (UTC)', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Age', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Schedule', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Args (sample)', 'wp-cron-reality-check' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $slice as $event ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $event['hook'] ); ?></code></td>
                        <td>
                            <?php
                            echo esc_html(
                                gmdate(
                                    get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                                    $event['timestamp']
                                )
                            );
                            ?>
                        </td>
                        <td><?php echo esc_html( $this->format_interval( $event['age'] ) ) . ' ' . esc_html__( 'late', 'wp-cron-reality-check' ); ?></td>
                        <td>
                            <?php
                            if ( $event['schedule'] ) {
                                echo '<code>' . esc_html( $event['schedule'] ) . '</code>';
                                if ( $event['interval'] ) {
                                    echo '<br><span style="font-size:12px;opacity:0.7;">' .
                                        esc_html( $this->format_interval( $event['interval'] ) ) .
                                        esc_html__( ' between runs', 'wp-cron-reality-check' ) .
                                        '</span>';
                                }
                            } else {
                                esc_html_e( 'Single run', 'wp-cron-reality-check' );
                            }
                            ?>
                        </td>
                        <td>
                            <code>
                                <?php
                                if ( ! empty( $event['args'] ) ) {
                                    echo esc_html( wp_json_encode( $event['args'] ) );
                                } else {
                                    esc_html_e( 'none', 'wp-cron-reality-check' );
                                }
                                ?>
                            </code>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:12px;opacity:0.8;">
                <?php esc_html_e( 'Only the most overdue events are shown here. If this list is full, that is your sign cron is badly behind.', 'wp-cron-reality-check' ); ?>
            </p>
            <?php
        }

        private function render_heavy_repeating_table( $heavy_grouped ) {
            if ( empty( $heavy_grouped ) ) {
                echo '<p>' . esc_html__( 'No repeating events with very short intervals were detected based on the current schedules.', 'wp-cron-reality-check' ) . '</p>';
                return;
            }

            ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Hook', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Schedule', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Interval', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Examples', 'wp-cron-reality-check' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $heavy_grouped as $hook => $data ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $hook ); ?></code></td>
                        <td><code><?php echo esc_html( $data['schedule'] ); ?></code></td>
                        <td><?php echo esc_html( $this->format_interval( $data['interval'] ) ); ?></td>
                        <td>
                            <?php foreach ( $data['examples'] as $ev ) : ?>
                                <div style="font-size:12px;margin-bottom:4px;">
                                    <?php
                                    echo esc_html(
                                        gmdate(
                                            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                                            $ev['timestamp']
                                        )
                                    );
                                    ?>
                                    <?php if ( ! empty( $ev['args'] ) ) : ?>
                                        <br><code><?php echo esc_html( wp_json_encode( $ev['args'] ) ); ?></code>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:12px;opacity:0.8;">
                <?php esc_html_e( 'Short interval jobs are not always bad, but too many of them can add invisible load. Especially on low traffic sites.', 'wp-cron-reality-check' ); ?>
            </p>
            <?php
        }

        private function render_orphaned_hooks_table( $hooks ) {
            if ( empty( $hooks ) ) {
                echo '<p>' . esc_html__( 'No orphaned cron hooks detected. Either plugins cleaned up after themselves, or you have not deactivated anything recently.', 'wp-cron-reality-check' ) . '</p>';
                return;
            }

            ?>
            <table class="widefat striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Hook', 'wp-cron-reality-check' ); ?></th>
                        <th><?php esc_html_e( 'Notes', 'wp-cron-reality-check' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $hooks as $hook ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $hook ); ?></code></td>
                        <td>
                            <?php esc_html_e( 'This hook has cron events scheduled but there are no callbacks currently attached to it. Likely left behind by a plugin that was deactivated or removed.', 'wp-cron-reality-check' ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:12px;opacity:0.8;">
                <?php esc_html_e( 'If you recognise a hook as coming from a plugin you no longer use, it may be safe to clear those events with a more advanced cron manager.', 'wp-cron-reality-check' ); ?>
            </p>
            <?php
        }

        private function render_snapshot_summary( $snapshot ) {
            $total = (int) $snapshot['total'];
            $hooks = $snapshot['hook_counts'];

            arsort( $hooks );

            ?>
            <table class="widefat striped" style="max-width:800px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Total scheduled events', 'wp-cron-reality-check' ); ?></th>
                        <td><?php echo esc_html( $total ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Most used hooks', 'wp-cron-reality-check' ); ?></th>
                        <td>
                            <?php
                            if ( empty( $hooks ) ) {
                                esc_html_e( 'No cron events registered.', 'wp-cron-reality-check' );
                            } else {
                                $i = 0;
                                foreach ( $hooks as $hook => $count ) {
                                    $i++;
                                    echo '<code>' . esc_html( $hook ) . '</code> (' . (int) $count . ')';
                                    if ( $i >= 10 ) {
                                        break;
                                    }
                                    echo '<br>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php
        }

        private function format_interval( $seconds ) {
            $seconds = (int) $seconds;

            if ( $seconds < 60 ) {
                return $seconds . ' s';
            }

            $minutes = floor( $seconds / 60 );
            if ( $minutes < 60 ) {
                return $minutes . ' min';
            }

            $hours = floor( $minutes / 60 );
            if ( $hours < 24 ) {
                return $hours . ' h';
            }

            $days = floor( $hours / 24 );
            return $days . ' days';
        }

        public function inject_plugin_list_icon_css() {
            $icon_url = esc_url( $this->get_brand_icon_url() );
            ?>
            <style>
                .wp-list-table.plugins tr[data-slug="wp-cron-reality-check"] .plugin-title strong::before {
                    content: '';
                    display: inline-block;
                    vertical-align: middle;
                    width: 18px;
                    height: 18px;
                    margin-right: 6px;
                    background-image: url('<?php echo $icon_url; ?>');
                    background-repeat: no-repeat;
                    background-size: contain;
                }
            </style>
            <?php
        }
    }

    new WP_Cron_Reality_Check();
}
