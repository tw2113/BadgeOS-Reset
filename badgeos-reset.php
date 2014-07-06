<?php
/**
 * Plugin Name: BadgeOS Reset Developer Add-On
 * Plugin URI: http://michaelbox.net
 * Description: This BadgeOS add-on adds support to reset BadgeOS data for developer purpose
 * Author: Michae; Beckwith
 * Version: 1.0.0
 * Author URI: http://michaelbox.net
 * License: GNU AGPLv3
 * License URI: http://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: badgeos-reset
 */

/*
 * Copyright © 2014 Michael Beckwith
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General
 * Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>;.
*/

/**
 * Our main plugin instantiation class
 *
 * @since 1.0.0
 */
class BadgeOS_Reset {

	public $basename = '';
	public $directory_path = '';
	public $directory_uri = '';
	public $achievement_types = array();
	public $achievement_ids = array();
	public $attachment_ids = array();


	/**
	 * Get everything running.
	 *
	 * @since 1.0.0
	 */
	function __construct() {

		// Define plugin constants
		$this->basename       = plugin_basename( __FILE__ );
		$this->directory_path = plugin_dir_path( __FILE__ );
		$this->directory_url  = plugins_url( dirname( $this->basename ) );

		// Load translations
		load_plugin_textdomain( 'badgeos-reset', false, dirname( $this->basename ) . '/languages' );

		add_action( 'admin_notices', array( $this, 'maybe_disable_plugin' ) );
		add_action( 'badgeos_settings', array( $this, 'settings_page' ) );
		add_action( 'admin_init', array( $this, 'handle_reset' ) );

	}

	/**
	 * Run our methods to delete all of our BadgeOS data, if user has checked to.
	 *
	 * @since 1.0.0
	 */
	public function handle_reset() {

		if ( empty( $_POST ) || '1' !== $_POST['badgeos_reset'] ) {
			return;
		}

		$this->set_achievements();
		$this->set_achievement_type_ids();
		$this->set_attachment_ids();

		$this->reset_achievement_meta();
		$this->reset_achievements_and_attachments();
		$this->reset_users();
		$this->reset_options();
		$this->reset_p2p();
	}

	/**
	 * Set our achievement types property for all registered achievement types and core types
	 *
	 * @since 1.0.0
	 */
	public function set_achievements() {
		$achievement_types = badgeos_get_achievement_types_slugs();
		$achievement_types[] = 'achievement-type';
		$achievement_types[] = 'badgeos-log-entry';

		$this->achievement_types = $achievement_types;
	}

	/**
	 * Query for our achievement post IDs based on registered achievement types.
	 *
	 * @since 1.0.0
	 */
	public function set_achievement_type_ids() {
		global $wpdb;

		$sql = "SELECT ID AS ID FROM {$wpdb->posts} WHERE post_type = %s";
		$achievement_ids = array();
		foreach( $this->achievement_types as $type ) {
			$result_ids = $wpdb->get_results(
				$wpdb->prepare(
					$sql,
					$type
				)
			);

			$achievement_ids = $this->construct_array_of_ids( $result_ids );
		}

		$this->achievement_ids = $achievement_ids;
	}

	/**
	 * Query for our attachment IDs based on having a parent that's an achievement post or post type.
	 *
	 * @since 1.0.0
	 */
	public function set_attachment_ids() {
		global $wpdb;

		$sql = "SELECT ID AS ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d";
		$attachment_ids = array();
		foreach( $this->achievement_ids as $id ) {
			$attachments_results_ids = $wpdb->get_results(
				$wpdb->prepare(
					$sql,
					'attachment',
					$id
				)
			);

			$attachment_ids = $this->construct_array_of_ids( $attachments_results_ids );
		}

		$this->attachment_ids = $attachment_ids;
	}

	/**
	 * Filter down our WPDB results to an indexed array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $results Results from a $wpdb get_results call.
	 *
	 * @return array Indexed array of IDs for posts.
	 */
	public function construct_array_of_ids( $results = array() ) {
		$ids = array();

		if ( empty( $results ) ) {
			return $ids;
		}

		foreach( $results as $result ) {
			$ids[] = absint( $result->ID );
		}

		return $ids;
	}

	/**
	 * Run SQL DELETE statement on the User Meta table.
	 *
	 * @since 1.0.0
	 */
	public function reset_users() {
		global $wpdb;

		//We have two types of keys stored for a user, so we need to handle both.
		$badgeos_keys = array( 'credly', '_badgeos' );
		$sql = "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE \"%s\"";

		foreach( $badgeos_keys as $key ) {
			$wpdb->query(
				$wpdb->prepare(
					$sql,
					$key . '%'
				)
			);
		}
	}

	/**
	 * Run SQL statement on the Post Meta table
	 *
	 * @since 1.0.0
	 */
	public function reset_achievement_meta() {
		global $wpdb;

		$sql = "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE \"%s\"";

		$wpdb->query(
			$wpdb->prepare(
				$sql,
				'%badgeos%'
			)
		);

	}

	/**
	 * Query for our post type posts and associated attachments.
	 *
	 * Uses WordPress core API functions for complete deletion of asscoiated data instead of simply deleting database rows.
	 *
	 * @since 1.0.0
	 */
	private function reset_achievement_types() {
		global $wpdb;

		$achievement_types = badgeos_get_achievement_types_slugs();
		$achievement_types[] = 'achievement-type';
		$achievement_types[] = 'badgeos-log-entry';

		//Post Types
		$sql = "SELECT ID AS ID FROM {$wpdb->posts} WHERE post_type = %s";
		$achievement_ids = array();
		foreach( $achievement_types as $type ) {
			$result_ids = $wpdb->get_results(
				$wpdb->prepare(
					$sql,
					$type
				)
			);

			if ( !empty( $result_ids ) ) {
				foreach( $result_ids as $value ) {
					$achievement_ids[] = absint( $value->ID );
				}
			}
		}

		//Attachments/media
		$sql = "SELECT ID AS ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d";
		$attachment_ids = array();
		foreach( $achievement_ids as $id ) {
			$attachments_results_ids = $wpdb->get_results(
				$wpdb->prepare(
					$sql,
					'attachment',
					$id
				)
			);
			if ( !empty( $attachments_results_ids ) ) {
				foreach( $attachments_results_ids as $value ) {
					$attachment_ids[] = absint( $value->ID );
				}
			}
		}

		foreach( $achievement_ids as $achievement ) {
			wp_delete_post( $achievement );
		}

		foreach( $attachment_ids as $attachment ) {
			wp_delete_attachment( $attachment );
		}
	}

	/**
	 * Run SQL DELETE statement on WordPress options table.
	 *
	 * @since 1.0.0
	 */
	private function reset_options() {
		global $wpdb;

		$sql = "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%s\"";

		$wpdb->query(
			$wpdb->prepare(
				$sql,
				'%badgeos%'
			)
		);

	}

	/**
	 * Run SQL TRUNCATE statement on our p2p tables.
	 * Truncate drops the table and then rebuilds it as new
	 *
	 * @since 1.0.0
	 */
	private function reset_p2p() {
		return;

		//@todo Determine how to best delete only BadgeOS content from these tables.
		global $wpdb;

	}

	/**
	 * Adds additional options to the BadgeOS Settings page
	 *
	 * @since 1.0.0
	 */
	public function settings_page() {

	?>
		<tr>
			<th scope="row">
				<?php _e( 'Reset all BadgeOS data: ', 'badgeos-reset' ); ?>
			</th>
			<td>
				<label for="badgeos_reset">
					<input type="checkbox" name="badgeos_reset" id="badgeos_reset" value="1" />
					<?php _e( 'WARNING: This will delete ALL your BadgeOS data*', 'badgeos-reset' ); ?>
				</label>
					<p><small><?php _e( '*As much as we can accurately detect', 'badgeos-reset' ); ?></small></p>
			</td>
		</tr>
	<?php
	}

	/**
	 * Check if BadgeOS is available
	 *
	 * @since  1.0.0
	 *
	 * @return bool True if BadgeOS is available, false otherwise
	 */
	public static function meets_requirements() {

		if ( class_exists('BadgeOS') )
			return true;
		else
			return false;

	}

	/**
	 * Potentially output a custom error message and deactivate
	 * this plugin, if we don't meet requriements.
	 *
	 * This fires on admin_notices.
	 *
	 * @since 1.0.0
	 */
	public function maybe_disable_plugin() {

		if ( ! $this->meets_requirements() ) {
			// Display our error
			?>
			<div id="message" class="error">
			<p><?php printf( __( 'BadgeOS Reset requires BadgeOS and has been <a href="%s">deactivated</a>. Please install and activate BadgeOS and then reactivate this plugin.', 'badgeos-reset' ), admin_url( 'plugins.php' ) ); ?></p>
			</div>

			<?php
			// Deactivate our plugin
			deactivate_plugins( $this->basename );
		}

	}

}

$gameover = new BadgeOS_Reset();
