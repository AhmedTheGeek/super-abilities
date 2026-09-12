<?php
/**
 * Settings screen.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Admin;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Module_Interface;
use SuperAbilities\Options;
use SuperAbilities\Plugin;
use SuperAbilities\Support\Time;
use SuperAbilities\Support\Version;

defined( 'ABSPATH' ) || exit;

/**
 * Renders Settings, Super Abilities.
 *
 * @since 0.1.0
 */
class Settings_Page {

	/**
	 * Menu slug.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PAGE = 'super-abilities';

	/**
	 * Composition root.
	 *
	 * @since 0.1.0
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Screen hook suffix returned by `add_options_page()`.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Plugin $plugin Composition root.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the screen into the admin.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the options page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_menu() {
		$hook_suffix = add_options_page(
			__( 'Super Abilities', 'super-abilities' ),
			__( 'Super Abilities', 'super-abilities' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Loads the stylesheet on our screen only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'super-abilities-settings',
			SUPER_ABILITIES_URL . 'assets/admin/settings.css',
			array(),
			SUPER_ABILITIES_VERSION
		);
	}

	/**
	 * Registers the setting, its sections and its fields.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			Options::GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'Super Abilities settings.', 'super-abilities' ),
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'super_abilities_modules',
			__( 'Modules', 'super-abilities' ),
			array( $this, 'render_modules_intro' ),
			self::PAGE
		);

		foreach ( $this->plugin->modules()->all() as $id => $module ) {
			add_settings_field(
				'super_abilities_module_' . $id,
				$this->module_title( $module ),
				array( $this, 'render_module_field' ),
				self::PAGE,
				'super_abilities_modules',
				array( 'module_id' => (string) $id )
			);
		}

		add_settings_section(
			'super_abilities_audit',
			__( 'Audit log', 'super-abilities' ),
			'__return_empty_string',
			self::PAGE
		);

		add_settings_field(
			'audit_retention_days',
			__( 'Retention', 'super-abilities' ),
			array( $this, 'render_number_field' ),
			self::PAGE,
			'super_abilities_audit',
			array(
				'key'         => 'audit_retention_days',
				'min'         => 1,
				'max'         => 3650,
				'unit'        => __( 'days', 'super-abilities' ),
				'description' => __( 'Audit rows older than this are deleted by a daily cron job.', 'super-abilities' ),
			)
		);

		add_settings_field(
			'audit_third_party',
			__( 'Third-party abilities', 'super-abilities' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE,
			'super_abilities_audit',
			array(
				'key'         => 'audit_third_party',
				'label'       => __( 'Also log abilities registered by other plugins', 'super-abilities' ),
				'description' => __( 'Covers core, WooCommerce, Jetpack and any other plugin that registers abilities.', 'super-abilities' ),
			)
		);

		add_settings_field(
			'audit_store_ip',
			__( 'Client IP', 'super-abilities' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE,
			'super_abilities_audit',
			array(
				'key'         => 'audit_store_ip',
				'label'       => __( 'Store the client IP address with each audit row', 'super-abilities' ),
				'description' => __( 'Turn this off if your privacy policy does not allow storing IP addresses.', 'super-abilities' ),
			)
		);

		add_settings_section(
			'super_abilities_jobs',
			__( 'Background jobs', 'super-abilities' ),
			'__return_empty_string',
			self::PAGE
		);

		add_settings_field(
			'jobs_time_budget',
			__( 'Time budget', 'super-abilities' ),
			array( $this, 'render_number_field' ),
			self::PAGE,
			'super_abilities_jobs',
			array(
				'key'         => 'jobs_time_budget',
				'min'         => 5,
				'max'         => 300,
				'unit'        => __( 'seconds', 'super-abilities' ),
				'description' => __( 'How long a single cron run may spend on one job before it reschedules itself.', 'super-abilities' ),
			)
		);

		add_settings_field(
			'jobs_max_items',
			__( 'Maximum items', 'super-abilities' ),
			array( $this, 'render_number_field' ),
			self::PAGE,
			'super_abilities_jobs',
			array(
				'key'         => 'jobs_max_items',
				'min'         => 1,
				'max'         => 5000,
				'unit'        => __( 'items per job', 'super-abilities' ),
				'description' => __( 'Requests that queue more items than this are rejected.', 'super-abilities' ),
			)
		);

		add_settings_field(
			'jobs_retention_days',
			__( 'Retention', 'super-abilities' ),
			array( $this, 'render_number_field' ),
			self::PAGE,
			'super_abilities_jobs',
			array(
				'key'         => 'jobs_retention_days',
				'min'         => 1,
				'max'         => 3650,
				'unit'        => __( 'days', 'super-abilities' ),
				'description' => __( 'Finished jobs and their items are deleted after this many days.', 'super-abilities' ),
			)
		);

		add_settings_section(
			'super_abilities_extensions',
			__( 'Plugins and themes', 'super-abilities' ),
			'__return_empty_string',
			self::PAGE
		);

		add_settings_field(
			'extensions_allow_zip_url',
			__( 'ZIP URLs', 'super-abilities' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE,
			'super_abilities_extensions',
			array(
				'key'         => 'extensions_allow_zip_url',
				'label'       => __( 'Allow installing from a ZIP URL', 'super-abilities' ),
				'description' => __( 'Without this, only plugins and themes from WordPress.org can be installed.', 'super-abilities' ),
			)
		);

		add_settings_field(
			'extensions_zip_hosts',
			__( 'Allowed ZIP hosts', 'super-abilities' ),
			array( $this, 'render_hosts_field' ),
			self::PAGE,
			'super_abilities_extensions',
			array( 'key' => 'extensions_zip_hosts' )
		);

		add_settings_section(
			'super_abilities_media',
			__( 'Media', 'super-abilities' ),
			'__return_empty_string',
			self::PAGE
		);

		add_settings_field(
			'media_import_max_bytes',
			__( 'Import size limit', 'super-abilities' ),
			array( $this, 'render_number_field' ),
			self::PAGE,
			'super_abilities_media',
			array(
				'key'         => 'media_import_max_bytes',
				'min'         => 1024,
				'max'         => 536870912,
				'unit'        => __( 'bytes', 'super-abilities' ),
				'description' => __( 'Largest file that may be downloaded when importing media from a URL.', 'super-abilities' ),
			)
		);

		add_settings_section(
			'super_abilities_uninstall',
			__( 'Uninstall', 'super-abilities' ),
			'__return_empty_string',
			self::PAGE
		);

		add_settings_field(
			'delete_data_on_uninstall',
			__( 'Delete data', 'super-abilities' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE,
			'super_abilities_uninstall',
			array(
				'key'         => 'delete_data_on_uninstall',
				'label'       => __( 'Delete all plugin tables, options and scheduled events when the plugin is deleted', 'super-abilities' ),
				'description' => __( 'Deactivating the plugin never deletes anything. This only applies when the plugin is deleted.', 'super-abilities' ),
			)
		);
	}

	/**
	 * Renders the settings screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap super-abilities-settings">
			<h1><?php echo esc_html__( 'Super Abilities', 'super-abilities' ); ?></h1>
			<p class="super-abilities-lede">
				<?php echo esc_html__( 'Abilities are gated by WordPress capabilities and recorded in the audit log. Turn a module off to remove its abilities from the registry entirely.', 'super-abilities' ); ?>
			</p>

			<?php $this->render_status_box(); ?>

			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( Options::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the read-only status panel.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected function render_status_box() {
		$rows = array(
			__( 'WordPress version', 'super-abilities' )   => Version::wp(),
			__( 'Abilities API level', 'super-abilities' ) => Version::abilities_api_level(),
			__( 'Abilities registered', 'super-abilities' ) => (string) number_format_i18n( $this->count_registered_abilities() ),
			__( 'Cron heartbeat', 'super-abilities' )      => $this->heartbeat_label(),
			__( 'REST base', 'super-abilities' )           => rest_url( 'wp-abilities/v1' ),
		);

		?>
		<div class="super-abilities-status">
			<h2><?php echo esc_html__( 'Status', 'super-abilities' ); ?></h2>
			<table class="super-abilities-status-table">
				<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><code><?php echo esc_html( $value ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Explains the module toggles.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_modules_intro() {
		echo '<p class="description">';
		echo esc_html__( 'Each module registers its own ability category. Higher risk modules are off until you switch them on.', 'super-abilities' );
		echo '</p>';
	}

	/**
	 * Renders one module checkbox with its metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Field arguments with a `module_id` key.
	 * @return void
	 */
	public function render_module_field( $args ) {
		$id     = isset( $args['module_id'] ) ? (string) $args['module_id'] : '';
		$module = $this->plugin->modules()->get( $id );

		if ( ! $module instanceof Module_Interface ) {
			return;
		}

		$abilities = $this->module_abilities( $module );
		$caps      = $this->module_capabilities( $abilities );
		$enabled   = $this->plugin->options()->is_module_enabled( $id );

		printf(
			'<label><input type="checkbox" name="%1$s[modules][%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( Options::OPTION ),
			esc_attr( $id ),
			checked( $enabled, true, false ),
			esc_html__( 'Enabled', 'super-abilities' )
		);

		echo '<p class="description">' . esc_html( $module->description() ) . '</p>';

		echo '<p class="super-abilities-module-meta">';
		printf(
			'<span class="super-abilities-count">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: Number of abilities. */
					_n( '%s ability', '%s abilities', count( $abilities ), 'super-abilities' ),
					number_format_i18n( count( $abilities ) )
				)
			)
		);

		if ( ! empty( $caps ) ) {
			echo ' <span class="super-abilities-caps">' . esc_html__( 'Requires:', 'super-abilities' ) . ' ';

			foreach ( $caps as $cap ) {
				echo '<code>' . esc_html( $cap ) . '</code> ';
			}

			echo '</span>';
		}
		echo '</p>';
	}

	/**
	 * Renders a number input bound to a setting.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_number_field( $args ) {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';

		if ( '' === $key ) {
			return;
		}

		printf(
			'<input type="number" class="small-text" name="%1$s[%2$s]" id="%2$s" value="%3$s" min="%4$s" max="%5$s" step="1" />',
			esc_attr( Options::OPTION ),
			esc_attr( $key ),
			esc_attr( (string) $this->plugin->options()->get( $key, 0 ) ),
			esc_attr( (string) ( isset( $args['min'] ) ? $args['min'] : 0 ) ),
			esc_attr( (string) ( isset( $args['max'] ) ? $args['max'] : 0 ) )
		);

		if ( ! empty( $args['unit'] ) ) {
			echo ' <span class="super-abilities-unit">' . esc_html( (string) $args['unit'] ) . '</span>';
		}

		$this->render_description( $args );
	}

	/**
	 * Renders a checkbox bound to a boolean setting.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_checkbox_field( $args ) {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';

		if ( '' === $key ) {
			return;
		}

		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" id="%2$s" value="1" %3$s /> %4$s</label>',
			esc_attr( Options::OPTION ),
			esc_attr( $key ),
			checked( (bool) $this->plugin->options()->get( $key, false ), true, false ),
			esc_html( isset( $args['label'] ) ? (string) $args['label'] : '' )
		);

		$this->render_description( $args );
	}

	/**
	 * Renders the ZIP host allowlist textarea.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_hosts_field( $args ) {
		$key   = isset( $args['key'] ) ? (string) $args['key'] : 'extensions_zip_hosts';
		$hosts = (array) $this->plugin->options()->get( $key, array() );

		printf(
			'<textarea name="%1$s[%2$s]" id="%2$s" rows="4" class="large-text code" placeholder="%3$s">%4$s</textarea>',
			esc_attr( Options::OPTION ),
			esc_attr( $key ),
			esc_attr__( 'downloads.example.com', 'super-abilities' ),
			esc_textarea( implode( "\n", array_map( 'strval', $hosts ) ) )
		);

		echo '<p class="description">' . esc_html__( 'One host per line. Only these hosts may be used as a ZIP source. Leave empty to block every ZIP URL.', 'super-abilities' ) . '</p>';
	}

	/**
	 * Prints a field description when one was supplied.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	protected function render_description( $args ) {
		if ( empty( $args['description'] ) ) {
			return;
		}

		echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
	}

	/**
	 * Builds the escaped label cell for a module row.
	 *
	 * @since 0.1.0
	 *
	 * @param Module_Interface $module Module instance.
	 * @return string HTML.
	 */
	protected function module_title( Module_Interface $module ) {
		$risk   = (string) $module->risk();
		$labels = array(
			'low'    => __( 'low risk', 'super-abilities' ),
			'medium' => __( 'medium risk', 'super-abilities' ),
			'high'   => __( 'high risk', 'super-abilities' ),
		);

		return sprintf(
			'%1$s <span class="super-abilities-risk super-abilities-risk-%2$s">%3$s</span>',
			esc_html( $module->label() ),
			esc_attr( $risk ),
			esc_html( isset( $labels[ $risk ] ) ? $labels[ $risk ] : $risk )
		);
	}

	/**
	 * Instantiates the abilities a module declares.
	 *
	 * @since 0.1.0
	 *
	 * @param Module_Interface $module Module instance.
	 * @return array<int, Abstract_Ability>
	 */
	protected function module_abilities( Module_Interface $module ) {
		$abilities = array();

		foreach ( $module->abilities() as $class_name ) {
			$class_name = (string) $class_name;

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$ability = new $class_name();

			if ( $ability instanceof Abstract_Ability ) {
				$abilities[] = $ability;
			}
		}

		return $abilities;
	}

	/**
	 * The union of the capabilities a module's abilities require.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, Abstract_Ability> $abilities Ability instances.
	 * @return array<int, string>
	 */
	protected function module_capabilities( array $abilities ) {
		$caps = array();

		foreach ( $abilities as $ability ) {
			foreach ( $ability->capability() as $cap ) {
				$cap = (string) $cap;

				if ( '' !== $cap ) {
					$caps[ $cap ] = $cap;
				}
			}
		}

		ksort( $caps );

		return array_values( $caps );
	}

	/**
	 * Counts the abilities this plugin currently has in the registry.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	protected function count_registered_abilities() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return 0;
		}

		$prefix = Abstract_Ability::ABILITY_NAMESPACE . '/';
		$count  = 0;

		foreach ( array_keys( wp_get_abilities() ) as $name ) {
			if ( 0 === strpos( (string) $name, $prefix ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Human readable age of the last cron heartbeat.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function heartbeat_label() {
		$last = (int) get_option( Plugin::HEARTBEAT_OPTION, 0 );

		if ( $last < 1 ) {
			return __( 'never', 'super-abilities' );
		}

		return sprintf(
			/* translators: %s: Human readable time difference, for example "5 mins". */
			__( '%s ago', 'super-abilities' ),
			human_time_diff( $last, Time::now() )
		);
	}
}
