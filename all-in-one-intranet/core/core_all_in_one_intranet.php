<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/mu-shim/core_aioi_mu_shim.php';

class core_all_in_one_intranet {

	protected $aioi_options = null;

	protected function __construct() {
		$this->add_actions();
	}

	/**
	 * Hook into WordPress.
	 */
	protected function add_actions() {

		// Keep the must-use shim in sync on every request, not only on activation:
		// register_activation_hook does not fire when an existing install is
		// updated, so those sites would have no shim until the next admin visit.
		// Unless the plugin is network-activated, the shim is also the only way to
		// gate wp-activate.php, which defines WP_INSTALLING before bootstrapping
		// WordPress, so wp_get_active_and_valid_plugins() skips regular plugins
		// entirely. MU plugins still load there, and the gate has to run before
		// template-loader.php outputs protected feed content. ensure() re-copies
		// only when the installed copy's size or mtime differs from the source.
		add_action( 'plugins_loaded', [ 'core_aioi_mu_shim', 'ensure' ], 0 );

		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'aioi_admin_init' ], 5, 0 );

			add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', [ $this, 'aioi_admin_menu' ] );

			if ( is_multisite() ) {
				add_action( 'network_admin_edit_' . $this->get_options_menuname(), [ $this, 'aioi_save_network_options' ] );
				add_filter( 'network_admin_plugin_action_links', [ $this, 'aioi_plugin_action_links' ], 10, 2 );
			} else {
				add_filter( 'plugin_action_links', [ $this, 'aioi_plugin_action_links' ], 10, 2 );
			}
		}

		// Both hooks at priority 1, with a static guard so the gate runs once.
		// 'template_redirect' at 1 beats redirect_canonical at 10, which leaks post
		// slugs through its 301 Location. 'wp' at 1 covers non-theme entry points
		// (wp-signup.php, wp-trackback.php) where wp_using_themes() is false so
		// template_redirect never fires, yet template-loader.php still runs its
		// unconditional is_feed/is_trackback/is_robots/is_favicon block and leaks
		// feeds. Not on admin or WP-CLI: both call wp() themselves (list tables in
		// wp-admin/includes/post.php), admin has its own auth, and CLI must not be
		// redirected.
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'wp', [ $this, 'aioi_template_redirect' ], 1 );
		}
		add_action( 'template_redirect', [ $this, 'aioi_template_redirect' ], 1 );
		add_filter( 'robots_txt', [ $this, 'aioi_robots_txt' ], 0, 2 );
		add_filter( 'option_ping_sites', [ $this, 'aioi_option_ping_sites' ], 0, 1 );
		add_filter( 'rest_pre_dispatch', [ $this, 'aioi_rest_pre_dispatch' ], 0, 3 );
		add_filter( 'xmlrpc_enabled', [ $this, 'aioi_xmlrpc_enabled' ] );

		// wp-comments-post.php loads wp-load.php without calling wp(), so neither
		// 'template_redirect' nor 'wp' fires and wp_handle_comment_submission() runs
		// ungated. 'comments_open' is consulted before insertion. 'pings_open' backs
		// up wp-trackback.php, which does call wp(), so trackback writes do not rest
		// on the 'wp' hook alone.
		add_filter( 'comments_open', [ $this, 'aioi_comments_open' ], 10, 2 );
		add_filter( 'pings_open', [ $this, 'aioi_comments_open' ], 10, 2 );

		add_filter( 'login_redirect', [ $this, 'aioi_login_redirect' ], 10, 3 );

		add_action( 'wp_login', [ $this, 'aioi_wp_login' ], 10, 2 );

		// 'wp_login' only fires from wp_signon(), while two-factor and SSO plugins
		// call wp_set_auth_cookie() directly for their own second step. This action
		// fires on every path, so the clock never stays on the password step.
		add_action( 'set_logged_in_cookie', [ $this, 'aioi_set_logged_in_cookie' ], 10, 4 );

		add_action( 'init', [ $this, 'aioi_check_activity' ], 1 );

		// wp-links-opml.php require()s wp-load.php and prints the blogroll without
		// calling wp(), so neither 'wp' nor 'template_redirect' fires. 'init'
		// priority 1 is the earliest shared hook there: regular plugins and
		// pluggable.php are loaded, and no OPML has been output yet. The handler is
		// scoped to $pagenow, so it stays inert on every other request.
		add_action( 'init', [ $this, 'aioi_gate_opml' ], 1 );

		// Both endpoints make is_admin() true, so the 'wp' gate above skips them,
		// 'template_redirect' never fires, and rest_pre_dispatch does not cover
		// them. That leaves every wp_ajax_nopriv_* / admin_post_nopriv_* handler
		// from the theme or another plugin running unauthenticated on a private
		// site. 'init' priority 1 is the earliest shared hook on these endpoints,
		// after pluggable.php is loaded and before they dispatch. Scoped to
		// wp_doing_ajax() / $pagenow so it stays inert elsewhere.
		add_action( 'init', [ $this, 'aioi_gate_admin_endpoints' ], 1 );

		if ( is_multisite() ) {
			add_action( 'wpmu_new_user', [ $this, 'aioi_wpmu_new_user' ], 10, 1 );

			// 'wpmu_new_blog' has been deprecated since WordPress 5.1, below the
			// plugin's own 5.5 minimum, and core fires it only through
			// do_action_deprecated(), logging a notice on every sub-site creation.
			// Priority 20 runs after core's own initializer at priority 10, which
			// creates the tables and makes the creator an administrator.
			add_action( 'wp_initialize_site', [ $this, 'aioi_wp_initialize_site' ], 20, 2 );
		}
	}

	/**
	 * The list of plugin options and their default values.
	 *
	 * @return array
	 */
	protected function get_default_options() {

		return [
			'aioi_version'          => $this->PLUGIN_VERSION,
			'aioi_privatesite'      => true,
			'aioi_ms_requiremember' => true,
			'aioi_autologout_time'  => 0,
			'aioi_autologout_units' => 'minutes',
			'aioi_loginredirect'    => '',
			'aioi_ms_membersrole'   => '',
		];
	}

	// PRIVATE SITE

	/**
	 * Process the request based on whether the site is private or not.
	 */
	public function aioi_template_redirect() {

		// Hooked on both 'wp' and 'template_redirect'. Run once.
		static $already_ran = false;
		if ( $already_ran ) {
			return;
		}
		$already_ran = true;

		$options = $this->get_option_aioi();

		// Do nothing if private site is off.
		if ( ! $options['aioi_privatesite'] ) {
			return;
		}

		$allow_access = false;

		// Allow certain URLs. Compare the parsed path exactly: with a prefix match
		// on the raw REQUEST_URI, a path like /robots.txt/?p=7 gets past the gate
		// and is still routed to the underlying post or feed.
		$request_path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: '';

		// Derive expected paths at runtime so the gate works on subdirectory installs
		// (where WP lives at /wp/, so robots.txt is at /wp/robots.txt) and on
		// multisite subdirectory subsites (where home is at /siteN/).
		$home_path   = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_prefix = ( '' === $home_path || '/' === $home_path ) ? '' : rtrim( $home_path, '/' );
		$robots_path = $home_prefix . '/robots.txt';

		// The allowances below (robots.txt and wp-activate.php) let an
		// unauthenticated request reach WordPress without the gate, so each first
		// confirms the request is not also routed to content. See
		// aioi_request_has_routing_query_var().
		global $pagenow;

		if ( $request_path === $robots_path && ! $this->aioi_request_has_routing_query_var() ) {
			$allow_access = true;
		}

		// $pagenow rather than a REQUEST_URI compare for wp-activate.php. WordPress
		// derives it from PHP_SELF with a regex that still resolves
		// /wp-activate.php/?feed=rss2 and /wp-activate%2Ephp?feed=rss2 to
		// 'wp-activate.php', where a string compare does not. The script loads
		// wp-blog-header.php, so WP::main() and template-loader.php can emit feed
		// or REST output before its own redirect and render logic runs.
		if ( isset( $pagenow ) && 'wp-activate.php' === $pagenow && ! $this->aioi_request_has_routing_query_var() ) {
			$allow_access = true;
		}

		$allow_access = (bool) apply_filters( 'aioi_allow_public_access', $allow_access );

		if ( $allow_access ) {
			return;
		}

		// We do want a private site.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( is_multisite() ) {
			$this->handle_private_loggedin_multisite( $options );
		} elseif ( ! $this->aioi_user_has_role() ) {
			// Restrict access to logged-in users with no role.
			wp_logout();
			wp_die(
				'<p>' . esc_html__( 'You attempted to login to the site, but you do not have any permissions. If you believe you should have access, please contact your administrator.', 'all-in-one-intranet' ) . '</p>'
			);
		}
	}

	/**
	 * Whether the current request carries a recognized WordPress query var that
	 * would route it to content.
	 *
	 * Qualifies the static-path allowances in aioi_template_redirect(): those
	 * paths go past the private-site gate unauthenticated, but only when the
	 * request is not also asking WordPress to render something else.
	 * WP::parse_request() lets $_GET/$_POST override a path's rewrite vars and
	 * also routes from the URL path itself, so /robots.txt?robots=0&feed=rss2 or
	 * /wp-activate.php/feed/rss2/ would otherwise emit a protected feed.
	 *
	 * Both sources are checked: the keys of $_GET/$_POST against
	 * WP::$public_query_vars, which the `query_vars` filter extends inside
	 * parse_request() and is how the REST API adds `rest_route`, and the parsed
	 * feed/rest_route vars, which path routing can set without them appearing in
	 * either superglobal. Keep in sync with core/mu-shim/aioi-installing-gate.php.
	 *
	 * Fails closed: without a parsed $wp there is no telling where the request
	 * routes, so it counts as carrying query vars and the allowance does not fire.
	 *
	 * @return bool
	 */
	protected function aioi_request_has_routing_query_var() {

		global $wp;

		if ( ! isset( $wp ) || ! is_array( $wp->public_query_vars ) ) {
			return true;
		}

		// WP::parse_request() populates routing query vars from $_POST as well as
		// $_GET, so a $_GET-only check is bypassable with a POST body.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$request_keys = array_merge( array_keys( $_GET ), array_keys( $_POST ) );

		if ( array_intersect( $request_keys, $wp->public_query_vars ) ) {
			return true;
		}

		// feed and rest_route are the content-read vectors these static paths reach:
		// wp_using_themes() is false on wp-activate.php, so template-loader.php runs
		// only its unconditional feed block, and REST dispatches from
		// rest_api_loaded(). The parsed vars also catch pretty-permalink routing
		// such as /wp-activate.php/feed/rss2/, which never appears in $_GET/$_POST.
		$query_vars = is_array( $wp->query_vars ) ? $wp->query_vars : [];

		return ! empty( $query_vars['feed'] ) || ! empty( $query_vars['rest_route'] );
	}

	/**
	 * Handle private site for logged-in users in a multisite.
	 *
	 * @param array $options
	 */
	protected function handle_private_loggedin_multisite( $options ) {

		if ( $this->aioi_user_is_subsite_member( $options ) ) {
			return;
		}

		// The logged-in user is not a member of this sub-site.
		$blogs     = get_blogs_of_user( get_current_user_id() );
		$blog_name = get_bloginfo( 'name' );

		$output = '<p>' . esc_html(
			sprintf( /* translators: %s - name of the site. */
				__( 'You attempted to access the "%1$s" sub-site, but you are not currently a member of this site. If you believe you should be able to access "%1$s", please contact your network administrator.', 'all-in-one-intranet' ),
				$blog_name
			)
			) . '</p>';

		if ( ! empty( $blogs ) ) {

			$output .= '<p>' . esc_html__( 'You are a member of the following sites:', 'all-in-one-intranet' ) . '</p>';

			$output .= '<table>';

			foreach ( $blogs as $blog ) {
				$output .= "<tr>";
				$output .= "<td valign='top'>";
				$output .= "<a href='" . esc_url( get_home_url( $blog->userblog_id ) ) . "'>" . esc_html( $blog->blogname ) . "</a>";
				$output .= "</td>";
				$output .= "</tr>";
			}
			$output .= '</table>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_die( $output );
	}

	/**
	 * Whether the current logged-in user is a member of the current sub-site.
	 *
	 * Returns true (access permitted) whenever the membership requirement does
	 * not apply: non-multisite installs, the requirement toggled off, or the
	 * network admin. Shared by handle_private_loggedin_multisite() (frontend)
	 * and aioi_is_access_allowed() (REST) so both decide membership identically.
	 *
	 * @param array $options Plugin options.
	 *
	 * @return bool
	 */
	protected function aioi_user_is_subsite_member( $options ) {

		if ( ! is_multisite() || empty( $options['aioi_ms_requiremember'] ) || is_network_admin() ) {
			return true;
		}

		$blogs = get_blogs_of_user( get_current_user_id() );

		return (bool) wp_list_filter( $blogs, [ 'userblog_id' => get_current_blog_id() ] );
	}

	/**
	 * Whether the current logged-in user has at least one role on this site.
	 *
	 * @return bool
	 */
	protected function aioi_user_has_role() {

		$user = wp_get_current_user();

		return $user && is_array( $user->roles ) && count( $user->roles ) > 0;
	}

	/**
	 * Whether the current request may access content while the site is private.
	 *
	 * Single source of truth for the login + role/membership rules, so the REST
	 * gate (aioi_rest_pre_dispatch) enforces the same parity as the frontend
	 * gate (aioi_template_redirect). The frontend-only URL allowances
	 * (robots.txt, wp-activate.php) are applied by the caller, not here.
	 * Anonymous users are reported as not allowed; the caller maps that to the
	 * appropriate response (auth_redirect / 401 vs wp_die / 403).
	 *
	 * @param array $options Plugin options.
	 *
	 * @return bool
	 */
	protected function aioi_is_access_allowed( $options ) {

		if ( empty( $options['aioi_privatesite'] ) ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return is_multisite()
			? $this->aioi_user_is_subsite_member( $options )
			: $this->aioi_user_has_role();
	}

	/**
	 * Handler for robots.txt - just disallow everything if private.
	 *
	 * @param string $output The robots.txt output.
	 * @param bool   $public Whether the site is considered "public".
	 *
	 * @return string
	 */
	public function aioi_robots_txt( $output, $public ) {

		$options = $this->get_option_aioi();

		if ( $options['aioi_privatesite'] ) {
			return "User-agent: *\nDisallow: /\n";
		}

		return $output;
	}

	/*
	 * Don't allow pingbacks if private.
	 */
	public function aioi_option_ping_sites( $sites ) {

		$options = $this->get_option_aioi();

		if ( $options['aioi_privatesite'] ) {
			return '';
		}

		return $sites;
	}

	/**
	 * Gate the REST API on private sites.
	 *
	 * Role and membership parity with the frontend gate comes from
	 * aioi_is_access_allowed(): anonymous requests get a 401, and logged-in
	 * requests without access (no role on single-site, not a member of this
	 * sub-site on multisite) get a 403, mirroring the frontend's auth_redirect()
	 * and wp_die() split. Routes that aioi_is_public_rest_route() exempts are
	 * let through ahead of both checks.
	 *
	 * @param mixed           $result  REST dispatch result; returned unchanged when access is allowed.
	 * @param mixed           $server  The REST server instance. Unused.
	 * @param WP_REST_Request $request The request being dispatched.
	 *
	 * @return mixed|WP_Error
	 */
	public function aioi_rest_pre_dispatch( $result, $server = null, $request = null ) {

		// Checked first, for the same reason as in aioi_gate_admin_endpoints().
		if ( $this->aioi_is_public_rest_route( $request ) ) {
			return $result;
		}

		$options      = $this->get_option_aioi();
		$allow_access = (bool) apply_filters( 'aioi_allow_public_access', $this->aioi_is_access_allowed( $options ) );

		if ( $allow_access ) {
			return $result;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not-logged-in', 'REST API Requests must be authenticated because All-In-One Intranet is active', [ 'status' => 401 ] );
		}

		return new WP_Error( 'not-authorized', 'You are not authorized to access this site because All-In-One Intranet is active', [ 'status' => 403 ] );
	}

	/**
	 * Disable XML-RPC when the site is private.
	 *
	 * @param bool $enabled Whether XML-RPC is enabled.
	 *
	 * @return bool
	 */
	public function aioi_xmlrpc_enabled( $enabled ) {

		$options = $this->get_option_aioi();

		if ( $options['aioi_privatesite'] ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Force comments and pingbacks closed for anonymous visitors on private sites.
	 *
	 * Shared callback for 'comments_open' and 'pings_open'; see the filter
	 * registrations in add_actions() for the rationale.
	 *
	 * @param bool $open    Whether commenting/pinging is currently open for the post.
	 * @param int  $post_id Post ID being checked.
	 *
	 * @return bool
	 */
	public function aioi_comments_open( $open, $post_id ) {

		$options      = $this->get_option_aioi();
		$allow_access = (bool) apply_filters( 'aioi_allow_public_access', $this->aioi_is_access_allowed( $options ) );

		if ( ! $allow_access ) {
			return false;
		}

		return $open;
	}

	/**
	 * Gate wp-links-opml.php on private sites.
	 *
	 * The endpoint would otherwise leak the blogroll, the site title and the
	 * WordPress generator version to anonymous visitors; see the hook
	 * registration in add_actions() for why 'init' priority 1. Identified via the
	 * $pagenow global (derived from PHP_SELF, so trailing-slash PATH_INFO and
	 * percent-encoded dots still match) rather than a REQUEST_URI compare,
	 * matching the wp-activate.php check. Login and role/membership parity with
	 * the REST gate comes from aioi_is_access_allowed(): anonymous users are sent
	 * to the login wall, logged-in users without access get a 403.
	 */
	public function aioi_gate_opml() {

		global $pagenow;

		if ( ! isset( $pagenow ) || 'wp-links-opml.php' !== $pagenow ) {
			return;
		}

		$options      = $this->get_option_aioi();
		$allow_access = (bool) apply_filters( 'aioi_allow_public_access', $this->aioi_is_access_allowed( $options ) );

		if ( $allow_access ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		wp_die(
			'<p>' . esc_html__( 'You do not have permission to access this resource. If you believe you should have access, please contact your administrator.', 'all-in-one-intranet' ) . '</p>',
			'',
			[ 'response' => 403 ]
		);
	}

	/**
	 * Gate admin-ajax.php and admin-post.php on private sites.
	 *
	 * Without this, any wp_ajax_nopriv_* / admin_post_nopriv_* handler runs
	 * unauthenticated while the site is private, which breaks the "entirely
	 * private" guarantee; see the hook registration in add_actions() for why
	 * 'init' priority 1. admin-ajax.php defines DOING_AJAX before bootstrap so
	 * wp_doing_ajax() is reliable here, and admin-post.php is identified via the
	 * $pagenow global, matching the OPML gate. Login and role/membership parity
	 * with the REST gate comes from aioi_is_access_allowed(): anonymous users are
	 * sent to the login wall, logged-in users without access get a 403. The
	 * authentication actions aioi_is_public_action() exempts stay reachable so
	 * visitors can log in at all.
	 */
	public function aioi_gate_admin_endpoints() {

		global $pagenow;

		$is_admin_post = isset( $pagenow ) && 'admin-post.php' === $pagenow;

		if ( ! wp_doing_ajax() && ! $is_admin_post ) {
			return;
		}

		// Checked first: these actions run while the visitor is still logged out. The
		// 'page' guard matters as much as the action name, because both endpoints
		// fire do_action( 'admin_init' ) before they dispatch and core hooks
		// render-and-exit handlers there that key on $_GET['page'] with no capability
		// check of their own (wp_font_library_intercept_render() and
		// wp_options_connectors_intercept_render() in WP 7.0). An exempt action with
		// a 'page' attached would return a rendered admin screen. No login flow
		// sends 'page'.
		if ( ! $this->aioi_request_has_page_var() && $this->aioi_is_public_action() ) {
			return;
		}

		$options      = $this->get_option_aioi();
		$allow_access = (bool) apply_filters( 'aioi_allow_public_access', $this->aioi_is_access_allowed( $options ) );

		if ( $allow_access ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		wp_die(
			'<p>' . esc_html__( 'You do not have permission to access this resource. If you believe you should have access, please contact your administrator.', 'all-in-one-intranet' ) . '</p>',
			'',
			[ 'response' => 403 ]
		);
	}

	/**
	 * Whether the request carries a 'page' variable.
	 *
	 * Reads $_GET and $_POST, not $_REQUEST: request_order can leave GET out of
	 * $_REQUEST, while core's admin_init interceptors read $_GET directly, so a
	 * 'page' in the query string beside an exempt 'action' in the body would slip
	 * past a $_REQUEST-only check.
	 *
	 * @return bool
	 */
	protected function aioi_request_has_page_var() {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) || isset( $_POST['page'] );
	}

	/**
	 * The login-flow endpoints that stay reachable while the site is private, and
	 * the plugins that provide them.
	 *
	 * WordPress leaves wp-login.php open, but two-factor, passkey and login
	 * interstitial plugins finish that same exchange over admin-ajax.php or the
	 * REST API, while the visitor is still logged out. Gating those answers their
	 * XHR with a redirect or a 401 that the script cannot parse as JSON, so
	 * nobody gets past the login screen. Allowing them adds no exposure
	 * wp-login.php lacks: each handler is registered for anonymous access by its
	 * own author, checks its own credentials, and returns a verdict rather than
	 * site content.
	 *
	 * A group applies only while one of its own plugin files is active, so the
	 * exemptions never reach past the plugins a site runs. Free and premium
	 * builds are listed separately where they ship as separate directories; a
	 * renamed or must-use copy is not recognized and needs the
	 * `aioi_public_actions` or `aioi_public_rest_routes` filter instead.
	 *
	 * @return array
	 */
	protected function get_login_flow_plugins() {

		return [
			// Wordfence, standalone Login Security or the copy bundled in Wordfence: the
			// login form posts the credentials here to learn whether a 2FA prompt is
			// required. Its script runs for every login attempt once any user has 2FA
			// enabled, so gating this locks out every account, not only the 2FA ones.
			[
				'files'   => [
					'wordfence/wordfence.php',
					'wordfence-login-security/wordfence-login-security.php',
				],
				'actions' => [ 'wordfence_ls_authenticate' ],
			],

			// WP 2FA. The validate route checks the one-time code and issues the login
			// cookie when its verification mechanism is "REST API" rather than the
			// default "Native", so gating it strands every user at the code prompt. Its
			// passkey (WebAuthn) challenge and assertion go over REST by default and
			// over AJAX in the fallback mode, so both transports have to be open.
			[
				'files'   => [
					'wp-2fa/wp-2fa.php',
					'wp-2fa-premium/wp-2fa.php',
				],
				'actions' => [
					'wp2fa_signin_request',
					'wp2fa_signin_response',
				],
				'routes'  => [
					'/wp-2fa-methods/v1/login/validate',
					'/wp-2fa-passkeys/v1/singin/request',
					'/wp-2fa-passkeys/v1/singin/response',
				],
			],

			// miniOrange 2-Factor: the second-factor step of its login flow. Its emailed
			// 2FA-reset link is left out on purpose, since that callback disables the
			// account's second factor and is not needed to finish a login. The route
			// carries its token in the path, so opening it needs aioi_allow_public_access
			// rather than the route filter.
			[
				'files'   => [ 'miniorange-2-factor-authentication/miniorange_2_factor_settings.php' ],
				'actions' => [ 'mo_two_factor_ajax' ],
			],

			// Solid Security, free or pro: the login interstitial that hosts its 2FA
			// prompt.
			[
				'files'   => [
					'better-wp-security/better-wp-security.php',
					'ithemes-security-pro/ithemes-security-pro.php',
				],
				'actions' => [ 'itsec-login-interstitial-ajax' ],
			],

			// AIO Login: the passwordless one-time-code panel it adds to the login form
			// by default. Blocking these leaves the panel visible but dead.
			[
				'files'   => [ 'change-wp-admin-login/change-wp-admin-login.php' ],
				'actions' => [
					'aio_login_otp_send',
					'aio_login_otp_resend',
					'aio_login_otp_verify',
				],
			],

			// Limit Login Attempts Reloaded: sends the code for its email second
			// factor. It offers that same step over both transports.
			[
				'files'   => [ 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ],
				'actions' => [ 'llar_mfa_flow_send_code' ],
				'routes'  => [ '/llar/v1/mfa/send-code' ],
			],

			// Login With Ajax: the passkey challenge and assertion. Off until passkey
			// login is switched on in its settings, and from then on it hooks the core
			// 'login_form', so this runs on the normal login screen too, not only in the
			// plugin's own front-end widget.
			[
				'files'   => [ 'login-with-ajax/login-with-ajax.php' ],
				'actions' => [
					'lwa_passkeys',
					'lwa_passkey_login',
				],
			],

			// Shield Security is deliberately absent: its login 2FA posts to the generic
			// 'shield_action' router, which also serves subactions declaring
			// AuthNotRequired and NonceVerifyNotRequired, so the action name would reopen
			// far more than the 2FA step. Shield users can add it through the filter.
		];
	}

	/**
	 * The admin-ajax.php / admin-post.php actions that stay reachable while the
	 * site is private.
	 *
	 * The ones from an active plugin, plus whatever the `aioi_public_actions`
	 * filter adds. See get_login_flow_plugins().
	 *
	 * @return array
	 */
	protected function get_public_actions() {

		$actions = [];

		foreach ( $this->get_login_flow_plugins() as $plugin ) {
			if ( ! empty( $plugin['actions'] ) && $this->aioi_is_any_plugin_active( $plugin['files'] ) ) {
				$actions = array_merge( $actions, $plugin['actions'] );
			}
		}

		return (array) apply_filters( 'aioi_public_actions', $actions );
	}

	/**
	 * The REST routes that stay reachable while the site is private.
	 *
	 * The same as get_public_actions(), one transport over: some login plugins
	 * verify the second factor over REST, registering the route with
	 * `permission_callback => '__return_true'` and checking their own login
	 * nonce, which the private-site 401 lands before.
	 *
	 * An entry matches the route exactly or as a path segment prefix, so a
	 * sibling route such as `/ns/v1/verify-debug` cannot ride in on an entry for
	 * `/ns/v1/verify`.
	 *
	 * @return array
	 */
	protected function get_public_rest_routes() {

		$routes = [];

		foreach ( $this->get_login_flow_plugins() as $plugin ) {
			if ( ! empty( $plugin['routes'] ) && $this->aioi_is_any_plugin_active( $plugin['files'] ) ) {
				$routes = array_merge( $routes, $plugin['routes'] );
			}
		}

		return (array) apply_filters( 'aioi_public_rest_routes', $routes );
	}

	/**
	 * Whether any of the given plugin files is active on this site.
	 *
	 * Not is_plugin_active(): that lives in wp-admin/includes/plugin.php, which
	 * admin-ajax.php and admin-post.php load only after the bootstrap has fired
	 * 'init', where the gate runs. Reads the same two options core does, so a
	 * network-activated plugin counts on every sub-site.
	 *
	 * @param array $files Plugin files, relative to the plugins directory.
	 *
	 * @return bool
	 */
	protected function aioi_is_any_plugin_active( $files ) {

		$active = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		return (bool) array_intersect( (array) $files, $active );
	}

	/**
	 * Whether the current REST request targets a route that may run while the site
	 * is private.
	 *
	 * Fails closed on anything that is not a parsed REST request, so the gate still
	 * applies if the filter is ever called without one.
	 *
	 * @param mixed $request The request passed to 'rest_pre_dispatch'.
	 *
	 * @return bool
	 */
	protected function aioi_is_public_rest_route( $request ) {

		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		$route = (string) $request->get_route();

		foreach ( $this->get_public_rest_routes() as $allowed ) {
			$allowed = is_string( $allowed ) ? rtrim( $allowed, '/' ) : '';

			// Skip anything that is not a route of its own: an entry of '/', or any run
			// of slashes, would reduce to the empty string and match every route.
			if ( $allowed === '' || strpos( $allowed, '/' ) !== 0 ) {
				continue;
			}

			// Exact match, or the allowed route followed by a '/' boundary. Never a
			// bare string prefix, which would also match a longer sibling route.
			if ( $route === $allowed || strpos( $route, $allowed . '/' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current admin-ajax.php / admin-post.php request targets an
	 * action that may run while the site is private.
	 *
	 * Compares the raw $_REQUEST['action'], which is the exact value both endpoints
	 * build their dispatch hook from. So the allowance can never apply to a different
	 * action than the one WordPress runs, and a blocked action in the POST body
	 * cannot hide behind an allowed one in the query string. Inexact matches and
	 * non-scalar input fail closed.
	 *
	 * @return bool
	 */
	protected function aioi_is_public_action() {

		// Raw on purpose: normalizing it would compare something other than what
		// WordPress dispatches on.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_REQUEST['action'] ) || ! is_scalar( $_REQUEST['action'] ) ) {
			return false;
		}

		$action = (string) $_REQUEST['action'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput

		return in_array( $action, $this->get_public_actions(), true );
	}

	/**
	 * Redirect on login event.
	 *
	 * @param string  $redirect_to
	 * @param string  $requested_redirect_to
	 * @param WP_User $user
	 */
	public function aioi_login_redirect( $redirect_to, $requested_redirect_to = '', $user = null ) {

		if ( ! is_null( $user ) && isset( $user->user_login ) ) {
			$options = $this->get_option_aioi();

			if ( $options['aioi_loginredirect'] !== '' && admin_url() === $redirect_to ) {
				return $options['aioi_loginredirect'];
			}
		}

		return $redirect_to;
	}

	/**
	 * AUTO-LOGOUT.
	 * Reset timer on login.
	 *
	 * @param string $username
	 * @param WP_User $user
	 */
	public function aioi_wp_login( $username, $user ) {

		try {
			if ( $user->ID ) {
				update_user_meta( $user->ID, 'aioi_last_activity_time', time() );
			}
		} catch ( Exception $e ) {
			// Do nothing.
		}
	}

	/**
	 * AUTO-LOGOUT.
	 * Start the inactivity clock when WordPress sets up a login cookie.
	 *
	 * Companion to aioi_wp_login() for logins that never fire 'wp_login'. The
	 * user ID comes from the action, since the current user is not set up yet.
	 *
	 * Every wp_set_auth_cookie() call fires this, which covers every
	 * authentication event in core, and it runs ahead of the 'send_auth_cookies'
	 * filter. So a plugin that suppresses the cookie for an unfinished login
	 * still resets the clock, which is harmless: there is no session until the
	 * second step issues a cookie and fires this again.
	 *
	 * @param string $logged_in_cookie The logged-in cookie value.
	 * @param int    $expire           When the cookie expires as a UNIX timestamp.
	 * @param int    $expiration       When the session expires as a UNIX timestamp.
	 * @param int    $user_id          The user the cookie was issued for.
	 */
	public function aioi_set_logged_in_cookie( $logged_in_cookie, $expire, $expiration, $user_id ) {

		$user_id = (int) $user_id;

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, 'aioi_last_activity_time', time() );
		}
	}

	/**
	 * AUTO-LOGOUT.
	 * Check whether the user should be auto-logged out this time.
	 */
	public function aioi_check_activity() {

		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id            = get_current_user_id();
		$last_activity_time = (int) get_user_meta( $user_id, 'aioi_last_activity_time', true );
		$logout_time_in_sec = $this->get_autologout_time_in_seconds();

		if (
			$logout_time_in_sec > 0 &&
			$last_activity_time + $logout_time_in_sec < time()
		) {
			// Bounce the user back to the page they were on so the login wall catches
			// them. The request URI only, never the Host header, which an attacker
			// controls. esc_url_raw() keeps percent-encoding that sanitize_text_field()
			// would strip, and wp_safe_redirect() sends an off-site target such as
			// "//evil.com/x" to wp-admin instead, so nothing here needs is_ssl().
			$current_url = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

			wp_logout();
			// Should hit the Login wall if site is private.
			wp_safe_redirect( $current_url );
			exit;
		}

		update_user_meta( $user_id, 'aioi_last_activity_time', time() );
	}

	protected function get_autologout_time_in_seconds() {

		$options = $this->get_option_aioi();

		$options['aioi_autologout_time'] = (int) $options['aioi_autologout_time'];

		if ( $options['aioi_autologout_time'] === 0 ) {
			return 0;
		}

		switch ( $options['aioi_autologout_units'] ) {
			case 'days':
				return $options['aioi_autologout_time'] * DAY_IN_SECONDS;

			case 'hours':
				return $options['aioi_autologout_time'] * HOUR_IN_SECONDS;

			case 'minutes':
			default:
				return $options['aioi_autologout_time'] * MINUTE_IN_SECONDS;
		}
	}

	// MEMBERSHIP

	public function aioi_wpmu_new_user( $user_id ) {

		// Add this user to all default sub-sites, if required.
		$options      = $this->get_option_aioi();
		$default_role = $options['aioi_ms_membersrole'];

		if ( $default_role === '' ) {
			return;
		}

		$blogs = $this->get_all_blogids();

		foreach ( $blogs as $blogid ) {
			if ( ! is_user_member_of_blog( $user_id, $blogid ) ) {
				add_user_to_blog( $blogid, $user_id, $default_role );
			}
		}
	}

	/**
	 * Add every existing user to a newly created sub-site, if a default role is set.
	 *
	 * @param WP_Site $new_site The sub-site that was just created.
	 * @param array   $args     Arguments the site was initialized with.
	 */
	public function aioi_wp_initialize_site( $new_site, $args = [] ) {

		// Add all other users to this new sub-site, if required.
		$options      = $this->get_option_aioi();
		$default_role = $options['aioi_ms_membersrole'];

		if ( $default_role === '' ) {
			return;
		}

		$blog_id = (int) ( $new_site->id ?? 0 );

		if ( $blog_id === 0 ) {
			return;
		}

		// Core passes the sub-site creator in $args.
		$creator_id = (int) ( $args['user_id'] ?? 0 );

		foreach ( $this->get_all_userids() as $auserid ) {
			// Core already made the creator an administrator here. Cast both sides, or
			// the strict compare never matches and the creator is demoted.
			if ( (int) $auserid !== $creator_id ) {
				add_user_to_blog( $blog_id, $auserid, $default_role );
			}
		}
	}

	private function get_all_blogids() {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$blogids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM $wpdb->blogs WHERE site_id = %d AND archived = '0' AND spam = '0' AND deleted = '0'", $wpdb->siteid ) );

		return is_array( $blogids ) ? $blogids : [];
	}

	private function get_all_userids() {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$userids = $wpdb->get_col( "SELECT ID FROM $wpdb->users" );

		return is_array( $userids ) ? $userids : [];
	}

	// PUT SETTINGS MENU ON PLUGINS PAGE

	protected function get_options_name() {
		return 'aioi_dsl';
	}

	protected function get_options_menuname() {
		return 'aioi_list_options';
	}

	protected function get_options_pagename() {
		return 'aioi_options';
	}

	protected function get_settings_url() {

		return is_multisite()
			? network_admin_url( 'settings.php?page=' . $this->get_options_menuname() )
			: admin_url( 'options-general.php?page=' . $this->get_options_menuname() );
	}

	/**
	 * Register plugin settings.
	 */
	public function aioi_admin_init() {

		register_setting(
			$this->get_options_pagename(),
			$this->get_options_name(),
			[ $this, 'aioi_options_validate' ]
		);
	}

	/**
	 * ADMIN AREA.
	 * Put settings menu on the plugins page.
	 *
	 * @param array  $links
	 * @param string $file
	 *
	 * @return array
	 */
	public function aioi_plugin_action_links( $links, $file ) {

		if ( $file === $this->my_plugin_basename() ) {
			$settings_link = '<a href="' . esc_url( $this->get_settings_url() ) . '">' . esc_html__( 'Settings', 'all-in-one-intranet' ) . '</a>';
			array_unshift( $links, $settings_link );
		}

		return $links;
	}

	/**
	 * Process values before saving to DB.
	 *
	 * @param array $input Values to save.
	 *
	 * @return array Validated values.
	 */
	public function aioi_options_validate( $input ) {

		$newinput                          = [];
		$newinput['aioi_version']          = $this->PLUGIN_VERSION;
		$newinput['aioi_privatesite']      = isset( $input['aioi_privatesite'] ) && (bool) $input['aioi_privatesite'];
		$newinput['aioi_ms_requiremember'] = isset( $input['aioi_ms_requiremember'] ) && (bool) $input['aioi_ms_requiremember'];
		$newinput['aioi_autologout_time']  = isset( $input['aioi_autologout_time'] ) ? (int) $input['aioi_autologout_time'] : 0;

		if ( ! preg_match( '/^[0-9]*$/i', $newinput['aioi_autologout_time'] ) ) {
			add_settings_error(
				'aioi_autologout_time',
				'nan_texterror',
				$this->get_error_string( 'aioi_autologout_time|nan_texterror' ),
				'error'
			);
			$newinput['aioi_autologout_time'] = 0;
		} else {
			$newinput['aioi_autologout_time'] = (int) $newinput['aioi_autologout_time'];
		}

		$newinput['aioi_autologout_units'] = isset( $input['aioi_autologout_units'] ) ? $input['aioi_autologout_units'] : '';
		if ( ! in_array( $newinput['aioi_autologout_units'], [ 'minutes', 'hours', 'days' ], true ) ) {
			$newinput['aioi_autologout_units'] = 'minutes';
		}

		// Normalize the post-login redirect before sanitizing. A bare path like
		// "dashboard" has no scheme, so esc_url_raw() would rewrite it to
		// "http://dashboard"; a leading slash keeps it site-relative. Anything that
		// already starts with "/" or carries a scheme is left alone. Only re-saving
		// normalizes a stored value.
		$redirect = isset( $input['aioi_loginredirect'] ) ? trim( $input['aioi_loginredirect'] ) : '';

		if ( $redirect !== '' && strpos( $redirect, '/' ) !== 0 && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $redirect ) ) {
			$redirect = '/' . $redirect;
		}
		$newinput['aioi_loginredirect'] = esc_url_raw( $redirect );

		$members_role = isset( $input['aioi_ms_membersrole'] ) ? sanitize_text_field( $input['aioi_ms_membersrole'] ) : '';
		// Allowlist against registered roles so a crafted POST or a stale role from a
		// removed provider can't persist; '' keeps the "None" (auto-membership off) option.
		$newinput['aioi_ms_membersrole'] = ( $members_role === '' || array_key_exists( $members_role, get_editable_roles() ) ) ? $members_role : '';

		return $newinput;
	}

	/**
	 * ADMIN AREA.
	 * Register plugin Settings in the admin area.
	 */
	public function aioi_admin_menu() {

		if ( is_multisite() ) {
			add_submenu_page(
				'settings.php',
				esc_html__( 'All-In-One Intranet settings', 'all-in-one-intranet' ),
				esc_html__( 'All-In-One Intranet', 'all-in-one-intranet' ),
				'manage_network_options',
				$this->get_options_menuname(),
				[ $this, 'aioi_options_do_page' ]
			);
		} else {
			add_options_page(
				esc_html__( 'All-In-One Intranet settings', 'all-in-one-intranet' ),
				esc_html__( 'All-In-One Intranet', 'all-in-one-intranet' ),
				'manage_options',
				$this->get_options_menuname(),
				[ $this, 'aioi_options_do_page' ]
			);
		}
	}

	/**
	 * ADMIN AREA.
	 * Render a plugin settings page.
	 */
	public function aioi_options_do_page() {

		wp_enqueue_script( 'aioi_admin', $this->my_plugin_url() . 'js/aioi-admin.js', [ 'jquery' ], $this->PLUGIN_VERSION, true );
		wp_enqueue_style( 'aioi_admin', $this->my_plugin_url() . 'css/style.css', [], $this->PLUGIN_VERSION );

		$submit_page = is_multisite() ? 'edit.php?action=' . $this->get_options_menuname() : 'options.php';
		?>

		<h1><?php esc_html_e( 'All-In-One Intranet', 'all-in-one-intranet' ); ?></h1>

		<div id="gal-tablewrapper">

			<div id="gal-tableleft" class="gal-tablecell">
				<hr/>

				<?php
				if ( is_multisite() ) {
					$this->aioi_options_do_network_errors();
				}
				?>

				<form action="<?php echo esc_attr( $submit_page ); ?>" method="post">

					<?php
					settings_fields( $this->get_options_pagename() );

					$this->aioi_privacysection_text();
					$this->aioi_memberssection_text();
					$this->aioi_loginredirectsection_text();
					$this->aioi_autologoutsection_text();
					$this->aioi_licensesection_text();

					submit_button();
					?>

				</form>
			</div>
			<?php $this->ga_options_do_sidebar(); ?>
		</div>

		<?php
	}

	/**
	 * ADMIN AREA.
	 * Render the privacy checkbox section.
	 */
	protected function aioi_privacysection_text() {

		$options = $this->get_option_aioi();
		?>

		<h3><?php esc_html_e( 'Privacy', 'all-in-one-intranet' ); ?></h3>

		<input id='input_aioi_privatesite' name='<?php echo esc_attr( $this->get_options_name() ); ?>[aioi_privatesite]' type='checkbox' <?php checked( (bool) $options['aioi_privatesite'] ); ?> class='checkbox' />
		<label for="input_aioi_privatesite" class="checkbox plain">
			<?php esc_html_e( 'Force site to be entirely private', 'all-in-one-intranet' ); ?>
		</label>

		<br />

		<?php if ( is_multisite() ) : ?>
			<input id='input_aioi_ms_requiremember' name='<?php echo esc_attr( $this->get_options_name() ); ?>[aioi_ms_requiremember]' type='checkbox' <?php checked( (bool) $options['aioi_ms_requiremember'] ); ?> class='checkbox' />
			<label for="input_aioi_ms_requiremember" class="checkbox plain">
			<?php esc_html_e( 'Require logged-in users to be members of a sub-site to view it', 'all-in-one-intranet' ); ?>
			</label>

			<br />
		<?php endif; ?>

		<p><?php esc_html_e( 'Note that your media uploads (e.g. photos) will still be accessible to anyone who knows their direct URLs.', 'all-in-one-intranet' ); ?></p>

		<?php $this->display_registration_warning(); ?>

		<br />

		<?php
	}

	/**
	 * ADMIN AREA.
	 * Render the warning message that anyone can register.
	 */
	protected function display_registration_warning() {

		if ( ! is_multisite() ) {

			if ( get_option( 'users_can_register' ) ) : ?>
				<div class="notice error" style="margin-left: 0">
					<p>
						<strong><?php esc_html_e( 'Warning:', 'all-in-one-intranet' ); ?></strong>
						<?php esc_html_e( 'Your site is set so that "Anyone can register" themselves.', 'all-in-one-intranet' ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
							<?php esc_html_e( 'Change settings here.', 'all-in-one-intranet' ); ?>
						</a>
					</p>
				</div>
			<?php endif;

			return;
		}

		// We are in a multisite.

		if ( in_array( get_site_option( 'registration' ), [ 'all', 'user' ] ) ) {
			$limited_domains = get_site_option( 'limited_email_domains' );

			echo '<div class="notice error" style="margin-left: 0"><p>' .
				 '<strong>' . esc_html__( 'Warning:', 'all-in-one-intranet' ) . '</strong>';

			if ( is_array( $limited_domains ) && count( $limited_domains ) > 0 ) {
				 esc_html_e( 'Your site is set so that "Anyone can register" themselves, provided they are members of one of the following domains:', 'all-in-one-intranet' );
				echo ' ' . esc_html( implode( ', ', $limited_domains ) );
			} else {
				esc_html_e( 'Warning: Your site is set so that "Anyone can register" themselves.', 'all-in-one-intranet' );
			}

			echo ' <a href="' . esc_url( network_admin_url( 'settings.php' ) ) . '">' . esc_html__( 'Change settings here.', 'all-in-one-intranet' ) . '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * ADMIN AREA.
	 * Deal with members of sub-sites in a multisite.
	 */
	protected function aioi_memberssection_text() {

		$options = $this->get_option_aioi();

		if ( ! is_multisite() ) {
			return;
		}
		?>

		<h3><?php esc_html_e( 'Sub-site Membership', 'all-in-one-intranet' ); ?></h3>

		<label for="input_aioi_ms_membersrole" class="textbox plain">
			<?php esc_html_e( 'Users should default to the following role in all sub-sites', 'all-in-one-intranet' ); ?>
		</label>

		<select name="<?php echo esc_attr( $this->get_options_name() ); ?>[aioi_ms_membersrole]" id="input_aioi_ms_membersrole">
			<option value="">-- <?php esc_html_e( 'None', 'all-in-one-intranet' ); ?> --</option>
			<?php wp_dropdown_roles( $options['aioi_ms_membersrole'] ); ?>
		</select>

		<p><?php esc_html_e( 'Changing the default role here will not affect existing sub-sites and users.', 'all-in-one-intranet' ); ?></p>
		<br />

		<?php
	}

	/**
	 * ADMIN AREA.
	 * Render the login redirect section.
	 */
	protected function aioi_loginredirectsection_text() {

		$options = $this->get_option_aioi();
		?>

		<h3><?php esc_html_e( 'Login Redirect', 'all-in-one-intranet' ); ?></h3>

		<label for="input_aioi_loginredirect" class="textbox plain">
			<?php esc_html_e( 'Redirect after login to URL: ', 'all-in-one-intranet' ); ?>
		</label>

		<input id='input_aioi_loginredirect' name='<?php echo esc_attr( $this->get_options_name() ); ?>[aioi_loginredirect]' type='text' value='<?php echo esc_attr( $options['aioi_loginredirect'] ); ?>' size='60' />

		<br />

		<p><?php esc_html_e( 'Effective when users login via /wp-login.php directly. Otherwise, they will be taken to the page they were trying to access before being required to login.', 'all-in-one-intranet' ); ?></p>

		<br />
		<?php
	}

	/**
	 * ADMIN AREA.
	 * Render the auto logout section.
	 */
	protected function aioi_autologoutsection_text() {

		$options = $this->get_option_aioi();
		?>

		<h3><?php esc_html_e( 'Auto Logout', 'all-in-one-intranet' ); ?></h3>

		<label for="input_aioi_autologout_time" class="textbox plain">
			<?php esc_html_e( 'Auto logout inactive users after ', 'all-in-one-intranet' ); ?>
		</label>

		<input id='input_aioi_autologout_time' name='<?php echo esc_attr( $this->get_options_name() ); ?>[aioi_autologout_time]' type='number' value='<?php echo (int) $options['aioi_autologout_time'] === 0 ? '' : (int) $options['aioi_autologout_time']; ?>' class="small-text" />

		<select name='<?php echo esc_attr( $this->get_options_name() ); ?>[aioi_autologout_units]'>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->list_options( [ 'minutes', 'hours', 'days' ], $options['aioi_autologout_units'] );
			?>
		</select>
		<?php esc_html_e( "(leave blank to turn off auto-logout)", 'all-in-one-intranet' ); ?>.

		<br />
		<?php
	}

	/**
	 * Helper function to render the options for autologout time units.
	 *
	 * @param array $list     List of options keys.
	 * @param string $current Current option from DB.
	 *
	 * @return string
	 */
	protected function list_options( $list, $current ) {

		$output = '';
		$labels = [
			'minutes' => __( 'Minutes', 'all-in-one-intranet' ),
			'hours'   => __( 'Hours', 'all-in-one-intranet' ),
			'days'    => __( 'Days', 'all-in-one-intranet' ),
		];

		foreach ( $list as $option ) {
			$output .= '<option value="' . esc_attr( $option ) . '" ' . selected( $current, $option ) . '>' . esc_html( $labels[ $option ] ) . '</option>';
		}

		return $output;
	}

	/**
	 * Override in Premium.
	 */
	protected function aioi_licensesection_text() {
	}

	protected function ga_options_do_sidebar() {

		$drivelink   = 'https://wp-glogin.com/drive/?utm_source=aioiplugin&utm_campaign=liteplugin&utm_medium=Admin%20Sidebar&utm_content=GDrive';
		$gloginlink  = 'https://wp-glogin.com/glogin/?utm_source=aioiplugin&utm_campaign=liteplugin&utm_medium=Admin%20Sidebar&utm_content=GLogin';
		$avatarslink = 'https://wp-glogin.com/avatars/?utm_source=aioiplugin&utm_campaign=liteplugin&utm_medium=Admin%20Sidebar&utm_content=GAvatars';

		$adverts = [];

		$adverts[] = '<div>'
		             . '<a href="' . esc_url( $gloginlink ) . '" target="_blank">'
		             . '<img alt="Google Apps Login plugin" src="' . esc_url( $this->my_plugin_url() ) . 'img/basic_loginupgrade.png" />'
		             . '</a>'
		             . '<span>Try our <a href="' . esc_url( $gloginlink ) . '" target="_blank">premium Google Apps Login plugin</a> to revolutionize user management</span>'
		             . '</div>';

		$adverts[] = '<div>'
		             . '<a href="' . esc_url( $drivelink ) . '" target="_blank">'
		             . '<img alt="Google Drive Embedder plugin" src="' . esc_url( $this->my_plugin_url() ) . 'img/basic_driveplugin.png" />'
		             . '</a>'
		             . '<span>Check our <a href="' . esc_url( $drivelink ) . '" target="_blank">Google Drive Embedder</a> plugin to embed files from Drive</span>'
		             . '</div>';

		$adverts[] = '<div>'
		             . '<a href="' . esc_url( $avatarslink ) . '" target="_blank">'
		             . '<img alt="Google Profile Avatars Plugin" src="' . esc_url( $this->my_plugin_url() ) . 'img/basic_avatars.png" />'
		             . '</a>'
		             . '<span>Bring your site to life with <a href="' . esc_url( $avatarslink ) . '" target="_blank">Google Profile Avatars</a></span>'
		             . '</div>';

		$startnum = (int) gmdate( 'j' );

		echo '<div id="gal-tableright" class="gal-tablecell">';

		for ( $i = 0; $i < 2; $i ++ ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $adverts[ ( $startnum + $i ) % 3 ];
		}

		echo '</div>';
	}

	/**
	 * Retrieve a custom error message based on the provided error key.
	 *
	 * @param string $fielderror Error key.
	 *
	 * @return string
	 */
	protected function get_error_string( $fielderror ) {

		$local_error_strings = [
			'aioi_autologout_time|nan_texterror' => esc_html__( 'Auto logout time should be blank or a whole number', 'all-in-one-intranet' ),
		];

		if ( isset( $local_error_strings[ $fielderror ] ) ) {
			return $local_error_strings[ $fielderror ];
		}

		return esc_html__( 'Unspecified error', 'all-in-one-intranet' );
	}

	/**
	 * Save the network options.
	 */
	public function aioi_save_network_options() {

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to perform this action.', 'all-in-one-intranet' ),
				'',
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( $this->get_options_pagename() . '-options' );

		if ( isset( $_POST[ $this->get_options_name() ] ) && is_array( $_POST[ $this->get_options_name() ] ) ) {
			$inoptions  = $_POST[ $this->get_options_name() ];
			$outoptions = $this->aioi_options_validate( $inoptions );

			$error_code    = [];
			$error_setting = [];
			foreach ( get_settings_errors() as $e ) {
				if ( is_array( $e ) && isset( $e['code'] ) && isset( $e['setting'] ) ) {
					$error_code[]    = $e['code'];
					$error_setting[] = $e['setting'];
				}
			}

			update_site_option( $this->get_options_name(), $outoptions );

			// Redirect to settings page in network.
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			wp_redirect(
				add_query_arg(
					[
						'page'          => $this->get_options_menuname(),
						'updated'       => true,
						'error_setting' => $error_setting,
						'error_code'    => $error_code,
					],
					network_admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Display a network error message upon settings save, if any.
	 */
	protected function aioi_options_do_network_errors() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		if ( isset( $_REQUEST['updated'] ) && $_REQUEST['updated'] ) {
			?>
			<div id="setting-error-settings_updated" class="updated settings-error">
				<p>
					<strong><?php esc_html_e( 'Settings saved', 'all-in-one-intranet' ); ?></strong>
				</p>
			</div>
			<?php
		}

		if (
			isset( $_REQUEST['error_setting'], $_REQUEST['error_code'] ) &&
			is_array( $_REQUEST['error_setting'] ) && is_array( $_REQUEST['error_code'] )
		) {
			$error_code       = $_REQUEST['error_code'];
			$error_setting    = $_REQUEST['error_setting'];
			$error_code_count = count( $error_code );

			if ( $error_code_count > 0 && $error_code_count === count( $error_setting ) ) {
				for ( $i = 0; $i < $error_code_count; ++ $i ) {
					?>
					<div id="setting-error-settings_<?php echo (int) $i; ?>" class="error settings-error">
						<p>
							<strong><?php echo esc_html( htmlentities2( $this->get_error_string( $error_setting[ $i ] . '|' . $error_code[ $i ] ) ) ); ?></strong>
						</p>
					</div>
					<?php
				}
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get the options from the database.
	 *
	 * @return array
	 */
	protected function get_option_aioi() {

		if ( $this->aioi_options !== null ) {
			return $this->aioi_options;
		}

		$option = get_site_option( $this->get_options_name(), [] );

		$default_options = $this->get_default_options();

		// Hydrate currently saved options with their default values, if missing.
		foreach ( $default_options as $k => $v ) {
			if ( ! isset( $option[ $k ] ) ) {
				$option[ $k ] = $v;
			}
		}

		$this->aioi_options = $option;

		return $this->aioi_options;
	}

}
