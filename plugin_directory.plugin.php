<?php

	include('beaconhandler.php');

	class PluginDirectory extends Plugin {
		
		private $addon_fields = array(
			'guid',
			'instructions',
			'type',
			'url',
			'screenshot',
			'author',
			'author_url',
			'license',
		);
		
		// fields defined on the license publish form
		private $license_fields = array(
			'shortname',
			'simpletext',
			'url',
		);
		
		// fields each version should have
		private $version_fields = array(
			'version',
			'description',
			'url',
			'habari_version',
			'severity',
			'requires',
			'provides',
			'recommends',
		);
		
		public function action_plugin_activation ( $file ) {
			
			Post::add_new_type( 'addon' );
			Post::add_new_type( 'license' );
			
			$this->create_default_content();
			
			// make sure it's registered before we try to modify the schema, so the table name gets replaced
			//DB::register_table( 'dir_addon_versions' );
			
			// create the database table, or upgrade it
			//DB::dbdelta( $this->get_db_schema() );
			
		}
		
		private function create_default_content ( ) {
			
			$habari_addon = Posts::get( array( 'content_type' => Post::type( 'addon' ), 'slug' => 'habari' ) );
			
			if ( count( $habari_addon ) == 0 ) {
				$habari = Post::create( array(
					'content_type' => Post::type( 'addon' ),
					'title' => 'Habari',
					'content' => '',
					'status' => Post::status('published'),
					'tags' => array( 'habari' ),
					'pubdate' => HabariDateTime::date_create(),
					'user_id' => User::identify()->id,
					'slug' => 'habari',
				) );
				
				$habari->info->guid = '7a0313be-d8e3-11db-8314-0800200c9a66';
				$habari->info->url = 'http://habariproject.org';
				$habari->info->author = 'The Habari Community';
				$habari->info->author_url = 'http://habariproject.org';
				$habari->info->license = 'asl2';
				$habari->info->type = 'core';
				
				$habari->info->commit();
			}
			
			$apache_license = Posts::get( array( 'content_type' => Post::type( 'license' ), 'slug' => 'asl2' ) );
			
			if ( count( $apache_license ) == 0 ) {
				$asl2 = Post::create( array(
					'content_type' => Post::type( 'license' ),
					'title' => 'Apache Software License, version 2.0',
					'content' => '',
					'status' => Post::status('published'),
					'pubdate' => HabariDateTime::date_create(),
					'user_id' => User::identify()->id,
					'slug' => 'asl2',
				) );
				
				$asl2->info->simpletext = file_get_contents( dirname( __FILE__ ) . '/license.asl2.txt' );
				$asl2->info->shortname = 'asl2';
				$asl2->info->url = 'http://www.apache.org/licenses/LICENSE-2.0';
				
				$asl2->info->commit();
			}
			
		}
		
		public function action_plugin_deactivation ( $file ) {
			
			// when deactivating, don't destroy data, just turn it 'off'
			Post::deactivate_post_type( 'addon' );
			Post::deactivate_post_type( 'license' );
			
		}
		
		public function filter_post_type_display ( $type, $plurality ) {
			
			if ( $type == 'addon' ) {
			
				if ( $plurality == 'singular' ) {
					$type = _t('Addon', 'plugin_directory');
				}
				else {
					$type = _t('Addons', 'plugin_directory');
				}
				
			}
			
			if ( $type == 'license' ) {
				
				if ( $plurality == 'singular' ) {
					$type = _t('License', 'plugin_directory');
				}
				else {
					$type = _t('Licenses', 'plugin_directory');
				}
				
			}
			
			return $type;
			
		}
		
		public function filter_plugin_config ( $actions, $plugin_id ) {
			
			// we don't use the magic configure() method because then it gets placed below custom actions in the dropbutton
			$actions['configure'] = _t('Configure', 'plugin_directory');
			$actions['uninstall'] = _t('Uninstall', 'plugin_directory');
			
			return $actions;
			
		}
		
		public function action_plugin_ui_uninstall ( ) {

			// get all the posts of the types we're deleting
			$addons = Posts::get( array( 'content_type' => array( Post::type( 'addon' ), Post::type( 'license' ) ), 'nolimit' => true ) );
			
			foreach ( $addons as $addon ) {
				$addon->delete();
			}
			
			// now that the posts are gone, delete the type - this would fail if we hadn't deleted the content first
			Post::delete_post_type( 'addon' );
			Post::delete_post_type( 'license' );
			
			// delete our custom db table
			DB::query( 'drop table {dir_addon_versions}' );
			
			// now deactivate the plugin
			Plugins::deactivate_plugin( __FILE__ );
			
			Session::notice( _t("Uninstalled plugin '%s'", array( $this->info->name ), 'plugin_directory' ) );
			
			// redirect to the plugins page again so the page updates properly - this is what AdminHandler does after plugin deactivation
			Utils::redirect( URL::get( 'admin', 'page=plugins' ) );
			
		}
		
		public function action_plugin_ui_configure ( ) {
			
			$ui = new FormUI('plugin_directory');
			
			//$ui->append( 'text', 'licenses', 'option:', _t( 'Licenses to use:', 'Lipsum' ) );
			
			$ui->append( 'submit', 'save', _t( 'Save' ) );
			
			$ui->on_success( array( $this, 'updated_config' ) );
			
			$ui->out();
			
		}
		
		public function filter_default_rewrite_rules ( $rules ) {
			
			// create the beacon endpoint rule
			$rule = array(
				'name' => 'beacon_server',
				'parse_regex' => '%^beacon$%i',
				'build_str' => 'beacon',
				'handler' => 'BeaconHandler',
				'action' => 'request',
				'description' => 'Incoming Beacon Update Requests',
			);
			
			// add it to the stack
			$rules[] = $rule;
			
			
			
			
			// always return the rules
			return $rules;
			
		}
		
		/**
		 * Manipulate the controls on the publish page
		 * 
		 * @param FormUI $form The form that is used on the publish page
		 * @param Post $post The post that's being edited
		 */
		public function action_form_publish ( $form, $post ) {
			
			// split out to smaller functions based on the content type
			if ( $form->content_type->value == Post::type( 'addon' ) ) {
				$this->form_publish_addon( $form, $post );
			}
			else if ( $form->content_type->value == Post::type( 'license' ) ) {
				$this->form_publish_license( $form, $post );
			}
			
		}
		
		/**
		 * Manipulate the controls on the publish page for Addons
		 * 
		 * @todo fix tab indexes
		 * @todo remove settings tab without breaking everything in it?
		 * 
		 * @param FormUI $form The form that is used on the publish page
		 * @param Post $post The post that's being edited
		 */
		private function form_publish_addon ( $form, $post ) {
			
			// remove silos, we don't need them
			$form->remove( $form->silos );
			
			// remove the settings pane from the publish controls for non-admin users, we don't want anyone editing that
			if ( User::identify()->can( 'superuser' ) == false ) {
				$form->publish_controls->remove( $form->publish_controls->settings );
			}
			
			// add guid after title
			$guid = $form->append( 'text', 'addon_details_guid', 'null:null', _t('GUID', 'plugin_directory') );
			$guid->value = $post->info->guid;	// populate it, if it exists
			$guid->template = ( $post->slug ) ? 'admincontrol_text' : 'guidcontrol';
			$form->move_after( $form->addon_details_guid, $form->title );	// position it after the title
			
			// add the instructions after the content
			$instructions = $form->append( 'textarea', 'addon_details_instructions', 'null:null', _t('Instructions', 'plugin_directory') );
			$instructions->value = $post->info->instructions;	// populate it, if it exists
			$instructions->class[] = 'resizable';
			$instructions->template = 'admincontrol_textarea';
			$form->move_after( $form->addon_details_instructions, $form->content );	// position it after the content box

			
			// create the addon details wrapper pane
			$addon_fields = $form->publish_controls->append( 'fieldset', 'addon_details', _t('Details', 'plugin_directory') );
			
			// add the type: plugin or theme
			$details_type = $addon_fields->append( 'select', 'addon_details_type', 'null:null', _t('Addon Type', 'plugin_directory') );
			$details_type->value = $post->info->type;
			$details_type->template = 'tabcontrol_select';
			$details_type->options = array(
				'' => '',
				'plugin' => _t('Plugin', 'plugin_directory'),
				'theme' => _t('Theme', 'plugin_directory'),
			);
			// admins can use the 'core' type for habari itself
			if ( User::identify()->can('superuser') ) {
				$details_type->options['core'] = _t('Core', 'plugin_directory');
			}
			$details_type->add_validator( 'validate_required' );
			
			// add the url
			$details_url = $addon_fields->append( 'text', 'addon_details_url', 'null:null', _t('URL', 'plugin_directory') );
			$details_url->value = $post->info->url;
			$details_url->template = 'tabcontrol_text';
			
			// add the screenshot
			$details_screenshot = $addon_fields->append( 'text', 'addon_details_screenshot', 'null:null', _t('Screenshot', 'plugin_directory') );
			$details_screenshot->value = $post->info->screenshot;
			$details_screenshot->template = 'tabcontrol_text';
			
			// add the author name
			$details_author = $addon_fields->append( 'text', 'addon_details_author', 'null:null', _t('Author', 'plugin_directory') );
			$details_author->value = $post->info->author;
			$details_author->template = 'tabcontrol_text';
			
			// add the author url
			$details_author_url = $addon_fields->append( 'text', 'addon_details_author_url', 'null:null', _t('Author URL', 'plugin_directory') );
			$details_author_url->value = $post->info->author_url;
			$details_author_url->template = 'tabcontrol_text';
			
			// add the license @todo should be populated with a list of license content types
			$details_license = $addon_fields->append( 'select', 'addon_details_license', 'null:null', _t('License', 'plugin_directory') );
			$details_license->value = $post->info->license;
			$details_license->template = 'tabcontrol_select';
			$details_license->options = $this->get_license_options();
			
			
			// create the addon versions wrapper pane
			$addon_versions = $form->publish_controls->append( 'fieldset', 'addon_versions', _t('Versions', 'plugin_directory') );
			
			if ( $post->info->versions ) {
				
				$form->addon_versions->append( 'static', 'current_versions', _t('Current Versions', 'plugin_directory') );
				
				foreach ( $post->info->versions as $version ) {
					
					$version_info = $version['severity'] . ': ' . $post->title . ' ' . $version['version'] . ' -- ' . $version['description'];
					$addon_versions->append( 'static', 'version_info', $version_info );
					
				}
				
			}
			
			// add the new version fields
			$form->addon_versions->append( 'static', 'new_version', _t('Add New Version', 'plugin_directory') );
			
			// the version number
			$version = $addon_versions->append( 'text', 'addon_version_version', 'null:null', _t('Version Number', 'plugin_directory') );
			$version->template = 'tabcontrol_text';
			
			// the version description
			$version_description = $addon_versions->append( 'text', 'addon_version_description', 'null:null', _t('Version Description', 'plugin_directory') );
			$version_description->template = 'tabcontrol_text';
			
			// the version url
			$version_url = $addon_versions->append( 'text', 'addon_version_url', 'null:null', _t('Version URL', 'plugin_directory') );
			$version_url->template = 'tabcontrol_text';
			
			// the habari version it's compatible with
			$habari_version = $addon_versions->append( 'text', 'addon_version_habari_version', 'null:null', _t('Compatible Habari Version', 'plugin_directory') );
			$habari_version->template = 'tabcontrol_text';
			$habari_version->helptext = _t('"x" is a wildcard, eg. 0.6.x', 'plugin_directory');
			
			// the release severity
			$severity = $addon_versions->append( 'select', 'addon_version_severity', 'null:null', _t('Severity', 'plugin_directory') );
			$severity->template = 'tabcontrol_select';
			$severity->options = array(
				'release' => _t('Initial Release', 'plugin_directory'),
				'critical' => _t('Critical', 'plugin_directory'),
				'bugfix' => _t('Bugfix', 'plugin_directory'),
				'feature' => _t('Feature', 'plugin_directory'),
			);
			
			// features required
			$requires = $addon_versions->append( 'text', 'addon_version_requires', 'null:null', _t('Requires Feature', 'plugin_directory') );
			$requires->template = 'tabcontrol_text';
			$requires->helptext = _t('Comma separated, like tags.', 'plugin_directory');
			
			// features provided
			$provides = $addon_versions->append( 'text', 'addon_version_provides', 'null:null', _t('Provides Feature', 'plugin_directory') );
			$provides->template = 'tabcontrol_text';
			
			// features recommended
			$recommends = $addon_versions->append( 'text', 'addon_version_recommends', 'null:null', _t('Recommends Feature', 'plugin_directory') );
			$recommends->template = 'tabcontrol_text';
			
		}
		
		private function get_license_options ( ) {
			
			$licenses = Posts::get( array( 'content_type' => Post::type( 'license' ), 'nolimit' => true ) );
			
			$l = array( '' => '' );		// start with a blank option
			foreach ( $licenses as $license ) {
				
				$l[ $license->slug ] = $license->title;
				
			}
			
			return $l;
			
		}
		
		/**
		 * Manipulate the controls on the publish page for Licenses
		 * 
		 * @param FormUI $form The form that is used on the publish page
		 * @param Post $post The post that's being edited
		 */
		private function form_publish_license ( $form, $post ) {
			
			// remove silos, we don't need them
			$form->remove( $form->silos );
			
			// remove the content, we use our own content field
			$form->remove( $form->content );
			
			// remove the tags, we don't use those
			$form->remove( $form->tags );
			
			// remove the settings pane from the publish controls for non-admin users, we don't want anyone editing that
			if ( User::identify()->can( 'superuser' ) == false ) {
				$form->publish_controls->remove( $form->publish_controls->settings );
			}
			
			// add shortname after title
			$shortname = $form->append( 'text', 'license_shortname', 'null:null', _t('Short Name', 'plugin_directory') );
			$shortname->value = $post->info->shortname;		// populate it, if it exists
			$shortname->template = 'admincontrol_text';
			$form->move_after( $form->license_shortname, $form->title );	// move it after the title field
			
			// add the simple text
			$simpletext = $form->append( 'textarea', 'license_simpletext', 'null:null', _t('Simple Text', 'plugin_directory') );
			$simpletext->value = $post->info->simpletext;	// populate it, if it exists
			$simpletext->template = 'admincontrol_textarea';
			$form->move_after( $form->license_simpletext, $form->license_shortname );
			
			// add url
			$url = $form->append( 'text', 'license_url', 'null:null', _t('License URL', 'plugin_directory') );
			$url->value = $post->info->url;		// populate it, if it exists
			$url->template = 'admincontrol_text';
			$form->move_after( $form->license_url, $form->license_simpletext );
			
		}
		
		public function action_publish_post ( $post, $form ) {
			
			if ( $post->content_type == Post::type( 'addon' ) ) {
				
				foreach ( $this->addon_fields as $field ) {
					
					if ( $form->{'addon_details_' . $field}->value ) {
						$post->info->$field = $form->{'addon_details_' . $field}->value;
					}
					
				}
				
				// save version information
				$this->save_versions( $post, $form );
				
			}
			else if ( $post->content_type == Post::type( 'license' ) ) {
				
				foreach ( $this->license_fields as $field ) {
					
					if ( $form->{'license_' . $field}->value ) {
						$post->info->$field = $form->{'license_' . $field}->value;
					}
					
				}
				
				// if the shortname is set, use it as the slug
				if ( isset( $post->info->shortname ) ) {
					$post->slug = Utils::slugify( $post->info->shortname );
				}
				
			}
			
		}
		
		private function save_versions ( $post, $form ) {
			
			// first see if a version is trying to be added
			if ( $form->addon_version_version != '' ) {
				
				// create an array to store all the version info
				$version = array();
				
				// loop through all the fields and add them to our array
				foreach ( $this->version_fields as $field ) {
					
					$version[ $field ] = $form->{'addon_version_' . $field}->value;
					
				}
				
				// if there are no current versions, initialize it
				if ( !isset( $post->info->versions ) ) {
					$post->info->versions = array();
				}
				
				// and add it to the list -- array_merge because [] = doesn't work with postinfo fields
				$post->info->versions = array_merge( $post->info->versions, array( $version['version'] => $version ) );
				
			}
			
		}
		
		public function action_init ( ) {
			
			// register our custom guid FormUI control for the post publish page
			$this->add_template( 'guidcontrol', dirname(__FILE__) . '/templates/guidcontrol.php' );
			
			// register the custom db table
			//DB::register_table( 'dir_addon_versions' );
			
		}
		
		/**
		 * Provide a quick AJAX method to return a GUID for the post page.
		 * 
		 * @param ActionHandler $handler The handler being executed.
		 */
		public function action_auth_ajax_generate_guid ( $handler ) {
			
			echo UUID::get();
			
		}
		
		public function action_ajax_plugindirectory_info ( $handler ) {
			
			
			
		}
		
	}

?>