<?php
class Tribe_Event_API {
	/* EVENT API */
	public static function createEvent($args) {
		$defaults = array(
			'post_type' => Events_Calendar_Pro::POSTTYPE
		);			

		$args = wp_parse_args( $args, $defaults);
		$eventId = wp_insert_post($args, true);	
		
		if( !is_wp_error($eventId) ) {
			Tribe_Event_API::saveEventMeta($eventId, $args, get_post( $eventId ) );
			return $eventId;
		}		
	}
	
	public static function updateEvent( $eventId, $args ) {
		$args['ID'] == $eventId;
		
		if(wp_update_post($args)) {
			Tribe_Event_API::saveEventMeta($eventId, $args, get_post( $eventId ) );
		}

		return $eventId;
	}
	
	public static function deleteEvent($eventId, $force_delete = false) {
		wp_delete_post($eventId, $force_delete);		
	}
	
	// abstracted so EventBrite can call without needing $_POST data
	public static function saveEventMeta($event_id, $data, $event = null) {
		global $sp_ecp;
		
		if( $data['EventAllDay'] == 'yes' || !isset($data['EventStartDate']) ) {
			$data['EventStartDate'] = TribeDateUtils::beginningOfDay($data['EventStartDate']);
			$data['EventEndDate'] = TribeDateUtils::endOfDay($data['EventEndDate']);
		} else {
			delete_post_meta( $event_id, '_EventAllDay' );
			$data['EventStartDate'] = date( TribeDateUtils::DBDATETIMEFORMAT, strtotime($data['EventStartDate'] . " " . $data['EventStartHour'] . ":" . $data['EventStartMinute'] . ":00 " . $data['EventStartMeridian']) );
			$data['EventEndDate'] = date( TribeDateUtils::DBDATETIMEFORMAT, strtotime($data['EventEndDate'] . " " . $data['EventEndHour'] . ":" . $data['EventEndMinute'] . ":59 " . $data['EventEndMeridian']) );
		}
		
		if(!$data['EventHideFromUpcoming']) delete_post_meta($event_id, '_EventHideFromUpcoming');

		// sanity check that start date < end date
		$startTimestamp = strtotime( $data['EventStartDate'] );
		$endTimestamp 	= strtotime( $data['EventEndDate'] );

		if ( $startTimestamp > $endTimestamp ) {
			$data['EventEndDate'] = $data['EventStartDate'];
		}
		
		if( !isset( $data['EventShowMapLink'] ) ) update_post_meta( $event_id, '_EventShowMapLink', 'false' );
		if( !isset( $data['EventShowMap'] ) ) update_post_meta( $event_id, '_EventShowMap', 'false' );
		
		$data['EventOrganizerID'] = Tribe_Event_API::saveEventOrganizer($data["Organizer"]);
		$data['EventVenueID'] = Tribe_Event_API::saveEventVenue($data["Venue"]);

		$sp_ecp->do_action('tribe_events_event_save', $event_id);

		//update meta fields
		foreach ( $sp_ecp->metaTags as $tag ) {
			$htmlElement = ltrim( $tag, '_' );
			if ( isset( $data[$htmlElement] ) && $tag != Events_Calendar_Pro::EVENTSERROROPT ) {
				if ( is_string($data[$htmlElement]) )
					$data[$htmlElement] = filter_var($data[$htmlElement], FILTER_SANITIZE_STRING);

				update_post_meta( $event_id, $tag, $data[$htmlElement] );
			}
		}

      	$sp_ecp->do_action('tribe_events_update_meta', $event_id, false, $data, $event);
	}	
	
	// used when saving event meta
	private static function saveEventOrganizer($data, $post=null) {
		// return if organizer is already created
		if($data['OrganizerID'] && $data['OrganizerID'] != "0")
			return $data['OrganizerID'];

		return Tribe_Event_API::createOrganizer($data);
	}
	
	// used when saving event meta
	private static function saveEventVenue($data, $post=null) {
		// return if Venue is already created
		if($data['VenueID'] && $data['VenueID'] != "0")
			return $data['VenueID'];

		return Tribe_Event_API::createVenue($data);
	}	
	
	/* ORGANIZER API */
	public static function createOrganizer($data) {
		if ( $data['Organizer'] ) {
			$postdata = array(
				'post_title' => $data['Organizer'],
				'post_type' => Events_Calendar_Pro::ORGANIZER_POST_TYPE,
				'post_status' => 'publish',
			);			

			$organizerId = wp_insert_post($postdata, true);		

			if( !is_wp_error($organizerId) ) {
				Tribe_Event_API::saveOrganizerMeta($organizerId, $data);
				return $organizerId;
			}
		}
	}	
	
	public static function deleteOrganizer($organizerId, $force_delete = false ) {
		wp_delete_post($organizerId, $force_delete);
	}		
	
	public static function updateOrganizer($organizerId, $data) {
		wp_update_post( array('post_title' => $data['Organizer'], 'ID'=>$organizerId ));		
		Tribe_Event_API::saveOrganizerMeta($organizerId, $data);
	}
	
	private static function saveOrganizerMeta($organizerId, $data) {
		foreach ($data as $key => $var) {
			update_post_meta($organizerId, '_Organizer'.$key, $var);
		}		
	}
	
	/* VENUE API */
	public static function createVenue($data) {
		if ( $data['Venue'] ) {
			$postdata = array(
				'post_title' => $data['Venue'],
				'post_type' => Events_Calendar_Pro::VENUE_POST_TYPE,
				'post_status' => 'publish',
			);			

			$venueId = wp_insert_post($postdata, true);		

			if( !is_wp_error($venueId) ) {
				Tribe_Event_API::saveVenueMeta($venueId, $data);
				return $venueId;
			}
		}
	}	
	
	public static function updateVenue($venueId, $data) {
		wp_update_post( array('post_title' => $data['Venue'], 'ID'=>$venueId ));		
		Tribe_Event_API::saveVenueMeta($venueId, $data);
	}
	
	
	public static function deleteVenue($venueId, $force_delete = false ) {
		wp_delete_post($venueId, $force_delete);
	}	
	
	private static function saveVenueMeta($venueId, $data) {
		foreach ($data as $key => $var) {
			update_post_meta($venueId, '_Venue'.$key, $var);
		}		
	}	
}
