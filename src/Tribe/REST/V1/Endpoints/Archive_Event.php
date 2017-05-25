<?php


class Tribe__Events__REST__V1__Endpoints__Archive_Event
	extends Tribe__Events__REST__V1__Endpoints__Base
	implements Tribe__REST__Endpoints__GET_Endpoint_Interface, Tribe__Documentation__Swagger__Provider_Interface {

	/**
	 * @var Tribe__Events__REST__Interfaces__Post_Repository
	 */
	protected $repository;

	/**
	 * @var array An array mapping the REST request supported query vars to the args used in a TEC WP_Query.
	 */
	protected $supported_query_vars = array(
		'page'       => 'paged',
		'per_page'   => 'posts_per_page',
		'start_date' => 'start_date',
		'end_date'   => 'end_date',
		'search'     => 's',
		'categories' => 'categories',
		'tags'       => 'tags',
		'venue'      => 'venue',
		'organizer'  => 'organizer',
		'featured'   => 'featured',
	);

	/**
	 * @var int The total number of events according to the current request parameters and user access rights.
	 */
	protected $total;

	/**
	 * @var Tribe__Validator__Interface
	 */
	protected $validator;

	/**
	 * Tribe__Events__REST__V1__Endpoints__Archive_Event constructor.
	 *
	 * @param Tribe__REST__Messages_Interface                  $messages
	 * @param Tribe__Events__REST__Interfaces__Post_Repository $repository
	 * @param Tribe__Events__Validator__Interface              $validator
	 */
	public function __construct(
		Tribe__REST__Messages_Interface $messages,
		Tribe__Events__REST__Interfaces__Post_Repository $repository,
		Tribe__Events__Validator__Interface $validator
	) {
		parent::__construct( $messages );
		$this->repository = $repository;
		$this->validator = $validator;
	}

	/**
	 * Handles GET requests on the endpoint.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_REST_Response An array containing the data on success or a WP_Error instance on failure.
	 */
	public function get( WP_REST_Request $request ) {
		$args = array();
		$date_format = Tribe__Date_Utils::DBDATETIMEFORMAT;

		try {
			$args['paged'] = $request['page'];
			$args['posts_per_page'] = $request['per_page'];
			$args['start_date'] = isset( $request['start_date'] ) ?
				Tribe__Timezones::localize_date( $date_format, $request['start_date'] )
				: false;
			$args['end_date'] = isset( $request['end_date'] ) ?
				Tribe__Timezones::localize_date( $date_format, $request['end_date'] )
				: false;
			$args['s'] = $request['search'];

			$args['meta_query'] = array_filter( array(
				$this->parse_meta_query_entry( $request['venue'], '_EventVenueID', '=', 'NUMERIC' ),
				$this->parse_meta_query_entry( $request['organizer'], '_EventOrganizerID', '=', 'NUMERIC' ),
				$this->parse_featured_meta_query_entry( $request['featured'] ),
			) );

			$args['tax_query'] = array_filter( array(
				$this->parse_terms_query( $request['categories'], Tribe__Events__Main::TAXONOMY ),
				$this->parse_terms_query( $request['tags'], 'post_tag' ),
			) );

			$args = $this->parse_args( $args, $request->get_default_params() );

			$data = array( 'events' => array() );

			$data['rest_url'] = $this->get_current_rest_url( $args );
		} catch ( Tribe__REST__Exceptions__Exception $e ) {
			return new WP_Error( $e->getCode(), $e->getMessage(), array( 'status' => $e->getStatus() ) );
		}

		$cap = get_post_type_object( Tribe__Events__Main::POSTTYPE )->cap->edit_posts;
		$args['post_status'] = current_user_can( $cap ) ? 'any' : 'publish';

		// Due to an incompatibility between date based queries and 'ids' fields we cannot do this, see `wp_list_pluck` use down
		// $args['fields'] = 'ids';

		if ( empty( $args['posts_per_page'] ) ) {
			$args['posts_per_page'] = $this->get_default_posts_per_page();
		}

		$events = tribe_get_events( $args );

		$page = $this->parse_page( $request ) ? $this->parse_page( $request ) : 1;

		if ( empty( $events ) ) {
			$message = $this->messages->get_message( 'event-archive-page-not-found' );

			return new WP_Error( 'event-archive-page-not-found', $message, array( 'status' => 404 ) );
		}

		$events = wp_list_pluck( $events, 'ID' );

		unset( $args['fields'] );

		if ( $this->has_next( $args, $page ) ) {
			$data['next_rest_url'] = $this->get_next_rest_url( $data['rest_url'], $page );
		}

		if ( $this->has_previous( $page, $args ) ) {
			$data['previous_rest_url'] = $this->get_previous_rest_url( $data['rest_url'], $page );;
		}

		foreach ( $events as $event_id ) {
			$data['events'][] = $this->repository->get_event_data( $event_id );
		}

		$data['total'] = $total = $this->get_total( $args );
		$data['total_pages'] = $this->get_total_pages( $total, $args['posts_per_page'] );

		/**
		 * Filters the data that will be returned for an events archive request.
		 *
		 * @param array           $data    The retrieved data.
		 * @param WP_REST_Request $request The original request.
		 */
		$data = apply_filters( 'tribe_rest_events_archive_data', $data, $request );

		$response = new WP_REST_Response( $data );

		if ( isset( $data['total'] ) && isset( $data['total_pages'] ) ) {
			$response->header( 'X-TEC-Total', $data['total'], true );
			$response->header( 'X-TEC-TotalPages', $data['total_pages'], true );
		}

		return $response;
	}

	/**
	 * Parses the `page` argument from the request.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|int The `page` argument provided in the request or `false` if not set.
	 */
	protected function parse_page( WP_REST_Request $request ) {
		return !empty( $request['page'] ) ? intval( $request['page'] ) : false;
	}

	/**
	 * Parses the `per_page` argument from the request.
	 *
	 * @param int $per_page The `per_page` param provided by the request.
	 * @return bool|int The `per_page` argument provided in the request or `false` if not set.
	 */
	public function sanitize_per_page( $per_page ) {
		return ! empty( $per_page ) ?
			min( $this->get_max_posts_per_page(), intval( $per_page ) )
			: false;
	}

	/**
	 * Parses the request for featured events.
	 *
	 * @param string $featured
	 *
	 * @return array|bool Either the meta query for featured events or `false` if not specified.
	 */
	protected function parse_featured_meta_query_entry( $featured ) {
		if ( null === $featured ) {
			return false;
		}

		$parsed = array(
			'key' => Tribe__Events__Featured_Events::FEATURED_EVENT_KEY,
			'compare' => $featured ? 'EXISTS' : 'NOT EXISTS',
		);

		return $parsed;
	}

	/**
	 * @param array|string $terms A list of terms term_id or slugs or a single term term_id or slug.
	 * @param string $taxonomy The taxonomy of the terms to parse.
	 *
	 * @return array|bool Either an array of `terms_ids` or `false` on failure.
	 *
	 * @throws Tribe__REST__Exceptions__Exception If one of the terms does not exist for the specified taxonomy.
	 */
	protected function parse_terms_query( $terms, $taxonomy ) {
		if ( empty( $terms ) ) {
			return false;
		}

		$parsed    = array();
		$requested = Tribe__Utils__Array::list_to_array($terms);

		foreach ( $requested as $t ) {
			$term = get_term_by( 'slug', $t, $taxonomy );

			if ( false === $term ) {
				$term = get_term_by( 'id', $t, $taxonomy );
			}

			$parsed[] = $term->term_id;
		}

		if ( ! empty( $parsed ) ) {
			$parsed = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $parsed,
			);
		}

		return $parsed;
	}

	/**
	 * Parses and created a meta query entry in from the request.
	 *
	 * @param string $meta_value The value that should be used for comparison.
	 * @param string $meta_key   The meta key that should be used for the comparison.
	 * @param string $compare    The comparison operator.
	 * @param string $type       The type to which compared values should be cast.
	 * @param string $relation   If multiple meta values are provided then this is the relation that the query should use.
	 *
	 * @return array|bool The meta query entry or `false` on failure.
	 */
	protected function parse_meta_query_entry( $meta_value, $meta_key, $compare = '=', $type = 'CHAR', $relation = 'OR' ) {
		if ( empty( $meta_value ) ) {
			return false;
		}

		$meta_values = Tribe__Utils__Array::list_to_array( $meta_value );

		$parsed = array();
		foreach ( $meta_values as $meta_value ) {
			$parsed[] = array(
				'key'     => $meta_key,
				'value'   => $meta_value,
				'type'    => $type,
				'compare' => $compare,
			);
		}

		if ( count( $parsed ) > 1 ) {
			$parsed['relation'] = $relation;
		}

		return $parsed;
	}

	/**
	 * Builds and returns the current rest URL depending on the query arguments.
	 *
	 * @param array $args
	 *
	 * @return string
	 */
	protected function get_current_rest_url( array $args = array() ) {
		$url = tribe_events_rest_url( 'events/' );

		$flipped = array_flip( $this->supported_query_vars );
		$values  = array_intersect_key( $args, $flipped );
		$keys    = array_intersect_key( $flipped, $values );

		if ( ! empty( $keys ) ) {
			$url = add_query_arg( array_combine( array_values( $keys ), array_values( $values ) ), $url );
		}

		return $url;
	}

	/**
	 * Whether there is a next page in respect to the specified one.
	 *
	 * @param array $args
	 * @param int $page
	 *
	 * @return bool
	 */
	protected function has_next( $args, $page ) {
		$overrides = array(
			'paged'                  => $page + 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false
		);
		$next      = tribe_get_events( array_merge( $args, $overrides ) );

		return ! empty( $next );
	}

	/**
	 * Builds and returns the next page REST URL.
	 *
	 * @param string $rest_url
	 * @param int $page
	 *
	 * @return string
	 */
	protected function get_next_rest_url( $rest_url, $page ) {
		return add_query_arg( array( 'page' => $page + 1 ), remove_query_arg( 'page', $rest_url ) );
	}

	/**
	 * Whether there is a previous page in respect to the specified one.
	 *
	 * @param array $args
	 * @param int $page
	 *
	 * @return bool
	 */
	protected function has_previous( $page, $args ) {
		$overrides = array(
			'paged'                  => $page - 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false
		);
		$previous  = tribe_get_events( array_merge( $args, $overrides ) );

		return 1 !== $page && ! empty( $previous );
	}

	/**
	 * Builds and returns the previous page REST URL.
	 *
	 * @param string $rest_url
	 * @param int $page
	 *
	 * @return string
	 */
	protected function get_previous_rest_url( $rest_url, $page ) {
		$rest_url = remove_query_arg( 'page', $rest_url );

		return 2 === $page ? $rest_url : add_query_arg( array( 'page' => $page - 1 ), $rest_url );
	}

	/**
	 * @return int
	 */
	protected function get_max_posts_per_page() {
		/**
		 * Filters the maximum number of events per page that should be returned.
		 *
		 * @param int $per_page Default to 50.
		 */
		return apply_filters( 'tribe_rest_event_max_per_page', 50 );
	}

	/**
	 * @param array $args
	 *
	 * @return int
	 */
	protected function get_total( $args ) {
		$this->total = count( tribe_get_events( array_merge( $args, array(
			'posts_per_page'         => - 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false
		) ) ) );

		return $this->total;
	}

	/**
	 * Returns the total number of pages depending on the `per_page` setting.
	 *
	 * @param int $total
	 * @param int $per_page
	 *
	 * @return int
	 */
	protected function get_total_pages( $total, $per_page = null ) {
		$per_page    = $per_page ? $per_page : get_option( 'posts_per_page' );
		$total_pages = intval( ceil( $total / $per_page ) );

		return $total_pages;
	}

	/**
	 * Returns an array in the format used by Swagger 2.0.
	 *
	 * While the structure must conform to that used by v2.0 of Swagger the structure can be that of a full document
	 * or that of a document part.
	 * The intelligence lies in the "gatherer" of informations rather than in the single "providers" implementing this
	 * interface.
	 *
	 * @link http://swagger.io/
	 *
	 * @return array An array description of a Swagger supported component.
	 */
	public function get_documentation() {
		return array(
			'get' => array(
				'parameters' => array(
					array(
						'name'        => 'page',
						'in'          => 'query',
						'description' => __( 'The archive page to return', 'the-events-calendar' ),
						'type'        => 'integer',
						'required'    => false,
						'default'     => 1,
					),
					array(
						'name'        => 'per_page',
						'in'          => 'query',
						'description' => __( 'The number of events to return on each page', 'the-events-calendar' ),
						'type'        => 'integer',
						'required'    => false,
						'default'     => get_option( 'posts_per_page' ),
					),
					array(
						'name'        => 'start_date',
						'in'          => 'query',
						'description' => __( 'Events should start after the specified date', 'the-events-calendar' ),
						'type'        => 'date',
						'required'    => false,
						'default'     => date( Tribe__Date_Utils::DBDATETIMEFORMAT, time() ),
					),
					array(
						'name'        => 'end_date',
						'in'          => 'query',
						'description' => __( 'Events should start before the specified date', 'the-events-calendar' ),
						'type'        => 'string',
						'required'    => false,
						'default'     => date( Tribe__Date_Utils::DBDATETIMEFORMAT, time() ),
					),
					array(
						'name'        => 'search',
						'in'          => 'query',
						'description' => __( 'Events should contain the specified string in the title or description', 'the-events-calendar' ),
						'type'        => 'string',
						'required'    => false,
						'default'     => '',
					),
					array(
						'name'        => 'categories',
						'in'          => 'query',
						'description' => __( 'Events should be assigned one of the specified categories slugs or IDs', 'the-events-calendar' ),
						'type'        => 'array',
						'required'    => false,
						'default'     => '',
					),
					array(
						'name'        => 'tags',
						'in'          => 'query',
						'description' => __( 'Events should be assigned one of the specified tags slugs or IDs', 'the-events-calendar' ),
						'type'        => 'array',
						'required'    => false,
						'default'     => '',
					),
				),
				'responses'  => array(
					'200' => array(
						'description' => __( 'Returns all the upcoming events matching the search criteria', 'the-event-calendar' ),
						'schema'      => array(
							'title' => 'events',
							'type'  => 'array',
							'items' => array( '$ref' => '#/definitions/Event' ),
						),
					),
					'400' => array(
						'description' => __( 'One or more of the specified query variables has a bad format', 'the-events-calendar' ),
					),
					'404' => array(
						'description' => __( 'No events match the query or the requested page was not found.', 'the-events-calendar' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the content of the `args` array that should be used to register the endpoint
	 * with the `register_rest_route` function.
	 *
	 * @return array
	 */
	public function GET_args() {
		return array(
			'page'       => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_positive_int' ),
				'default'           => 1,
			),
			'per_page'   => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_positive_int' ),
				'sanitize_callback' => array( $this, 'sanitize_per_page' ),
				'default'           => $this->get_default_posts_per_page(),
			),
			'start_date' => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_time' ),
				'default'           => Tribe__Timezones::localize_date(Tribe__Date_Utils::DBDATETIMEFORMAT,'yesterday 23:59'),
			),
			'end_date' => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_time' ),
				'default'           => date( Tribe__Date_Utils::DBDATETIMEFORMAT, strtotime( '+24 months' ) ),
			),
			's' => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_string' ),
			),
			'venue'     => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_venue_id' ),
			),
			'organizer' => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_organizer_id_list' ),
			),
			'featured'   => array(
				'required' => false,
			),
			'categories' => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_event_category' ),
			),
			'tags'       => array(
				'required'          => false,
				'validate_callback' => array( $this->validator, 'is_post_tag' ),
			),
		);
	}

	/**
	 * Parses the arguments populated parsing the request filling out with the defaults.
	 *
	 * @param array $args
	 * @param array $defaults
	 *
	 * @return array
	 */
	protected function parse_args( array $args, array $defaults ) {
		foreach ( $this->supported_query_vars as $request_key => $query_var ) {
			if ( isset( $defaults[ $request_key ] ) ) {
				$defaults[ $query_var ] = $defaults[ $request_key ];
			}
		}

		$args = wp_parse_args( array_filter( $args ), $defaults );

		return $args;
	}
}