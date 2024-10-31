<?php
/**
 * Plugin Name: Request IO
 * Plugin URI: https://www.request.io
 * Description: This plugin helps you configure your WordPress website to use Request.io.
 * Version: 1.0
 * Author: Mersenne AB
 * Author URI: https://profiles.wordpress.org/homanp/
 **/

class WP_Request_IO
{
    public function __construct()
    {   
        // Hook into the admin menu
        add_action('admin_menu', array($this, 'create_plugin_settings_page'));

        // Add Settings and Fields
        add_action('admin_init', array($this, 'setup_sections'));
        add_action('admin_init', array($this, 'setup_fields'));

        // Main cache hook
        add_action('template_redirect', array($this, 'process_post'));
		
		add_action( 'rest_api_init', function () {
		  register_rest_route( 'requestio/v1', '/update-cache', array(
			'methods' => 'GET',
			'callback' => array($this, 'update_cache'),
		  ));
		});

        add_action('shutdown', function () {
            if (is_admin()) {
                return;
            }

            $final = '';

            // We'll need to get the number of ob levels we're in, so that we can iterate over each, collecting
            // that buffer's output into the final output.
            $levels = ob_get_level();

            for ($i = 0; $i < $levels; $i++) {
                $final .= ob_get_contents();
                ob_clean();
            }

            // Apply any filters to the final output
            echo apply_filters('final_output', $final);
        }, 0);

        add_filter('final_output', array($this, 'fetch_from_cache'));
    }

    public function create_plugin_settings_page()
    {
        $page_title = 'Request.io';
        $menu_title = 'RequestIO';
        $capability = 'manage_options';
        $slug = 'request_io';
        $callback = array($this, 'plugin_settings_page_content');
        $icon = 'dashicons-admin-plugins';
        $position = 100;

        add_menu_page($page_title, $menu_title, $capability, $slug, $callback, $icon, $position);
    }

    public function plugin_settings_page_content()
    {?>
    	<div class="wrap">
    		<h2>Request.io</h2>
            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
                $this->admin_notice();
            }?>
    		<form method="POST" action="options.php">
                <?php
                    settings_fields('request_io');
                    do_settings_sections('request_io');
                    submit_button();
                ?>
    		</form>
    	</div>
    <?php
    }

    public function admin_notice()
    {?>
        <div class="notice notice-success is-dismissible">
            <p>Your settings have been updated!</p>
        </div>
    <?php
    }

    public function setup_sections()
    {
        add_settings_section('settings', 'Settings', array($this, 'section_callback'), 'request_io');
    }

    public function section_callback($arguments)
    {
        return;
    }

    public function setup_fields()
    {
        $fields = array(
            array(
                'uid' => 'apikey',
                'label' => 'API Key',
                'section' => 'settings',
                'type' => 'text',
                'style' => 'min-width: 400px',
                'placeholder' => 'Enter your API key...',
                'helper' => '<a href="mailto:info@request.io">Need help?</a>',
                'supplimental' => 'You can find your public key by logging into your <a href="https://app.request.io" target="_blank">Request.io account</a>.',
            ),
			array(
                'uid' => 'abtest',
                'label' => 'A/B testing',
                'section' => 'settings',
                'type' => 'checkbox',
				'options' => array(
        			'enabled' => 'Enabled',
        		),
            ),
        );
        foreach ($fields as $field) {

            add_settings_field(
                $field['uid'],
                $field['label'],
                array($this, 'field_callback'),
                'request_io',
                $field['section'],
                $field
            );
            register_setting('request_io', $field['uid']);
        }
    }

    public function field_callback($arguments)
    {

        $value = get_option($arguments['uid']);

        if (!$value) {
            $value = $arguments['default'];
        }

        switch ($arguments['type']) {
            case 'text':
            case 'password':
            case 'number':
                printf(
                    '<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" style="%5$s" />',
                    $arguments['uid'],
                    $arguments['type'],
                    $arguments['placeholder'],
                    $value,
                    $arguments['style']
                );
                break;
            case 'textarea':
                printf(
                    '<textarea name="%1$s" id="%1$s" placeholder="%2$s" rows="5" cols="50">%3$s</textarea>',
                    $arguments['uid'],
                    $arguments['placeholder'],
                    $value
                );
                break;
            case 'select':
            case 'multiselect':
                if (!empty($arguments['options']) && is_array($arguments['options'])) {
                    $attributes = '';
                    $options_markup = '';
                    foreach ($arguments['options'] as $key => $label) {
                        $options_markup .= sprintf(
                            '<option value="%s" %s>%s</option>',
                            $key,
                            selected($value[array_search($key, $value, true)], $key, false),
                            $label
                        );
                    }
                    if ($arguments['type'] === 'multiselect') {
                        $attributes = ' multiple="multiple" ';
                    }
                    printf(
                        '<select name="%1$s[]" id="%1$s" %2$s>%3$s</select>',
                        $arguments['uid'],
                        $attributes,
                        $options_markup
                    );
                }
                break;
            case 'radio':
            case 'checkbox':
                if (!empty($arguments['options']) && is_array($arguments['options'])) {
                    $options_markup = '';
                    $iterator = 0;
                    foreach ($arguments['options'] as $key => $label) {
                        $iterator++;
                        $options_markup .= sprintf(
                            '<label for="%1$s_%6$s"><input id="%1$s_%6$s" name="%1$s[]" type="%2$s" value="%3$s" %4$s /> %5$s</label><br/>',
                            $arguments['uid'],
                            $arguments['type'],
                            $key,
                            checked($value[array_search($key, $value, true)], $key, false),
                            $label,
                            $iterator
                        );
                    }
                    printf('<fieldset>%s</fieldset>', $options_markup);
                }
                break;
        }

        if ($helper = $arguments['helper']) {
            printf('<span class="helper"> %s</span>', $helper);
        }

        if ($supplimental = $arguments['supplimental']) {
            printf('<p class="description">%s</p>', $supplimental);
        }
    }

    public function process_post()
    {
        ob_start();
    }

    public function is_cached($header)
    {
        return in_array($header, ['STALE', 'HIT'], true);
    }

    public function is_get_request() {
        return in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['GET'], true);
    }
	
	public function update_cache($request) {
		$api_url = esc_url_raw($_GET['api_url']);
		$cache_key = base64_encode($api_url);

        if ( ! is_dir(WP_CONTENT_DIR . '/cache/requestio')) {
            wp_mkdir_p(WP_CONTENT_DIR . '/cache/requestio');
        }

		$response = wp_remote_get($api_url);
		
		if (is_array($response) && ! is_wp_error($response)) {	
			$optimized_html = wp_remote_retrieve_body($response);
            $fp = fopen(WP_CONTENT_DIR . '/cache/requestio' . "/{$cache_key}.html","wb");
            fwrite($fp, $optimized_html);
            fclose($fp);
		}	
	}
	
	public function getOptimizedHTML($url) {
		$cache_key = base64_encode($url);
		return @file_get_contents(WP_CONTENT_DIR . '/cache/requestio' . "/{$cache_key}.html");
	}
	
	public function siteURL() {
  		$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || 
    		$_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
  		$domainName = $_SERVER['HTTP_HOST'];
  		return $protocol.$domainName;
	}

    public function fetch_from_cache($output)
    {
        $api_key = get_option('apikey');
		$do_experiment = get_option('abtest');
		$api_url = "https://{$api_key}.pagespeed.request.io{$_SERVER['REQUEST_URI']}";
        $bypass_optimization = (isset( $_GET['disable_request_io'])) ? true : false;
        $site_url = $this->siteURL();
        $request_qs = $_GET['request_io'];
		
        if (
            isset($api_key) && 
            !isset($_SERVER['HTTP_X_REQUEST_IO']) && 
            $this->is_get_request() && 
            !$bypass_optimization && 
            $request_qs != "off"  &&
            strpos($_SERVER['REQUEST_URI'], 'wp-') === false &&
            strpos($_SERVER['REQUEST_URI'], '.txt') === false &&
            strpos($_SERVER['REQUEST_URI'], '.xml') === false 
        ) {
            $args = array(
                'blocking' => false,
                'timeout' => 0.01
            );

            wp_remote_get("{$site_url}/wp-json/requestio/v1/update-cache?api_url={$api_url}", $args);

            $optimizedHTML = $this->getOptimizedHTML($api_url);
            $experiment_group = $_COOKIE['wordpress_requestio'];
			
			if (isset($do_experiment[0]) && $request_qs != "on") {
				if (!$experiment_group) {
                	$experiment_group = mt_rand(0, 1) === 1 ? "control" : "test";
					setcookie('wordpress_requestio', $experiment_group, 0, "/", COOKIE_DOMAIN, 1);
            	}
				
				if($experiment_group === 'control') {
					wp_safe_redirect(
						add_query_arg(array('request_io' => 'off' )) 
					);
				}
			}
			
			if ($optimizedHTML) {
				return $optimizedHTML;
			}    
        }

        return $output;
    }

}

new WP_Request_IO();
