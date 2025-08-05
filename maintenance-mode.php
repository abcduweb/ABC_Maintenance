<?php
/*
Plugin Name: ABC Maintenance
Description: Active un mode maintenance compatible Full Site Editing (FSE) avec gestion avancée et personnalisation.
Version: 1.0.0
Author: Adrien Dubois (SAS ABCduWeb)
Text Domain: maintenance-mode-fse
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Maintenance_Mode_FSE {
	const OPTION_KEY = 'mmfse_enabled';
	const PAGE_SLUG = 'maintenance';
	const TEMPLATE_SLUG = 'page-maintenance';
	const TEMPLATE_TITLE = 'Page Maintenance';
	const MAINTENANCE_LABEL = 'Page de maintenance du site';
	const MESSAGE_OPTION = 'mmfse_message';

	public function __construct() {
		// Hooks
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'template_redirect', [ $this, 'handle_redirection' ] );
		add_action( 'init', [ $this, 'register_custom_template' ] );
		add_action( 'admin_notices', [ $this, 'admin_notice_on_maintenance' ] );
		add_filter( 'display_post_states', [ $this, 'add_maintenance_label' ], 10, 2 );
		register_activation_hook( __FILE__, [ __CLASS__, 'on_activation' ] );
	}

	/**
	 * Ajoute la page de réglages dans le menu admin
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Maintenance', 'maintenance-mode-fse' ),
			__( 'Maintenance', 'maintenance-mode-fse' ),
			'manage_options',
			'mmfse-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enregistre les réglages
	 */
	public function register_settings() {
		register_setting( 'mmfse_settings_group', self::OPTION_KEY );
		register_setting( 'mmfse_settings_group', self::MESSAGE_OPTION );
	}

	/**
	 * Affiche la page de réglages
	 */
	public function render_settings_page() {
		$enabled = get_option( self::OPTION_KEY, false );
		$message = get_option( self::MESSAGE_OPTION, __( 'Le site est en maintenance. Merci de revenir plus tard.', 'maintenance-mode-fse' ) );
		$template_edit_url = $this->get_fse_template_edit_url();
		?>
		<div class="wrap">
			<h1><?php _e( 'Réglages Maintenance', 'maintenance-mode-fse' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'mmfse_settings_group' ); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e( 'Activer la maintenance', 'maintenance-mode-fse' ); ?></th>
						<td><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>" value="1" <?php checked( $enabled, 1 ); ?> /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e( 'Message de maintenance', 'maintenance-mode-fse' ); ?></th>
						<td><textarea name="<?php echo esc_attr( self::MESSAGE_OPTION ); ?>" rows="3" cols="50"><?php echo esc_textarea( $message ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( $template_edit_url ) : ?>
				<p><a class="button button-secondary" href="<?php echo esc_url( $template_edit_url ); ?>" target="_blank"><?php _e( 'Éditer le template FSE Maintenance', 'maintenance-mode-fse' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Gère la redirection selon l'état de la maintenance
	 */
	public function handle_redirection() {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return; // Admins can access everything
		}
		$enabled = get_option( self::OPTION_KEY, false );
		if ( $enabled ) {
			if ( ! is_page( self::PAGE_SLUG ) ) {
				$page = get_page_by_path( self::PAGE_SLUG );
				if ( $page ) {
					wp_redirect( get_permalink( $page->ID ), 302 );
					exit;
				}
			}
		} else {
			if ( is_page( self::PAGE_SLUG ) ) {
				wp_redirect( home_url(), 302 );
				exit;
			}
		}
	}

	/**
	 * Ajoute un label personnalisé à la page maintenance dans l'admin
	 */
	public function add_maintenance_label( $post_states, $post ) {
		if ( $post->post_name === self::PAGE_SLUG ) {
			$post_states[] = __( self::MAINTENANCE_LABEL, 'maintenance-mode-fse' );
		}
		return $post_states;
	}

	/**
	 * Crée la page et le template FSE à l'activation du plugin
	 */
	public static function on_activation() {
		// Créer la page "maintenance" si elle n'existe pas
		$page = get_page_by_path( self::PAGE_SLUG );
		if ( ! $page ) {
			$page_id = wp_insert_post([
				'post_title'   => __( 'Maintenance', 'maintenance-mode-fse' ),
				'post_name'    => self::PAGE_SLUG,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			]);
		} else {
			$page_id = $page->ID;
		}
		// Créer le template FSE personnalisé
		self::create_fse_template();
		// Assigner le template à la page
		if ( $page_id ) {
			update_post_meta( $page_id, '_wp_page_template', 'page-' . self::TEMPLATE_SLUG );
		}
	}

	/**
	 * Crée un template FSE "Page Maintenance" basé sur le template "pages" du thème actif
	 */
	public static function create_fse_template() {
		$theme = wp_get_theme();
		$template_slug = 'page-' . self::TEMPLATE_SLUG;
		$template_exists = get_page_by_path( $template_slug, OBJECT, 'wp_template' );
		if ( $template_exists ) {
			return;
		}
		// Récupérer le template "page" du thème actif
		$block_templates = get_block_templates( [ 'slug__in' => [ 'page' ] ], 'wp_template' );
		$page_template = ! empty( $block_templates ) ? reset( $block_templates ) : null;
		$content = '';
		if ( $page_template ) {
			// Remplacer le contenu principal par le message de maintenance
			$content = preg_replace( '/<!-- wp:post-content.*?\/-->/s', '<!-- wp:paragraph --><p>{{mmfse_message}}</p><!-- /wp:paragraph -->', $page_template->content );
		} else {
			// Fallback minimal
			$content = '<!-- wp:paragraph --><p>{{mmfse_message}}</p><!-- /wp:paragraph -->';
		}
		// Créer le template personnalisé
		wp_insert_post([
			'post_title'     => self::TEMPLATE_TITLE,
			'post_name'      => $template_slug,
			'post_status'    => 'publish',
			'post_type'      => 'wp_template',
			'post_content'   => $content,
			'post_author'    => get_current_user_id(),
			'post_excerpt'   => __( 'Template de maintenance', 'maintenance-mode-fse' ),
			'post_parent'    => 0,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'menu_order'     => 0,
			'guid'           => home_url( '/?post_type=wp_template&p=' ) . uniqid(),
			'meta_input'     => [
				'theme' => get_stylesheet(),
			],
		]);
	}

	/**
	 * Enregistre le template personnalisé pour l'éditeur FSE
	 */
	public function register_custom_template() {
		add_filter( 'default_page_template_title', function( $title, $post ) {
			if ( $post->post_name === self::PAGE_SLUG ) {
				return self::TEMPLATE_TITLE;
			}
			return $title;
		}, 10, 2 );
	}

	/**
	 * Affiche une notice admin si la maintenance est activée
	 */
	public function admin_notice_on_maintenance() {
		if ( get_option( self::OPTION_KEY, false ) ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Le mode maintenance est actuellement activé.', 'maintenance-mode-fse' ) );
		}
	}

	/**
	 * Retourne l'URL d'édition FSE de la page maintenance
	 */
	public function get_fse_template_edit_url() {
		$page = get_page_by_path( self::PAGE_SLUG );
		if ( $page ) {
			return admin_url( 'site-editor.php?postId=' . $page->ID . '&postType=page' );
		}
		return false;
	}
}

new Maintenance_Mode_FSE(); 