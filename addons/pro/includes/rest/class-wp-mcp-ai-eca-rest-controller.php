<?php
/**
 * ECA Management REST API Controller
 *
 * Provides REST API endpoints for Extra-Curricular Activities and Student management.
 * Implements CRUD operations following WordPress REST API standards.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_ECA_REST_Controller
 *
 * REST API controller for ECA and Student CRUD operations.
 */
class WP_MCP_AI_ECA_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai/v1';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Constructor can be extended if needed.
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// ECA endpoints.
		$this->register_eca_routes();

		// Student endpoints.
		$this->register_student_routes();
	}

	/**
	 * Register ECA-related REST routes
	 */
	protected function register_eca_routes() {
		// List ECAs / Create ECA.
		register_rest_route(
			$this->namespace,
			'/ecas',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ecas' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => $this->get_list_ecas_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_eca' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => $this->get_create_eca_params(),
				),
			)
		);

		// Get/Update/Delete specific ECA.
		register_rest_route(
			$this->namespace,
			'/ecas/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_eca' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param > 0;
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_eca' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => $this->get_update_eca_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_eca' ),
					'permission_callback' => array( $this, 'check_delete_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param > 0;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Register Student-related REST routes
	 */
	protected function register_student_routes() {
		// List Students / Create Student.
		register_rest_route(
			$this->namespace,
			'/students',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_students' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => $this->get_list_students_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_student' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => $this->get_create_student_params(),
				),
			)
		);

		// Get/Update/Delete specific Student.
		register_rest_route(
			$this->namespace,
			'/students/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_student' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param > 0;
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_student' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => $this->get_update_student_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_student' ),
					'permission_callback' => array( $this, 'check_delete_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param > 0;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Check read permission
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_read_permission( $request ) {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view ECAs or students.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Check write permission
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_write_permission( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to create or edit ECAs or students.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Check delete permission
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_delete_permission( $request ) {
		if ( ! current_user_can( 'delete_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to delete ECAs or students.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Get list of ECAs
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ecas( $request ) {
		// Use the existing list_ecas tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_List_ECAs' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'ECA listing tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool = new WP_MCP_AI_Tool_List_ECAs();
		$args = array(
			'eca_type'         => $request->get_param( 'eca_type' ),
			'day'              => $request->get_param( 'day' ),
			'year_group'       => $request->get_param( 'year_group' ),
			'status'           => $request->get_param( 'status' ),
			'is_paid'          => $request->get_param( 'is_paid' ),
			'has_availability' => $request->get_param( 'has_availability' ),
			'search'           => $request->get_param( 'search' ),
			'page'             => $request->get_param( 'page' ),
			'per_page'         => $request->get_param( 'per_page' ),
		);

		// Remove null values.
		$args = array_filter(
			$args,
			function ( $value ) {
				return null !== $value;
			}
		);

		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get single ECA
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_eca( $request ) {
		// Use the existing get_eca tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Get_ECA' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'ECA retrieval tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool    = new WP_MCP_AI_Tool_Get_ECA();
		$args    = array( 'eca_id' => $request->get_param( 'id' ) );
		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create ECA
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_eca( $request ) {
		// Use the existing create_eca tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_ECA' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'ECA creation tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool    = new WP_MCP_AI_Tool_Create_ECA();
		$args    = $request->get_params();
		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update ECA
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_eca( $request ) {
		// Use the existing update_eca tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Update_ECA' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'ECA update tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool         = new WP_MCP_AI_Tool_Update_ECA();
		$args         = $request->get_params();
		$args['eca_id'] = $request->get_param( 'id' );
		$context      = array( 'user_id' => get_current_user_id() );
		$result       = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Delete ECA
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_eca( $request ) {
		// Use the existing delete_eca tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delete_ECA' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'ECA deletion tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool    = new WP_MCP_AI_Tool_Delete_ECA();
		$args    = array( 'eca_id' => $request->get_param( 'id' ) );
		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get list of students
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_students( $request ) {
		// Use the existing list_students tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_List_Students' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'Student listing tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool = new WP_MCP_AI_Tool_List_Students();
		$args = array(
			'year_group' => $request->get_param( 'year_group' ),
			'house'      => $request->get_param( 'house' ),
			'search'     => $request->get_param( 'search' ),
			'per_page'   => $request->get_param( 'per_page' ),
			'page'       => $request->get_param( 'page' ),
		);

		// Remove null values.
		$args = array_filter(
			$args,
			function ( $value ) {
				return null !== $value;
			}
		);

		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get single student
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_student( $request ) {
		// Use the existing get_student tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Get_Student' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'Student retrieval tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool    = new WP_MCP_AI_Tool_Get_Student();
		$args    = array( 'student_id' => $request->get_param( 'id' ) );
		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create student
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_student( $request ) {
		// Use the existing create_student tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Student' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'Student creation tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool    = new WP_MCP_AI_Tool_Create_Student();
		$args    = $request->get_params();
		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update student
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_student( $request ) {
		// Use the existing update_student tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Update_Student' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'Student update tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool                = new WP_MCP_AI_Tool_Update_Student();
		$args                = $request->get_params();
		$args['student_id'] = $request->get_param( 'id' );
		$context             = array( 'user_id' => get_current_user_id() );
		$result              = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Delete student
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_student( $request ) {
		// Use the existing delete_student tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delete_Student' ) ) {
			return new WP_Error(
				'rest_tool_not_available',
				__( 'Student deletion tool is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$tool    = new WP_MCP_AI_Tool_Delete_Student();
		$args    = array( 'student_id' => $request->get_param( 'id' ) );
		$context = array( 'user_id' => get_current_user_id() );
		$result  = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get parameters for listing ECAs
	 *
	 * @return array
	 */
	protected function get_list_ecas_params() {
		return array(
			'eca_type'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
			),
			'day'              => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
			),
			'year_group'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'active', 'inactive', 'full', 'cancelled' ),
			),
			'is_paid'          => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'has_availability' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'search'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'             => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'         => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get parameters for creating ECA
	 *
	 * @return array
	 */
	protected function get_create_eca_params() {
		return array(
			'name'         => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'eca_code'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'eca_type'     => array(
				'type'              => 'string',
				'default'           => 'club',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
			),
			'day'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
			),
			'start_time'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'end_time'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'venue'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'year_groups'  => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'max_students' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
			'teachers'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'cost'         => array(
				'type'              => 'number',
				'minimum'           => 0,
				'sanitize_callback' => 'floatval',
			),
			'currency'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'GBP', 'USD', 'EUR', 'AED', 'INR', 'AUD', 'CAD', 'SGD', 'ZAR' ),
			),
			'term'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'Term 1', 'Term 2', 'Term 3', 'Yearly' ),
			),
		);
	}

	/**
	 * Get parameters for updating ECA
	 *
	 * @return array
	 */
	protected function get_update_eca_params() {
		$params = $this->get_create_eca_params();
		unset( $params['name']['required'] );
		return $params;
	}

	/**
	 * Get parameters for listing students
	 *
	 * @return array
	 */
	protected function get_list_students_params() {
		return array(
			'year_group' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'house'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page'   => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'       => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get parameters for creating student
	 *
	 * @return array
	 */
	protected function get_create_student_params() {
		return array(
			'first_name' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'  => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'year_group' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'house'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'email'      => array(
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
			),
			'isams_id'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get parameters for updating student
	 *
	 * @return array
	 */
	protected function get_update_student_params() {
		$params = $this->get_create_student_params();
		unset( $params['first_name']['required'] );
		unset( $params['last_name']['required'] );
		return $params;
	}
}
