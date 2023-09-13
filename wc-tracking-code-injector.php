<?php
/*
Plugin Name: Watson Pixel Tracking Code Injector (WC)
Plugin URI: https://github.com/Watson-Creative/GA-Tracking-Code-Injector
GitHub Plugin URI: https://github.com/Watson-Creative/GA-Tracking-Code-Injector
description: Add tags for Sentry.IO, Google Analytics, Google Tag Manager, Hubspot and Facebook code in appropriate locations globally from WP Admin menu. Code is only printed in a live Pantheon environment to prevent skewing data with traffic on the development or testing environments.
Version: 2.3.3
Author: Spencer Thayer, Hunter Watson, Alex Tryon
Author URI: https://watsoncreative.com
License: GPL2
 */

if (is_admin()) {
	// admin actions
	add_action('admin_menu', 'ga_inject_create_menu');
	add_action('admin_init', 'register_ga_inject_settings');
}

function printGAcode_head() {
	if ($_ENV['PANTHEON_ENVIRONMENT'] == 'live') {

		// Google Analytics Header Code

		$GA_CODE = get_option("ga_inject_code");
		$GA_MEASUREMENT_ID = get_option("ga4_measurement_id");
		if(strlen($GA_MEASUREMENT_ID) == 0 || $GA_MEASUREMENT_ID == 'G-XXXXXX') {
			$GA_MEASUREMENT_ID = false;
		}

		if ($GA_CODE != 'UA-XXXXX-X' && $GA_CODE != ''):
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . get_option("ga_inject_code") . '"></script>
						<script>window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag("js", new Date());';
						
						echo 'gtag("config", "' . $GA_CODE . '");';

						if($GA_MEASUREMENT_ID !== false) {
							echo 'gtag("config", "' . $GA_MEASUREMENT_ID . '");';
						}
						
						echo '</script>';
		endif;

		// Google Tag Manager Header Code
		$GTM_CODE = get_option("gtm_inject_code");
		if ($GTM_CODE != 'GTM-XXXX' && $GTM_CODE != ''):
			echo "<!-- Google Tag Manager -->
						<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
						new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
						j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
						'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
						})(window,document,'script','dataLayer','" . $GTM_CODE . "');</script>
						<!-- End Google Tag Manager -->";
		endif;

		// Google Tag Manager Header Code
		$FB_PIXEL_CODE = get_option("fb_pixel_code");
		if ($FB_PIXEL_CODE != '###############' && $FB_PIXEL_CODE != ''):
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
		endif;

		// HubSpot Embed Code
		$HBS_CODE = get_option("hbs_pixel_code");
		if(is_string($HBS_CODE)) {
			$HBS_CODE = trim($HBS_CODE);
		}
		if ($HBS_CODE !== false && strlen($HBS_CODE) > 0):
			echo "\n<!-- Start of HubSpot Embed Code -->\n";
			echo '<script type="text/javascript" id="hs-script-loader" async defer src="//js.hs-scripts.com/'.$HBS_CODE.'.js"></script>';
			echo "\n<!-- End of HubSpot Embed Code -->\n";
		endif;

		// Custom Code
		$CUSTOM_CODE = get_option("custom_inject_code");
		if(is_string($CUSTOM_CODE)) {
			$CUSTOM_CODE = trim($CUSTOM_CODE);
		}
		if ($CUSTOM_CODE !== false && strlen($CUSTOM_CODE) > 0):
			echo "\n<!-- Custom Code -->\n";

			echo $CUSTOM_CODE;

			echo "\n<!-- End Custom Code -->\n";
		endif;

	}
	
	// Below here can show up on dev/test/live

	// Sentry.IO
	$SENTRY_DSN = get_option("sentry_dsn");
	if(is_string($SENTRY_DSN)) {
		$SENTRY_DSN = trim($SENTRY_DSN);
	}
	if ($SENTRY_DSN !== false && strlen($SENTRY_DSN) > 0):
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
	endif;

}
// add_action('wp_headers', 'printGAcode');
add_action('wp_head', 'printGAcode_head');

// filter hack via https://www.affectivia.com/blog/placing-the-google-tag-manager-in-wordpress-after-the-body-tag/
if ($_ENV['PANTHEON_ENVIRONMENT'] === 'live') {
	add_filter('body_class', 'gtm_add', 10000);
}

function gtm_add($classes) {

	$GTM_CODE = get_option("gtm_inject_code");

	if ($GTM_CODE != 'GTM-XXXX' && $GTM_CODE != ''):

		$PRINT_CODE = '<!-- Google Tag Manager (noscript) -->
				<noscript><iframe src="https://www.googletagmanager.com/ns.html?id="' . $GTM_CODE . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
				<!-- End Google Tag Manager (noscript) -->';

		$classes[] = '">' . $PRINT_CODE . '<br style="display:none';
		return $classes;

	else:
		return $classes;
	endif;
}

register_activation_hook(__FILE__, 'ga_create_default_values');
add_action('admin_init', 'ga_create_default_values'); // Fix: update new options regardless of activation status
function ga_create_default_values() {
	if (get_option('ga_inject_code') == false) {
		add_option("ga_inject_code", 'UA-XXXXX-X');
	}
	if (get_option('ga4_measurement_id') == false) {
		add_option("ga4_measurement_id", 'G-XXXXXX');
	}
	if (get_option('custom_inject_code') == false) {
		add_option("custom_inject_code", '');
	}
	if (get_option('gtm_inject_code') == false) {
		add_option("gtm_inject_code", 'GTM-XXXX');
	}
	if (get_option('fb_pixel_code') == false) {
		add_option("fb_pixel_code", '###############');
	}
	if (get_option('sentry_dsn') == false) {
		add_option("sentry_dsn", '');
	}
}

function register_ga_inject_settings() {
	// whitelist options
	register_setting('ga-inject-option-group', 'ga_inject_code');
	register_setting('ga-inject-option-group', 'gtm_inject_code');
	register_setting('ga-inject-option-group', 'fb_pixel_code');

	
	register_setting('ga-inject-option-group', 'ga4_measurement_id');
	register_setting('ga-inject-option-group', 'custom_inject_code');
	register_setting('ga-inject-option-group', 'sentry_dsn');
	
}

function ga_inject_create_menu() {

	//create new top-level menu
	add_menu_page('GA Code Injector Settings', 'GA Code Injector Settings', 'administrator', __FILE__, 'ga_inject_settings_page', plugins_url('img/ga.png', __FILE__));

	//call register settings function
	add_action('admin_init', 'register_ga_inject_settings');
}

function ga_inject_settings_page() {
	?>

<div class="wrap">
	<img id="watson-branding" src="<?php echo plugins_url('img/WC_Brand_Signature.png', __FILE__); ?>" style="max-width:400px;">
	<h1>Watson Creative Tracking Code Injector</h1>
	<h2>Note: These tracking tags are only active on the <b>Live</b> Pantheon Environment</h2>
	<form method="post" action="options.php">
		<?php
settings_fields('ga-inject-option-group');
	do_settings_sections('ga-inject-option-group');?>

		<table class="form-table ga-inject-code-options">

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
		        <th scope="row">Hubspot Tracking Code (########)<th>
		        <td><input type="text" name="hbs_pixel_code" value="<?php echo esc_attr(get_option('hbs_pixel_code')); ?>" /></td>
	        </tr>

	        <tr valign="top">
		        <th scope="row"><small><i>ALL ENVIRONMENTS</i></small><br/>Sentry.IO DSN</th>
		        <td><input type="text" name="sentry_dsn" value="<?php echo esc_attr(get_option('sentry_dsn')); ?>" /></td>
	        </tr>

	        <tr valign="top">
		        <th scope="row">Custom Tracking HTML Scripts (Head)</th>
		        <td><textarea name="custom_inject_code" rows="15" cols="80"><?php echo esc_attr(get_option('custom_inject_code')); ?></textarea></td>
	        </tr>

	    </table>

    <?php
submit_button('Save Changes');
	?>
	</form>
</div>







<?php }?>