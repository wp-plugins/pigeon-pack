<?php
/**
 * Helper functions used by Pigeon Pack
 * 
 * @package Pigeon Pack
 * @since 0.0.1
 */
 
if ( !function_exists( 'get_pigeonpack_settings' ) ){
	
	/**
	 * Helper Function for getting the main Pigeon Pack settings
	 *
	 * @since 0.0.1
	 *
	 * @return array Pigeon Pack options
	 */
	function get_pigeonpack_settings() {
		
		global $dl_plugin_pigeonpack;
		
		return $dl_plugin_pigeonpack->get_pigeonpack_settings();
		
	}
	
}

if ( !function_exists( 'pigeonpack_campaign_scheduler' ) ) { 
	
	/**
	 * Schedule the first run of a campaign
	 *
	 * @since 0.0.1
	 *
	 * @param int|string ID of campaign being run
	 * @param array Option array of post IDs to merge with this campaign (really only used for WordPress Post campaigns
	 */
    function pigeonpack_campaign_scheduler( $campaign_id, $posts = array() ) {
		
		//we want to schedule an event to happen right now, to send out the first batch of emails
		//we do not want to call the pigeonpack_mail function directly, it can cause a delay when publish a post.
		wp_schedule_single_event( current_time( 'timestamp', 1 ), 'scheduled_pigeonpack_mail', array( $campaign_id, $posts, 0, null ) );
	
    }   
	
}

if ( !function_exists( 'pigeonpack_double_optin_scheduler' ) ) { 
	
	/**
	 * Schedule the first run of a campaign
	 *
	 * @since 0.0.1
	 *
	 * @param int|string ID of campaign being run
	 * @param array Option array of post IDs to merge with this campaign (really only used for WordPress Post campaigns
	 */
    function pigeonpack_double_optin_scheduler( $list_id, $subscriber_id ) {
		
		//we want to schedule an event to happen right now, to send out the first batch of emails
		//we do not want to call the pigeonpack_mail function directly, it can cause a delay when publish a post.
		wp_schedule_single_event( current_time( 'timestamp', 1 ), 'scheduled_pigeonpack_double_optin_mail', array( $list_id, $subscriber_id ) );
	
    }   
	
}

if ( !function_exists( 'pigeonpack_double_optin_mail' ) ) {

	/**
	 * Process the double opt-in mail
	 *
	 * @since 0.0.1
	 * @uses apply_filters() Calls 'double_optin_pigeonpack_headers' hook on an array of headers before subscriber loop is processing.
	 *
	 * @param int $list_id Used to get details about which list is being subscribed to
	 * @param int $subscriber_id Used to get details about which subscriber is doing the subscribing
	 */
	function pigeonpack_double_optin_mail( $list_id, $subscriber_id ) {
		
		$list = get_post( $list_id );
		$subscriber = get_pigeonpack_subscriber( $subscriber_id );
	
		$pigeonpack_settings = get_pigeonpack_settings();
		$double_optin_settings = get_post_meta( $list->ID, '_pigeonpack_double_optin_settings', true );
		
		$headers[] = 'From: ' . $double_optin_settings['from_name'] . ' <' . $double_optin_settings['from_email'] . '>';
			
		$subject = $double_optin_settings['subject'];
		$message = $double_optin_settings['message'];

		list( $subject, $message, $footer ) = pigeonpack_unmerge_misc( $double_optin_settings['subject'], $double_optin_settings['message'], '', array( 'type' => 'list', 'id' => $list->ID ) );
		
		list( $email, $subject, $message, $footer ) = pigeonpack_unmerge_subscriber( $subscriber, $subject, $message, '' );
		
		$content_type = 'content-type: ' . pigeonpack_subscriber_content_type( $subscriber );
		
		if ( 'content-type: text/plain' === $content_type ) {
			
			require_once( PIGEON_PACK_PLUGIN_PATH . '/includes/html2txt.php' );
			
			$message = convert_html_to_text( $message );
			
		}
		
		$subscriber_headers = apply_filters( 'double_optin_pigeonpack_headers', array_merge( $headers, array( $content_type ) ), $subscriber );
	
		// If we're using an SMTP server, set it up now...
		if ( isset( $pigeonpack_settings['smtp_enable'] ) && 'smtp' === $pigeonpack_settings['smtp_enable'] )
			add_action( 'phpmailer_init', 'pigeonpack_phpmailer_init' );
			
		wp_mail( $email, strip_tags( $subject ), $message, $subscriber_headers );
		
	}
	add_action( 'scheduled_pigeonpack_double_optin_mail', 'pigeonpack_double_optin_mail', 10, 2 ); //wp_schedule_single_event action
	
}

if ( !function_exists( 'pigeonpack_wp_post_campaign_init' ) ) {

	/**
	 * Initializes options and meta values for WordPress post campaigns
	 *
	 * Adds campaigns to options table
	 * If campaign is a digest, adds the digest schedule to check for new posts
	 * new posts for digests are stored in post meta with the transition action
	 *
	 * @since 0.0.1
	 *
	 * @param int $campaign_id ID of campaign being initialized
	 */
	function pigeonpack_wp_post_campaign_init( $campaign_id ) {
		
		$post_campaigns = (array)get_option( 'pigeonpack_wp_post_campaigns' );
		
		$type = get_post_meta( $campaign_id, '_pigeonpack_wp_post_type', true );
		
		if ( !empty( $post_campaigns ) ) {
			// Change type if we're updating an existing campaign
			foreach( $post_campaigns as $post_campaign ) {
			
				if ( $campaign_id === $post_campaign['id'] )
					$post_campaign['type'] = $type;
				
			}
		}
	
		$new_campaign = array(
			'id'	=> $campaign_id,
			'type'	=> $type,
		);
		
		//only add NEW campaigns				
		if ( !in_array( $new_campaign, $post_campaigns ) )
			$post_campaigns[] = $new_campaign;
		
		//update with modified array
		update_option( 'pigeonpack_wp_post_campaigns', $post_campaigns );
		
		if ( 'digest' === $type ) {
		
			$digest = get_post_meta( $campaign_id, '_pigeonpack_wp_post_digest', true );
					
			switch( $digest['freq'] ) {
			
				case 'monthly':
					$today = date_i18n( 'j' );
					$month = date_i18n( 'n' );
					
					if ( 'last_day'	=== $digest['date'] )
						$date = date_i18n( 't' );
					else
						$date = $digest['date'];
						
					if ( $today > $date )
						$month = date_i18n( 'n', strtotime( '+1 month' ) );
					else if ( $today === $date && current_time( 'timestamp' ) > strtotime( $digest['time'] ) )
						$month = date_i18n( 'n', strtotime( '+1 month' ) );
						
					$schedule = strtotime( $date . " " . $month );
					$schedule = strtotime( $digest['time'], $schedule );
					break;
					
				case 'weekly':
					// If we've already passed the schedule for today, get the next one
					// doesn't really matter if we're on the correct day or not
					// but if we are, we want the next one, not this one
					if ( current_time( 'timestamp' ) > strtotime( $digest['time'] ) )
						$next = 'next ';
					else
						$next = '';
						
					$schedule = strtotime( $next . pigeonpack_day_string( $digest['day'] ) );
					$schedule = strtotime( $digest['time'], $schedule );
					break;
					
				case 'daily':
				default:
					$today = date_i18n( 'w' );
					$days = $digest['days'];
				
					do {
						
						$day = array_shift( $days );
						
					} while( $today > $day && !empty( $days ) );
					
					if ( empty( $days ) )
						$day = $digest['days'][0]; //first element on array
					
					// If we've already passed the schedule for today, get the next one
					if ( $today === $day && current_time( 'timestamp' ) > strtotime( $digest['time'] ) ) {
						
						if ( !empty( $days ) ) //for the case where the users selects daily, but only chooses one day
							$day = array_shift( $days );
							
						$next = 'next ';
						
					} else {
					
						$next = '';	
						
					}
					
					$schedule = strtotime( $next . pigeonpack_day_string( $day ) );
					$schedule = strtotime( $digest['time'], $schedule );
					break;
				
			}
			
			$schedule = strtotime( get_gmt_from_date( date_i18n( 'Y-m-d H:i:s', $schedule ) ) );
	
			//get the post meta with the event schedule details
			$previous_schedule = get_post_meta( $campaign_id, '_pigeonpack_scheduled_event', true );
			
			// if a previous schedule already exists for this campaign, we want to unschedule it and schedule the new event
			if ( !empty( $previous_schedule) && $next_schedule = wp_next_scheduled( 'scheduled_wp_post_digest_campaign', $previous_schedule[1] ) )
				if ( $previous_schedule[0] === $next_schedule ) //doubel check that the schedules are the same before removing it
					wp_unschedule_event( $previous_schedule[0], 'scheduled_wp_post_digest_campaign', $previous_schedule[1] );
			
			//wp_schedule_single_event( $schedule, 'scheduled_wp_post_digest_campaign', array( $campaign->ID, $schedule ) );
			wp_schedule_single_event( $schedule, 'scheduled_wp_post_digest_campaign', array( $campaign_id, $schedule ) );
			
			//update the post meta with the event schedule details for next update/publish
			update_post_meta( $campaign_id, '_pigeonpack_scheduled_event', array( $schedule, array( $campaign_id, $schedule ) ) );
			
		}
		
	}
	
}

if ( !function_exists( 'remove_pigeonpack_wp_post_campaign' ) ) {
 
    /**
     * Removes campaign from pigeonpack_wp_post_campaigns option in WordPress options table
     *
     * @since 0.0.1
     *
     * @param int $campaign_id ID of campaign being initialized
     */
    function remove_pigeonpack_wp_post_campaign( $campaign_id ) {
    
        if ( $wp_post_type = get_post_meta( $campaign_id, '_pigeonpack_wp_post_type', true ) ) {
                                        
            $post_campaigns = get_option( 'pigeonpack_wp_post_campaigns' );
            
            if ( !empty( $post_campaigns ) ) {
            
                // If the current campaign is listed as a wp_post campaign, unset it and update the option
                if ( false !== $i = array_search( array( 'id' => $campaign_id, 'type' => $wp_post_type ), $post_campaigns ) ) {
                        
                    unset( $post_campaigns[$i] );                   
                    update_option( 'pigeonpack_wp_post_campaigns', $post_campaigns );
                
                }
                
            }
        
        }
            
    }
        
}
 
if ( !function_exists( 'remove_pigeonpack_wp_post_digest_schedule' ) ) {
 
    /**
     * Removes digest campaign schedule
     *
     * @since 0.0.1
     *
     * @param int $campaign_id ID of campaign being initialized
     */
    function remove_pigeonpack_wp_post_digest_schedule( $campaign_id ) {
                            
        $previous_schedule = get_post_meta( $campaign_id, '_pigeonpack_scheduled_event', true );
        wp_unschedule_event( $previous_schedule[0], 'scheduled_wp_post_digest_campaign', $previous_schedule[1] );
        delete_post_meta( $campaign_id, '_pigeonpack_scheduled_event' );
            
    }
        
}

if ( !function_exists( 'do_pigeonpack_wp_post_campaigns' ) ) {
	
	/**
	 * Runs through Pigeon Pack post campaigns and processes the new post as necessary
	 *
	 * If there is an individual post campaign (each post is a new campaign mailing) this function
	 * will call the scheduler on the campaign ID w/ the post ID to be processed.
	 *
	 * If there is a digest post campaign this function will add the post ID to the digest campaign's
	 * post meta for future processing.
	 *
	 * @since 0.0.1
	 *
	 * @param int $post_id ID of post being processed
	 */
	function do_pigeonpack_wp_post_campaigns( $post_id ) {
	
		$post_campaigns = get_option( 'pigeonpack_wp_post_campaigns' );
		
		if ( !empty( $post_campaigns ) ) {
		
			foreach( $post_campaigns as $campaign ) {
				
				if ( pigeonpack_exclude_post( $campaign['id'], $post_id ) )
					continue;
					
				if ( 'individual' === $campaign['type'] ) {
									
					//process the next campaign for this post
					pigeonpack_campaign_scheduler( $campaign['id'], array( $post_id ) );
				
				} else if ( 'digest' === $campaign['type'] ) { 
				
					$digest_posts = get_post_meta( $campaign['id'], '_pigeonpack_digest_posts', true );
					
					if ( empty( $digest_posts ) ) 
						$digest_posts = array();

					// We don't want duplicates
					if ( !in_array( $post_id, $digest_posts ) )
						$digest_posts[] = $post_id;
						
					update_post_meta( $campaign['id'], '_pigeonpack_digest_posts', $digest_posts );
					
				}
				
			}
			
		}
		
	}
	
}

if ( !function_exists( 'do_pigeonpack_remove_wp_post_from_digest_campaigns' ) ) {
	
	/**
	 * Runs through Pigeon Pack post campaigns and removes the post from any digest campaigns as necessary
	 *
	 * @since 0.0.1
	 *
	 * @param int $post_id ID of post being processed
	 */
	function do_pigeonpack_remove_wp_post_from_digest_campaigns( $post_id ) {
	
		$post_campaigns = get_option( 'pigeonpack_wp_post_campaigns' );
		
		if ( !empty( $post_campaigns ) ) {
		
			foreach( $post_campaigns as $campaign ) {
				
				if ( 'digest' === $campaign['type'] ) { 
				
					$digest_posts = get_post_meta( $campaign['id'], '_pigeonpack_digest_posts', true );
	
					if ( !empty( $digest_posts ) && is_array( $digest_posts ) ) {
					
						if ( false !== $key = array_search( $post_id, $digest_posts ) )
							unset( $digest_posts[$key] );
												
						update_post_meta( $campaign['id'], '_pigeonpack_digest_posts', $digest_posts );
						
					}
					
				}
				
			}
			
		}
		
	}
	
}

if ( !function_exists( 'extract_pigeonpack_list_id' ) ) {
	
	/**
	 * Extracts the list ID associated with a given campaign (WordPress role or Pigeon Pack list)
	 *
	 * If WordPress role, returns false
	 * If Pigeon Pack list, return the list ID
	 *
	 * @since 0.0.1
	 * @uses apply_filter() To call 'extract_pigeonpack_list_id' for future addons
	 *
	 * @param string $list_type Either R followed by Role name (e.g. RAdministrator) or L followed by List ID (e.g. L132)
	 * @return int|bool Integer ID of list or false
	 */
	function extract_pigeonpack_list_id( $list_type ) {
	
		if ( 'R' === substr( $list_type, 0, 1 ) )
			return array( 'type' => 'role', 'id' => substr( $list_type, 1 ) );
		else if ( 'L' === substr( $list_type, 0, 1 ) )
			return array( 'type' => 'list', 'id' => substr( $list_type, 1 ) );
		
		return apply_filter( 'extract_pigeonpack_list_id', false, $list_type );
		
	}
	
}

if ( !function_exists( 'get_pigeonpack_subscriber_by_type' ) ) {
	
	/**
	 * Gets the subscribers associated with a given list (WordPress role or Pigeon Pack list)
	 *
	 * If WordPress role, get and return the WP_User_Query object
	 * If Pigeon Pack list, get and return the list array
	 * If neither, return filtered array with custom get_pigeonpack_subscriber_by_type
	 *
	 * @since 0.0.1
	 * @uses apply_filters() Calls 'pigeonpack_subscription_user_query_args' hook on default args array for WordPress WP_User_Query
	 * @uses apply_filters() Calls 'get_pigeonpack_subscriber_by_type' hook on empty array for custom subscriber types
	 *
	 * @param string $list_type Either R followed by Role name (e.g. RAdministrator) or L followed by List ID (e.g. L132)
	 * @param array $args accepts arguments limit and offest, default 100 and 0 respectively
	 * @return object|array WP_User_Query results object or Pigeon Pack list array
	 */
	function get_pigeonpack_subscriber_by_type( $list_type, $args = array() ) {
		
		$defaults = array( 
			'limit'		=> 100,
			'offset'	=> 0,
		);
					
		extract( wp_parse_args( $args, $defaults ) );
	
		if ( 'R' === substr( $list_type, 0, 1 ) ) {
			
			//if a user unsubsribes, we do not want to get their info
			//this is tracked with the _pigeonpack_subscription meta
			$args = array(
				'role'		=> substr( $list_type, 1 ),
				'number'	=> $limit,
				'offset'	=> $offset,
			);
					
			add_action( 'pre_user_query', 'pigeonpack_pre_user_query', 1 );
			
			$args = apply_filters( 'pigeonpack_subscription_user_query_args', $args );
			$users = new WP_User_Query( $args );
			
			return $users->results;
				
		} else if ( 'L' === substr( $list_type, 0, 1 ) ) {
			
			return get_pigeonpack_subscribers( substr( $list_type, 1 ), $limit, $offset, 'subscribed' );
			
		}
		
		return apply_filters( 'get_pigeonpack_subscriber_by_type', array() );
		
	}
	
}

if ( !function_exists( 'pigeonpack_pre_user_query' ) ) {
	
	/**
	 * Called by 'pre_user_query' action for modifying the subscriber user query.
	 *
	 * There is a bug in WordPress 3.5+ that prevents us from using a complicated meta_query argument
	 * in the WP_User_Query. So we have to modify the query here to check if the _pigeonpack_subscription option
	 * is not set to "off" or if the key does not exist. If either of those cases is true, that is a valid 
	 * subscriber. 
	 * @link http://core.trac.wordpress.org/ticket/23849
	 *
	 * @since 0.0.1
	 *
	 * @param object $query Current user query
	 */
	function pigeonpack_pre_user_query( &$query ) {
		
		global $wpdb;
	
		$query->query_fields = 'DISTINCT ' . $query->query_fields;
		
		$query->query_from .= ' INNER JOIN ' . $wpdb->usermeta . ' AS pp1 ON ( ' . $wpdb->users . '.ID = pp1.user_id )';
		$query->query_from .= ' LEFT JOIN ' . $wpdb->usermeta . ' AS pp2 ON ( ' . $wpdb->users . '.ID = pp2.user_id AND pp2.meta_key = "_pigeonpack_subscription" )';
		
		$query->query_where .= " AND ( ( pp1.meta_key = '_pigeonpack_subscription' AND CAST(pp1.meta_value AS CHAR) NOT LIKE '%off%')";
		$query->query_where .= ' OR pp2.user_id IS NULL )';
		
		/*
		SELECT DISTINCT SQL_CALC_FOUND_ROWS wp_users.* 
		FROM wp_users 
		INNER JOIN wp_usermeta ON (wp_users.ID = wp_usermeta.user_id) 
		INNER JOIN wp_usermeta AS pp1 ON ( wp_users.ID = pp1.user_id ) 
		LEFT JOIN wp_usermeta AS pp2 ON ( wp_users.ID = pp2.user_id AND pp2.meta_key = "_pigeonpack_subscription" ) 
		WHERE 1=1 
		AND ( (wp_usermeta.meta_key = 'wp_capabilities' AND CAST(wp_usermeta.meta_value AS CHAR) LIKE '%\"Administrator\"%') ) 
		AND ( ( pp1.meta_key = '_pigeonpack_subscription' AND CAST(pp1.meta_value AS CHAR) NOT LIKE '%off%') OR pp2.user_id IS NULL ) 
		ORDER BY user_login ASC LIMIT 100
		*/
		
	}

}

if ( !function_exists( 'pigeonpack_unmerge_subscriber' ) ) { 

	/**
	 * Replaces {{MERGE}} variables with subscriber data
	 *
	 * @since 0.0.1
	 * @uses apply_filters() Calls 'pigeonpack_unmerge_subscriber' hook on an array of email, merged subject, merged message, and merged footer
	 *      with subscriber, email, original subject, message, and footer as next arguments.
	 *
	 * @param object|array $subscriber WP_User object or Pigeon Pack subscriber array
	 * @param string $subject Email subject to be merged
	 * @param string $message Email body to be merged
	 * @param string $footer Email footer to be merged
	 * @return array Email, merged subject, merged body, and merged footer
	 */
    function pigeonpack_unmerge_subscriber( $subscriber, $subject, $message, $footer ) { 
	
		$email = '';
		
		$merged_subject = $subject;
		$merged_message = $message;
		$merged_footer = $footer;
		
		if ( is_object( $subscriber ) ) { //Looks like this wasn't introduced until WP3.5.x -> if ( is_a( $subscriber, 'WP_User' ) ) {
			
			$subscriber_meta = get_userdata( $subscriber->ID );
			
			list( $merged_subject, $merged_message ) = str_ireplace( array( '{{EMAIL}}', '{{MERGE0}}' ), $subscriber_meta->user_email, array( $merged_subject, $merged_message ) );
			list( $merged_subject, $merged_message ) = str_ireplace( '{{FNAME}}', 	$subscriber_meta->user_firstname, 	array( $merged_subject, $merged_message ) );
			list( $merged_subject, $merged_message ) = str_ireplace( '{{LNAME}}', 	$subscriber_meta->user_lastname, 	array( $merged_subject, $merged_message ) );
			list( $merged_subject, $merged_message ) = str_ireplace( '{{DNAME}}', 	$subscriber_meta->display_name, 	array( $merged_subject, $merged_message ) );
			list( $merged_subject, $merged_message ) = str_ireplace( '{{NNAME}}', 	$subscriber_meta->user_nicename, 	array( $merged_subject, $merged_message ) );
			list( $merged_subject, $merged_message ) = str_ireplace( '{{SITE}}', 	$subscriber_meta->user_url, 		array( $merged_subject, $merged_message ) );
			list( $merged_subject, $merged_message ) = str_ireplace( '{{USERNAME}}', $subscriber_meta->user_login, 	array( $merged_subject, $merged_message ) );
			
			//check for existing hash, if not there, create it
			if ( !$hash = get_user_meta( $subscriber->ID, '_pigeonpack_subscriber_hash', true ) ) {
				
				$hash = pigeonpack_hash( $subscriber_meta->user_email );
				update_user_meta( $subscriber->ID, '_pigeonpack_subscriber_hash', $hash );
				
			}
			
			$unsubscribe_url = get_home_url() . '?pigeonpack=unsubscribe&role_name=' . array_shift( $subscriber_meta->roles ) . '&subscriber=' . $hash;
			list( $merged_message, $merged_footer ) = str_ireplace( '{{UNSUBSCRIBE_URL}}', '<a href="' . $unsubscribe_url . '">' . __( 'Unsubscribe from this list', 'pigeonpack' ) . '</a>' , array( $merged_message, $merged_footer ) );
			
			$email = $subscriber_meta->user_email;
			
		} else if ( is_array( $subscriber ) ) {
			
			$subscriber_meta = maybe_unserialize( $subscriber['subscriber_meta'] );
			
			if ( isset( $subscriber['list_id'] ) )
				$list_fields = get_pigeonpack_list_fields( $subscriber['list_id'] );
			else
				$list_fields = array();
				
			foreach ( $list_fields as $list_field ) {
		
					list( $merged_subject, $merged_message ) = str_ireplace( array( '{{' . $list_field['merge'] . '}}', '{{MERGE' . $list_field['static_merge'] . '}}' ), ( isset( $subscriber_meta['M' . $list_field['static_merge']] ) ? $subscriber_meta['M' . $list_field['static_merge']] : '' ), array( $merged_subject, $merged_message ) );
				
			}
			
			//check for existing hash, if not there, create it
			if ( !$hash = $subscriber['subscriber_hash'] ) {
				
				$hash = pigeonpack_hash( $subscriber_meta['M0'] );
				update_pigeonpack_subscriber_hash( $subscriber['id'], $hash );
				
			}
			
			$optin_url = get_home_url() . '?pigeonpack=subscribe&list_id=' . $subscriber['list_id'] . '&subscriber=' . $hash;
			$merged_message = str_ireplace( '{{OPTIN_URL}}', '<a href="' . $optin_url . '">' . $optin_url . '</a>' , $merged_message );
			
			$unsubscribe_url = get_home_url() . '?pigeonpack=unsubscribe&list_id=' . $subscriber['list_id'] . '&subscriber=' . $hash;
			list( $merged_message, $merged_footer ) = str_ireplace( '{{UNSUBSCRIBE_URL}}', '<a href="' . $unsubscribe_url . '">' . __( 'Unsubscribe from this list', 'pigeonpack' ) . '</a>' , array( $merged_message, $merged_footer ) );
			
			$email = $subscriber['email'];
			
		}
		
		return apply_filters( 'pigeonpack_unmerge_subscriber', array( $email, $merged_subject, $merged_message, $merged_footer ), $subscriber, $email, $subject, $message, $footer );
	
    }   
	
}

if ( !function_exists( 'pigeonpack_unmerge_postdata' ) ) {

	/**
	 * Replaces {{MERGE}} variables with post data
	 *
	 * @since 0.0.1
	 * @uses apply_filters() Calls 'the_content' hook on post content
	 * @uses apply_filters() Calls 'the_excerpt' hook on post excerpt or post content (if excerpt is empty)
	 * @uses apply_filters() Calls 'pigeonpack_permalink' hook on permalink value for post
	 * @uses apply_filters() Calls 'pigeonpack_unmerge_postdata' hook on an array of merged subject and merged message
	 *      with original subject, message, and posts as second, third, and fourth arguments.
	 *
	 * @param string $subject Email Subject to be merged
	 * @param string $message Email Body to be merged
	 * @param array $posts Array of post IDs to process
	 * @return array Merged subject and merged body
	 */
	function pigeonpack_unmerge_postdata( $subject, $message, $posts ) {
		
		/**
		 * WordPress time format
		 */
		$dateformat = get_option( 'date_format' );
		
		$merged_subject = $subject;
		$merged_message = $message;
		
		if ( !empty( $posts ) ) {
			
			 if ( 1 <= count( $posts ) && preg_match( '/{{POST_LOOP_START}}(.*){{POST_LOOP_END}}/is', $message, $matches ) ) {
				//digest
				
				$digest_merge = $matches[1];	
				
				foreach( $posts as $post_id ) {
					
					$new_merge = $digest_merge;
				
					$digest_post = get_post( $post_id );
					
					if ( 'publish' === $digest_post->post_status ) {
						
						$new_merge = str_ireplace( '{{POST_TITLE}}', get_the_title( $digest_post->ID ), $new_merge );
						$new_merge = str_ireplace( '{{POST_CONTENT}}', apply_filters( 'the_content', $digest_post->post_content ), $new_merge );
						$new_merge = str_ireplace( '{{POST_EXCERPT}}', apply_filters( 'the_excerpt', ( !empty( $digest_post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $digest_post->post_content ) ) ), $new_merge );
						$new_merge = str_ireplace( '{{POST_AUTHOR}}', get_the_author_meta( 'display_name', $digest_post->post_author ), $new_merge );
						$new_merge = str_ireplace( '{{POST_DATE}}', date_i18n( $dateformat, strtotime( $digest_post->post_date ) ), $new_merge );
						$new_merge = str_ireplace( '{{POST_URL}}', apply_filters( 'pigeonpack_permalink', get_permalink( $digest_post->ID ) ), $new_merge );
						
						$digest_merged[] = $new_merge;
					
					}
					
				}
			
				$merged_message = str_ireplace( $matches[0], implode( $digest_merged ), $merged_message );
				
			} else {
				//individual
				
				foreach( $posts as $post_id ) {
				
					$ind_post = get_post( $post_id );
					
					if ( 'publish' === $ind_post->post_status ) {
						
						list( $merged_subject, $merged_message ) = str_ireplace( '{{POST_TITLE}}', get_the_title( $ind_post->ID ), array( $merged_subject, $merged_message ) );
						list( $merged_subject, $merged_message ) = str_ireplace( '{{POST_CONTENT}}', apply_filters( 'the_content', $ind_post->post_content ), array( $merged_subject, $merged_message ) );
						list( $merged_subject, $merged_message ) = str_ireplace( '{{POST_EXCERPT}}', apply_filters( 'the_excerpt', ( !empty( $ind_post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $ind_post->post_content ) ) ), array( $merged_subject, $merged_message ) );
						list( $merged_subject, $merged_message ) = str_ireplace( '{{POST_AUTHOR}}', get_the_author_meta( 'display_name', $ind_post->post_author ), array( $merged_subject, $merged_message ) );
						list( $merged_subject, $merged_message ) = str_ireplace( '{{POST_DATE}}', date_i18n( $dateformat, strtotime( $ind_post->post_date ) ), array( $merged_subject, $merged_message ) );
						$merged_message = str_ireplace( '{{POST_URL}}', apply_filters( 'pigeonpack_permalink', get_permalink( $ind_post->ID ) ), $merged_message );
					
					}
					
				}
				
			}
			
		}
		
		return apply_filters( 'pigeonpack_unmerge_postdata', array( $merged_subject, $merged_message ), $subject, $message, $posts );
		
	}

}

if ( !function_exists( 'pigeonpack_unmerge_misc' ) ) {

	/**
	 * Replaces {{MERGE}} variables with miscellaneous data
	 *
	 * @since 0.0.1
	 * @uses apply_filters() Calls 'pigeonpack_unmerge_misc' hook on an array of merged subject and merged message
	 *      with original subject, message, and footer as next arguments.
	 *
	 * @param string $subject Email subject to be merged
	 * @param string $message Email body to be merged
	 * @param string $footer Email footer to be merged
	 * @param array $list_info Email list info to be merged
	 * @return array Merged subject, merged body, and merged footer
	 */
	function pigeonpack_unmerge_misc( $subject, $message, $footer, $list_info ) {
		
		/**
		 * WordPress time format
		 */
		$dateformat = get_option( 'date_format' );
		
		$pigeonpack_settings = get_pigeonpack_settings();
		
		$merged_subject = $subject;
		$merged_message = $message;
		$merged_footer = $footer;
		
		list( $merged_subject, $merged_message ) = str_ireplace( '{{DATE}}', date_i18n( $dateformat ), array( $merged_subject, $merged_message ) );
		
		if ( !empty( $list_info ) ) {
		
			if ( 'list' === $list_info['type'] ) {
				
				list( $merged_subject, $merged_message, $merged_footer ) = str_ireplace( '{{LIST_NAME}}', get_the_title( $list_info['id'] ), array( $merged_subject, $merged_message, $merged_footer  ) );
				
				$required_footer = get_post_meta( $list_info['id'], '_pigeonpack_required_footer_settings', true );
				
				//If the required settings are sent of the list, use the Pigeon Pack defaults!
				$required_footer['company'] = empty( $required_footer['company'] ) ? $pigeonpack_settings['company'] : $required_footer['company'];
				$required_footer['address'] = empty( $required_footer['address'] ) ? $pigeonpack_settings['address'] : $required_footer['address'];
				$required_footer['reminder'] = empty( $required_footer['reminder'] ) ? $pigeonpack_settings['reminder'] : $required_footer['reminder'];
				
				$required_footer_string = '<p id="required-address-info">'
										. __( 'Our mailing address is:', 'pigeonpack' ) . '<br />' 
										. $required_footer['company'] . '<br />'
										. $required_footer['address'] . '<br />'
										. '</p>';
										
				list( $merged_message, $merged_footer ) = str_ireplace( '{{REQUIRED_FOOTER_CONTENT}}', $required_footer_string, array( $merged_message, $merged_footer ));
				list( $merged_message, $merged_footer ) = str_ireplace( '{{REMINDER}}', '<p id="reminder">' . $required_footer['reminder'] . '</p>', array( $merged_message, $merged_footer ) );
				
			} else if ( 'role' === $list_info['type'] ) {
				
				list( $merged_subject, $merged_message, $merged_footer  ) = str_ireplace( '{{LIST_NAME}}', ucfirst( $list_info['id'] ), array( $merged_subject, $merged_message, $merged_footer  ) );
				
				$required_footer_string = '<p id="required-address-info">'
										. __( 'Our mailing address is:', 'pigeonpack' ) . '<br />' 
										. $pigeonpack_settings['company'] . '<br />'
										. $pigeonpack_settings['address'] . '<br />'
										. '</p>';
										
				list( $merged_message, $merged_footer ) = str_ireplace( '{{REQUIRED_FOOTER_CONTENT}}', $required_footer_string, array( $merged_message, $merged_footer ) );
				list( $merged_message, $merged_footer ) = str_ireplace( '{{REMINDER}}', '<p id="reminder">' . $pigeonpack_settings['reminder'] . '</p>', array( $merged_message, $merged_footer ) );
				
			}
			
			list( $merged_subject, $merged_message, $merged_footer ) = apply_filters( 'custom_pigeonpack_unmerge_misc_list_info', array( $merged_subject, $merged_message, $merged_footer ), $subject, $message, $footer, $list_info );
			
		} else {
		
			list( $merged_subject, $merged_message ) = str_ireplace( '{{LIST_NAME}}', '', array( $merged_subject, $merged_message ) );
			list( $merged_message, $merged_footer ) = str_ireplace( '{{REQUIRED_FOOTER_CONTENT}}', '', array( $merged_message, $merged_footer ) );
			list( $merged_message, $merged_footer ) = str_ireplace( '{{REMINDER}}', '', array( $merged_message, $merged_footer ) );
			
		}
		
		return apply_filters( 'pigeonpack_unmerge_misc', array( $merged_subject, $merged_message, $merged_footer ), $subject, $message, $footer, $list_info );
		
	}
	
}

if ( !function_exists( 'pigeonpack_exclude_post' ) ){
	
	/**
	 * Check to see if post is in an included or exluded category
	 *
	 * @since 0.0.1
	 * @uses wp_get_post_categories() to get current post categories
	 * @link http://codex.wordpress.org/Function_Reference/wp_get_post_categories
	 *
	 * @param int $campaign_id Used to get meta for in/ex-cluded cateories
	 * @param int $post_id Used to get cateories associated with post
	 * return bool TRUE if post should be excluded from mailer, FALSE if post should be included
	 */
	function pigeonpack_exclude_post( $campaign_id, $post_id ) {
			
		$clude			= ( $var = get_post_meta( $campaign_id, '_pigeonpack_clude_cat', true ) ) ? $var : 'in';
		$clude_cats		= ( $var = get_post_meta( $campaign_id, '_pigeonpack_clude_cats', true ) ) ? $var : array( 0 );
		$post_cats 		= wp_get_post_categories( $post_id );
		$cat_intersect 	= array_intersect( $post_cats, $clude_cats );

		if ( 'in' === $clude && ( !in_array( '0', $clude_cats ) && empty( $cat_intersect ) ) )
			return true;
			
		if ( 'ex' === $clude && ( in_array( '0', $clude_cats ) || !empty( $cat_intersect ) ) )
			return true;
			
		return false;
					
	}
	
}

if ( !function_exists( 'pigeonpack_mail' ) ) {

	/**
	 * Process the campaign mail
	 *
	 * @since 0.0.1
	 * @uses apply_filters() Calls 'the_content' hook on campaign post content.
	 * @uses apply_filters() Calls 'default_pigeonpack_mail_footer' hook on footer string with replacement arguments.
	 * @uses apply_filters() Calls 'pre_subscriber_loop_pigeonpack_headers' hook on an array of headers before subscriber loop is processing.
	 * @uses apply_filters() Calls 'subscriber_loop_pigeonpack_headers' hook on an array of headers while subscriber loop is processing.
	 *
	 * @param int|object $campaign Used to get details about which campaign is being mailed out
	 * @param array $posts if WordPress post campaign, which posts are included in this campaign
	 * @param int $offset The offset of which subscribers need to be processed
	 */
	function pigeonpack_mail( $campaign, $posts = array(), $offset = 0, $recipients_arr = array() ) {
		
		$campaign = get_post( $campaign );
	
		//just incase the campaign was set to draft, stop processing here
		if ( 'publish' !== $campaign->post_status )
			return;
	
		$pigeonpack_settings = get_pigeonpack_settings();
	
		$from_name = get_post_meta( $campaign->ID, '_pigeonpack_from_name', true );
		$from_name = ( $from_name ) ? $from_name : $pigeonpack_settings['from_name'];
		$from_email = get_post_meta( $campaign->ID, '_pigeonpack_from_email', true );
		$from_email = ( $from_email ) ? $from_email : $pigeonpack_settings['from_email'];
		
		if ( empty( $recipients_arr ) )
			$recipients_arr = get_post_meta( $campaign->ID, '_pigeonpack_recipients', true );
		
		$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
		$headers = apply_filters( 'pre_subscriber_loop_pigeonpack_headers', $headers );
		
		$subject = $campaign->post_title;
		$message =  apply_filters( 'the_content', $campaign->post_content );
		$footer = apply_filters( 'default_pigeonpack_mail_footer', '{{REMINDER}}{{REQUIRED_FOOTER_CONTENT}}{{UNSUBSCRIBE_URL}}' );
					
		if ( !is_array( $recipients_arr ) )
			$recipients_arr = array( $recipients_arr );
			
		foreach( $recipients_arr as $recipients ) {
			
			list( $subject, $message, $footer ) = pigeonpack_unmerge_misc( $subject, $message, $footer, extract_pigeonpack_list_id( $recipients ) );
			
			if ( !empty( $posts ) ) {
			
				//just need to double check and make sure a post wasn't moved to an excluded category
				foreach ( $posts as $key => $post_id ) {
				
					if ( pigeonpack_exclude_post( $campaign->ID, $post_id ) )
						unset( $posts[$key] );
				
				}
				
				list( $subject, $message ) = pigeonpack_unmerge_postdata( $subject, $message, $posts );
				
			}
						
			$args = array(
				'limit' 	=> $pigeonpack_settings['emails_per_cycle'],
				'offset' 	=> $offset,
			);
			$subscribers = get_pigeonpack_subscriber_by_type( $recipients, $args );
			
			if ( !empty( $subscribers ) ) {
					
				// If we're using an SMTP server, set it up now...
				if ( isset( $pigeonpack_settings['smtp_enable'] ) && 'smtp' === $pigeonpack_settings['smtp_enable'] )
					add_action( 'phpmailer_init', 'pigeonpack_phpmailer_init' );
					
				foreach ( $subscribers as $subscriber ) {
					
					list( $email, $merged_subject, $merged_message, $merged_footer ) = pigeonpack_unmerge_subscriber( $subscriber, $subject, $message, $footer );
					
					$content_type = 'content-type: ' . pigeonpack_subscriber_content_type( $subscriber );
					
					$body = $merged_message . $merged_footer;
					
					if ( 'content-type: text/plain' === $content_type ) {
						
						require_once( PIGEON_PACK_PLUGIN_PATH . '/includes/html2txt.php' );
						
						$body = convert_html_to_text( $body );
						
					}
					
					$subscriber_headers = apply_filters( 'subscriber_loop_pigeonpack_headers', array_merge( $headers, array( $content_type ) ), $subscriber );
			
					wp_mail( $email, strip_tags( $merged_subject ), $body, $subscriber_headers );
					
				}
				
				//schedule the next event for this campaign...
				wp_schedule_single_event( current_time( 'timestamp', 1 ) + ( $pigeonpack_settings['email_cycle'] * MINUTE_IN_SECONDS ), 'scheduled_pigeonpack_mail', array( $campaign->ID, $posts, $offset + $pigeonpack_settings['emails_per_cycle'], $recipients ) );
				
			}
			
		}
		
	}
	add_action( 'scheduled_pigeonpack_mail', 'pigeonpack_mail', 10, 4 ); //wp_schedule_single_event action
	
}

if ( !function_exists( 'pigeonpack_subscriber_content_type' ) ) {

	/**
	 * Determines and returns subscriber's prefered email content type (plain text or html)
	 *
	 * @since 0.0.1
	 *
	 * @param object|array $subscriber WP_User object or Pigeon Pack subscriber array
	 * @return string content type preference by subscriber (if defined), default 'text/html'
	 */
	function pigeonpack_subscriber_content_type( $subscriber ) {
	
		if ( is_object( $subscriber ) ) { //Looks like this wasn't introduced until WP3.5.x -> if ( is_a( $subscriber, 'WP_User' ) ) {
			
			$subscriber_options = get_user_option( 'pigeonpack_subscriber_options', $subscriber->ID );
			
			if ( isset( $subscriber_options['message_preference'] ) && 'plain' === $subscriber_options['message_preference'] )
				return 'text/plain';
			else
				return 'text/html';
			
		} else if ( is_array( $subscriber ) ) {
		
			if ( isset( $subscriber['message_preference'] ) && 'plain' === $subscriber['message_preference'] )
				return 'text/plain';
			else
				return 'text/html';
			
		}
			
		return 'text/html';
		
	}
	
}

if ( !function_exists( 'pigeonpack_wp_post_digest_scheduler' ) ) {

	/**
	 * Runs and processes digest campaigns schedule
	 *
	 * If there are posts to digest, schedule campaign mailing
	 * delete camaign post meta so digested posts aren't repeated
	 * schedule next digest campaign check
	 *
	 * @since 0.0.1
	 *
	 * @param int $campaign_id Digest campaign to process
	 * @param int $schedule epoch time (in GMT) of current schedule -- used to remove old schedule from meta table
	 */
	function pigeonpack_wp_post_digest_scheduler( $campaign_id, $schedule ) {
		
		$posts = get_post_meta( $campaign_id, '_pigeonpack_digest_posts', true );
		
		//schedule email campaign for this digest...
		if ( !empty( $posts ) ) {
			
			pigeonpack_campaign_scheduler( $campaign_id, $posts );
			delete_post_meta( $campaign_id, '_pigeonpack_digest_posts' ); //we don't want to resend these posts, so remove them from the scheduler
			
		}
	
		//schedule next digest event...
		$digest = get_post_meta( $campaign_id, '_pigeonpack_wp_post_digest', true );
		
		switch( $digest['freq'] ) {
		
			case 'monthly':
				$today = date_i18n( 'j' );
				$month = date_i18n( 'n' );
				
				if ( 'last_day'	=== $digest['date'] ) {
					
					$date = date_i18n( 't', strtotime( '+1 month' ) );
					$month = date_i18n( 'n', strtotime( '+1 month' ) );
						
					$schedule = strtotime( $date . " " . $month );
					$schedule = strtotime( $digest['time'], $schedule );
						
				} else {
					
					$schedule = strtotime( '+1 month', $schedule );
				
				}
				break;
				
			case 'weekly':
				$schedule = strtotime( '+1 week', $schedule );
				break;
				
			case 'daily':
			default:
				$today = date_i18n( 'w' );
				$days = $digest['days'];
			
				do {
					
					$day = array_shift( $days );
					
				} while( $today >= $day && !empty( $days ) );
				
				if ( empty( $days ) )
					$day = $digest['days'][0]; //first element on array
					
				$schedule = strtotime( 'next ' . pigeonpack_day_string( $day  ) );
				$schedule = strtotime( $digest['time'], $schedule );
				break;
			
		}
		
		//wp_schedule needs GMT
		$schedule = strtotime( get_gmt_from_date( date_i18n( 'Y-m-d H:i:s', $schedule ) ) );
		
		//schedule the next event for this digest...
		wp_schedule_single_event( $schedule, 'scheduled_wp_post_digest_campaign', array( $campaign_id, $schedule ) );
		
		//update the post meta with the event schedule details for next update/publish
		update_post_meta( $campaign_id, '_pigeonpack_scheduled_event', array( $schedule, array( $campaign_id, $schedule ) ) );
	
	}
	add_action( 'scheduled_wp_post_digest_campaign', 'pigeonpack_wp_post_digest_scheduler', 10, 2 ); //wp_schedule_single_event action
	
}

if ( !function_exists( 'pigeonpack_phpmailer_init' ) ) {

	/**
	 * Use SMTP if set in Pigeon Pack settings
	 *
	 * @since 0.0.1
	 * @uses PHPMailer
	 *
	 * @param object $phpmailer reference initialized by WordPress
	 */
	function pigeonpack_phpmailer_init( &$phpmailer ) {
		
		$pigeonpack_settings = get_pigeonpack_settings();
		
		$phpmailer->IsSMTP(); //Set PHP Mailer to use SMTP
		$phpmailer->Host = $pigeonpack_settings['smtp_server'];
		$phpmailer->Port = $pigeonpack_settings['smtp_port'];
		
		if ( 'none' !== $pigeonpack_settings['smtp_authentication'] ) {
			
			$phpmailer->Username = !empty( $pigeonpack_settings['smtp_username'] ) ? $pigeonpack_settings['smtp_username'] : '';
			$phpmailer->Password = !empty( $pigeonpack_settings['smtp_password'] ) ? $pigeonpack_settings['smtp_password'] : '';
			
		}
		
		$phpmailer->SMTPSecure = 'none' === $pigeonpack_settings['smtp_encryption'] ? '' : $pigeonpack_settings['smtp_encryption'];
		
	}
	
}

if ( !function_exists( 'pigeonpack_hash' ) ) {

	/**
	 * Creates a 32-character hash string
	 *
	 * Generally used to create a unique hash for each subscriber, stored in the database
	 * and used for campaign links
	 *
	 * @since 0.0.1
	 *
	 * @param string $str String you want to hash
	 */
	function pigeonpack_hash( $str ) {
	
		global $wpdb;
		
		$hash = md5( microtime( true ) . uniqid() . $str );
		
		$hash_count = $wpdb->get_var( $wpdb->prepare( 
			'
				SELECT COUNT(*) 
				FROM ' . $wpdb->prefix . 'pigeonpack_subscribers 
				WHERE `subscriber_hash` = %s
			', 
			$hash
		) );
		
		if ( !empty( $hash_count ) )
			$hash = pigeonpack_hash( $str );
		
		return $hash;
		
	}
	
}

if ( !function_exists( 'pigeonpack_day_string' ) ) {

	/**
	 * helper function, used to print proper day for given number
	 *
	 * @since 0.0.1
	 *
	 * @param int $day_number Number to check
	 * @return string Day
	 */
	function pigeonpack_day_string( $day_number ) {
	
		if ( $day_number < 0 || $day_number > 7 )
			return __( 'Sunday', 'pigeonpack' ); // Default to Sunday
	
		//Sunday is day 0 and day 7;
		$day_strings = array( 
			__( 'Sunday', 'pigeonpack' ),	
			__( 'Monday', 'pigeonpack' ),
			__( 'Tuesday', 'pigeonpack' ), 
			__( 'Wendesday', 'pigeonpack' ), 
			__( 'Thursday', 'pigeonpack' ), 
			__( 'Friday', 'pigeonpack' ), 
			__( 'Saturday', 'pigeonpack' ), 
			__( 'Sunday', 'pigeonpack' ),
		);
		
		return $day_strings[$day_number];
		
	}
	
}

if ( !function_exists( 'ordinal_suffix' ) ) {

	/**
	 * helper function, used to print proper ordinal suffix for given number
	 *
	 * HT: http://stackoverflow.com/a/6604934
	 *
	 * @since 0.0.1
	 *
	 * @param int $num Number to check
	 * @return string Number plus correct ordinal suffix
	 */
	function ordinal_suffix( $num ) {
		
		if( $num < 11 || $num > 13 ) {
	
			switch( $num % 10 ) {
			
				case 1: return $num . 'st';
				case 2: return $num . 'nd';
				case 3: return $num . 'rd';
				
			}
		
		}
		
		return $num . 'th';
		
	}

}

if ( !function_exists( 'wp_print_r' ) ) { 

	/**
	 * Helper function used for printing out debug information
	 *
	 * HT: Glenn Ansley @ iThemes.com
	 *
	 * @since 0.0.1
	 *
	 * @param int $args Arguments to pass to print_r
	 * @param bool $die TRUE to die else FALSE (default TRUE)
	 */
    function wp_print_r( $args, $die = true ) { 
	
        $echo = '<pre>' . print_r( $args, true ) . '</pre>';
		
        if ( $die ) die( $echo );
        	else echo $echo;
		
    }   
	
}
