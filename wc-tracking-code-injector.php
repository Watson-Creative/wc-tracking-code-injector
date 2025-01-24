<?php
/*
Plugin Name: WC Tracking Code Injector
Plugin URI: https://github.com/Watson-Creative/wc-tracking-code-injector
GitHub Plugin URI: https://github.com/Watson-Creative/wc-tracking-code-injector
description: Add tags for Sentry.IO, Google Analytics, Google Tag Manager, Hubspot and Facebook code in appropriate locations globally from WP Admin menu. Code is only printed in a live Pantheon environment to prevent skewing data with traffic on the development or testing environments.
Version: 2.4.4
Author: Spencer Thayer, Hunter Watson, Alex Tryon
Author URI: https://watsoncreative.com
License: GPL2
 */

// Prevent direct access to the file.
defined('ABSPATH') or die('No script kiddies please!');

// Define Pantheon environment if not already defined
if (!defined('PANTHEON_ENVIRONMENT')) {
    define('PANTHEON_ENVIRONMENT', getenv('PANTHEON_ENVIRONMENT') ?: null);
}

// Define plugin directory constants if not already defined
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', dirname(dirname(__FILE__)));
}

require_once __DIR__ . '/updater.php';

// Force refresh plugin cache on activation
register_activation_hook(__FILE__, function() {
    delete_site_transient('update_plugins');
    wp_clean_plugins_cache();
});

class WatsonPixelTracking {
    private $config;

    // Constructor
    public function __construct() {
        add_action('admin_init', [$this, 'cleanup_legacy_updater']);
        add_action('init', [$this, 'init_github_updater']);
        if (is_admin()) {
            add_action('admin_menu', [$this, 'create_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            // Add update check link
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_update_check_link']);
            add_action('admin_init', [$this, 'handle_force_update_check']);
        }
        add_action('wp_head', [$this, 'print_code_head']);
        if (defined('PANTHEON_ENVIRONMENT') && PANTHEON_ENVIRONMENT === 'live') {
            add_filter('body_class', [$this, 'add_body_class']);
        }
        add_action('admin_init', [$this, 'create_default_values']);

        // Store config for update checks
        $this->config = array(
            'slug' => plugin_basename(__FILE__),
            'proper_folder_name' => dirname(plugin_basename(__FILE__))
        );

        // Add debug shortcode
        add_shortcode('github_updater_debug', [$this, 'debug_shortcode']);
    }

    public function cleanup_legacy_updater() {
        // Remove legacy updater plugin if it's still installed
        if (file_exists(WP_PLUGIN_DIR . '/wp-github-plugin-updater-test/wp-github-plugin-updater-test.php')) {
            deactivate_plugins('wp-github-plugin-updater-test/wp-github-plugin-updater-test.php');
            delete_plugins(array('wp-github-plugin-updater-test/wp-github-plugin-updater-test.php'));
        }
    }

    public function init_github_updater() {
        if (!is_admin()) return;

        $config = array(
            'main_plugin_file' => __FILE__,
            'slug' => plugin_basename(__FILE__),
            'proper_folder_name' => dirname(plugin_basename(__FILE__)),
            'api_url' => 'https://api.github.com/repos/Watson-Creative/wc-tracking-code-injector',
            'raw_url' => 'https://raw.githubusercontent.com/Watson-Creative/wc-tracking-code-injector/main',
            'github_url' => 'https://github.com/Watson-Creative/wc-tracking-code-injector',
            'zip_url' => 'https://github.com/Watson-Creative/wc-tracking-code-injector/archive/refs/heads/main.zip',
            'sslverify' => true,
            'requires' => '6.0',
            'tested' => '6.5',
            'readme' => 'README.md',
            'access_token' => '',
        );

        if (class_exists('WP_GitHub_Updater')) {
            new WP_GitHub_Updater($config);
        }
    }

    // Force transient refresh method
    public function force_transient_refresh() {
        delete_site_transient('update_plugins');
        delete_site_transient(md5($this->config['slug']) . '_github_data');
        delete_site_transient(md5($this->config['slug']) . '_new_version');
    }

    // Handle forced update check
    public function handle_force_update_check() {
        if (!isset($_GET['force_update_check']) || 
            !wp_verify_nonce($_GET['_wpnonce'], 'force_update_check') ||
            !current_user_can('update_plugins')) {
            return;
        }

        // Clear update transients
        delete_site_transient('update_plugins');
        delete_site_transient(md5(plugin_basename(__FILE__)) . '_github_data');
        delete_site_transient(md5(plugin_basename(__FILE__)) . '_new_version');

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible">
                <p>Successfully checked for updates. Refresh the page to see results.</p>
            </div>';
        });

        wp_redirect(admin_url('plugins.php'));
        exit;
    }

    public function debug_shortcode() {
        if (!current_user_can('update_plugins')) return '';
        
        $output = '<h3>GitHub Updater Debug Info</h3>';
        $output .= '<pre>';
        $output .= 'Plugin: ' . $this->config['slug'] . "\n";
        $output .= 'Last Error: ' . print_r(get_option('github_updater_error_' . $this->config['slug']), true) . "\n";
        $output .= 'Update Data: ' . print_r(get_site_transient(md5($this->config['slug']) . '_github_data'), true);
        $output .= '</pre>';
        return $output;
    }

    public function create_menu() {
        add_menu_page(
            'Tracking Code Injector',
            'Tracking Code Injector',
            'administrator',
            __FILE__,
            [$this, 'settings_page'],
            plugins_url('img/ga.png', __FILE__)
        );
    }

	function custom_inject_code_sanitization( $input ) {
		// Allow only specific script tags
		$allowed_html = array(
			'script' => array(
				'type' => array(),
				'src' => array(),
				'async' => array(),
				// Add any other attributes that need to be allowed
			),
			// Include other tags that need to be allowed
		);
	
		return wp_kses( $input, $allowed_html );
	}

    public function register_settings() {
        register_setting('ga-inject-option-group', 'ga_inject_code', 'sanitize_text_field');
        register_setting('ga-inject-option-group', 'gtm_inject_code', 'sanitize_text_field');
        register_setting('ga-inject-option-group', 'fb_pixel_code', 'sanitize_text_field');
        register_setting('ga-inject-option-group', 'ga4_measurement_id', 'sanitize_text_field');
        register_setting('ga-inject-option-group', 'sentry_dsn', 'sanitize_text_field');
        register_setting('ga-inject-option-group', 'hbs_pixel_code', 'sanitize_text_field');
        register_setting('ga-inject-option-group', 'custom_inject_code', 'custom_inject_code_sanitization');
        register_setting('ga-inject-option-group', 'google_site_verification', 'sanitize_text_field');
        register_setting('ga-inject-option-group', 'hotjar_hjid', 'sanitize_text_field');
    }

    public function create_default_values() {
        if (!get_option('ga_inject_code')) {
            update_option("ga_inject_code", 'UA-XXXXX-X');
        }
        if (!get_option('ga4_measurement_id')) {
            update_option("ga4_measurement_id", 'G-XXXXXX');
        }
        if (!get_option('gtm_inject_code')) {
            update_option("gtm_inject_code", 'GTM-XXXX');
        }
        if (!get_option('fb_pixel_code')) {
            update_option("fb_pixel_code", '###############');
        }
        if (!get_option('sentry_dsn')) {
            update_option("sentry_dsn", '');
        }
        if (!get_option('hbs_pixel_code')) {
            update_option('hbs_pixel_code', '########');
        }
        if (!get_option('google_site_verification')) {
            update_option('google_site_verification', '');
        }
        if (!get_option('hotjar_hjid')) {
            update_option('hotjar_hjid', '');
        }
    }

	public function print_code_head() {
		if (defined('PANTHEON_ENVIRONMENT') && PANTHEON_ENVIRONMENT === 'live') {
			
			// Google Site Verification
			$SITE_VERIFICATION = get_option('google_site_verification');
			if (is_string($SITE_VERIFICATION) && strlen(trim($SITE_VERIFICATION)) > 0) {
				echo "\n<!-- Google Site Verification -->\n";
				echo '<meta name="google-site-verification" content="' . esc_attr($SITE_VERIFICATION) . '" />';
				echo "\n<!-- End Google Site Verification -->\n";
			}
			
			// Google Analytics Header Code
			$GA_CODE = get_option("ga_inject_code");
			$GA_MEASUREMENT_ID = get_option("ga4_measurement_id");
			if (!$GA_MEASUREMENT_ID || $GA_MEASUREMENT_ID === 'G-XXXXXX') {
				$GA_MEASUREMENT_ID = false;
			}
			if ($GA_CODE && $GA_CODE !== 'UA-XXXXX-X') {
				echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $GA_CODE . '"></script>
					  <script>window.dataLayer = window.dataLayer || [];
					  function gtag(){dataLayer.push(arguments);}
					  gtag("js", new Date());';
				echo 'gtag("config", "' . $GA_CODE . '");';
				if ($GA_MEASUREMENT_ID) {
					echo 'gtag("config", "' . $GA_MEASUREMENT_ID . '");';
				}
				echo '</script>';
			}
	
			// Google Tag Manager Header Code
			$GTM_CODE = get_option("gtm_inject_code");
			if ($GTM_CODE && $GTM_CODE !== 'GTM-XXXX') {
				echo "<!-- Google Tag Manager -->
					  <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
					  new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
					  j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
					  'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
					  })(window,document,'script','dataLayer','" . $GTM_CODE . "');</script>
					  <!-- End Google Tag Manager -->";
			}
	
			// Facebook Pixel Code
			$FB_PIXEL_CODE = get_option("fb_pixel_code");
			if ($FB_PIXEL_CODE && $FB_PIXEL_CODE !== '###############') {
				echo '<!-- Facebook Pixel Code -->
					  <script>
					  !function(f,b,e,v,n,t,s)
					  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
					  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
					  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version=\'2.0\';
					  n.queue=[];t=b.createElement(e);t.async=!0;
					  t.src=v;s=b.getElementsByTagName(e)[0];
					  s.parentNode.insertBefore(t,s)}(window, document,\'script\',
					  \'https://connect.facebook.net/en_US/fbevents.js\');
					  fbq(\'init\', \'' . $FB_PIXEL_CODE . '\');
					  fbq(\'track\', \'PageView\');
					  </script>
					  <noscript><img height="1" width="1" style="display:none"
					  src="https://www.facebook.com/tr?id=' . $FB_PIXEL_CODE . '&ev=PageView&noscript=1"
					  /></noscript>
					  <!-- End Facebook Pixel Code -->';
			}
	
			// HubSpot Embed Code
			$HBS_CODE = get_option("hbs_pixel_code");
			if (is_string($HBS_CODE)) {
				$HBS_CODE = trim($HBS_CODE);
			}
			if ($HBS_CODE && strlen($HBS_CODE) > 0 && $HBS_CODE !== '########') {
				echo "\n<!-- Start of HubSpot Embed Code -->\n";
				echo '<script type="text/javascript" id="hs-script-loader" async defer src="//js.hs-scripts.com/'.$HBS_CODE.'.js"></script>';
				echo "\n<!-- End of HubSpot Embed Code -->\n";
			}
	
			// HotJar Tracking Code
			$HOTJAR_HJID = get_option('hotjar_hjid');
			if (!empty($HOTJAR_HJID)) {
				echo "\n<!-- Hotjar Tracking Code -->\n";
				echo '<script>
					(function(h,o,t,j,a,r){
						h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};
						h._hjSettings={hjid:' . esc_js($HOTJAR_HJID) . ',hjsv:6};
						a=o.getElementsByTagName("head")[0];
						r=o.createElement("script");r.async=1;
						r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;
						a.appendChild(r);
					})(window,document,"https://static.hotjar.com/c/hotjar-",".js?sv=");
				</script>';
				echo "\n<!-- End Hotjar Tracking Code -->\n";
			}
	
			// Custom Code
			$CUSTOM_CODE = get_option("custom_inject_code");
			if (is_string($CUSTOM_CODE)) {
				$CUSTOM_CODE = trim($CUSTOM_CODE);
			}
			if ($CUSTOM_CODE && strlen($CUSTOM_CODE) > 0) {
				echo "\n<!-- Custom Code -->\n";
				echo $CUSTOM_CODE;
				echo "\n<!-- End Custom Code -->\n";
			}
		}
	
		// Sentry.IO for all environments (dev/test/live)
		$SENTRY_DSN = get_option("sentry_dsn");
		if (is_string($SENTRY_DSN)) {
			$SENTRY_DSN = trim($SENTRY_DSN);
		}
		if ($SENTRY_DSN && strlen($SENTRY_DSN) > 0) {
			echo "\n<!-- Sentry.IO -->\n";
			echo '<script
				  src="https://browser.sentry-cdn.com/7.17.1/bundle.min.js"
				  integrity="sha384-vNdCKj9jIX+c41215wXDL6Xap/hZNJ8oyy/om470NxVJHff8VAQck1xu53ZYZ7wI"
				  crossorigin="anonymous"
				  ></script>
				  <script>
				  Sentry.init({
				  dsn: "'.$SENTRY_DSN.'",
				  environment: window.location.host
				  });
				  </script>';
			echo "\n<!-- End Sentry.IO -->\n";
		}
	}

    public function add_body_class($classes) {
        $GTM_CODE = get_option("gtm_inject_code");
        if ($GTM_CODE && $GTM_CODE !== 'GTM-XXXX') {
            $PRINT_CODE = '<!-- Google Tag Manager (noscript) -->';
            $classes[] = '">' . $PRINT_CODE . '<br style="display:none';
            return $classes;
        }
        return $classes;
    }

	public function settings_page() {
		?>
		<div class="wrap">
			<img id="watson-branding" src="<?php echo plugins_url('img/WC_Brand_Signature.png', __FILE__); ?>" style="max-width:400px;">
			<h1>Watson Creative Tracking Code Injector</h1>
			<h2>Note: These tracking tags are only active on the <b>Live</b> Pantheon Environment</h2>
			<form method="post" action="options.php">
				<?php
				settings_fields('ga-inject-option-group');
				do_settings_sections('ga-inject-option-group');
				?>
				<table class="form-table ga-inject-code-options">
					<tr valign="top">
						<th scope="row">Google Site Verification</th>
						<td><input type="text" name="google_site_verification" value="<?php echo esc_attr(get_option('google_site_verification')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Google Analytics Tracking Code (UA-XXXXX-X)</th>
						<td><input type="text" name="ga_inject_code" value="<?php echo esc_attr(get_option('ga_inject_code')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Google Analytics 4 Measurement ID (G-XXXXXX)</th>
						<td><input type="text" name="ga4_measurement_id" value="<?php echo esc_attr(get_option('ga4_measurement_id')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Google Tag Manager Container ID (GTM-XXXX)</th>
						<td><input type="text" name="gtm_inject_code" value="<?php echo esc_attr(get_option('gtm_inject_code')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Facebook Pixel Code (###############)</th>
						<td><input type="text" name="fb_pixel_code" value="<?php echo esc_attr(get_option('fb_pixel_code')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Hubspot Tracking Code (########)</th>
						<td><input type="text" name="hbs_pixel_code" value="<?php echo esc_attr(get_option('hbs_pixel_code')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">HotJar Tracking ID (e.g., 5270198)</th>
						<td><input type="text" name="hotjar_hjid" value="<?php echo esc_attr(get_option('hotjar_hjid')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><small><i>ALL ENVIRONMENTS</i></small><br/>Sentry.IO DSN</th>
						<td><input type="text" name="sentry_dsn" value="<?php echo esc_attr(get_option('sentry_dsn')); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Schema.org Object Notation</th>
						<td><textarea name="custom_inject_code" rows="15" cols="80"><?php echo esc_attr(get_option('custom_inject_code')); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button('Save Changes'); ?>
			</form>
		</div>
		<?php
	}
	
    // Add "Check Updates" link next to deactivate button
    public function add_update_check_link($links) {
        if (!current_user_can('update_plugins')) return $links;
        
        $nonce = wp_create_nonce('force_update_check');
        $url = admin_url('plugins.php?force_update_check=1&_wpnonce=' . $nonce);
        $links['check_updates'] = '<a href="' . esc_url($url) . '" style="color:#3d9970;">Check for Updates</a>';
        
        return $links;
    }
}

// Initialize the plugin
new WatsonPixelTracking();