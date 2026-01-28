<?php
/**
 * Test tool execute method signature compatibility
 *
 * @package WP_MCP_AI
 */

/**
 * Tests that all tools implement execute() with the correct signature.
 */
class Test_Tool_Execute_Signature extends WP_UnitTestCase {

	/**
	 * Test that client-side tools have correct execute signature.
	 */
	public function test_client_tools_execute_signature() {
		$client_tools = array(
			'WP_MCP_AI_Tool_Client_Analyze_Sentiment',
			'WP_MCP_AI_Tool_Client_Extract_Entities',
			'WP_MCP_AI_Tool_Client_Question_Answering',
			'WP_MCP_AI_Tool_Client_Semantic_Search',
			'WP_MCP_AI_Tool_Client_Summarize_Text',
			'WP_MCP_AI_Tool_Client_Translate_Text',
		);

		foreach ( $client_tools as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$reflection = new ReflectionClass( $class_name );
			$method     = $reflection->getMethod( 'execute' );

			// Verify method exists.
			$this->assertTrue( $method->isPublic(), "$class_name::execute() should be public" );

			// Get method parameters.
			$params = $method->getParameters();

			// Should have 2 parameters.
			$this->assertCount( 2, $params, "$class_name::execute() should have 2 parameters" );

			// First parameter should be array with default value.
			$this->assertEquals( 'arguments', $params[0]->getName(), "$class_name::execute() first parameter should be named 'arguments'" );
			$this->assertTrue( $params[0]->hasType(), "$class_name::execute() first parameter should have type hint" );
			$this->assertEquals( 'array', $params[0]->getType()->getName(), "$class_name::execute() first parameter should be array" );
			$this->assertTrue( $params[0]->isOptional(), "$class_name::execute() first parameter should be optional" );

			// Second parameter should be array with default value.
			$this->assertEquals( 'context', $params[1]->getName(), "$class_name::execute() second parameter should be named 'context'" );
			$this->assertTrue( $params[1]->hasType(), "$class_name::execute() second parameter should have type hint" );
			$this->assertEquals( 'array', $params[1]->getType()->getName(), "$class_name::execute() second parameter should be array" );
			$this->assertTrue( $params[1]->isOptional(), "$class_name::execute() second parameter should be optional" );
		}
	}

	/**
	 * Test that orchestration tools have correct execute signature.
	 */
	public function test_orchestration_tools_execute_signature() {
		$orchestration_tools = array(
			'WP_MCP_AI_Tool_Analyze_Loop_Health',
			'WP_MCP_AI_Tool_Calculate_Orchestration_Capacity',
			'WP_MCP_AI_Tool_Check_Exit_Conditions',
			'WP_MCP_AI_Tool_Create_Task_Plan',
			'WP_MCP_AI_Tool_Detect_Completion_Indicators',
			'WP_MCP_AI_Tool_Get_Session_Status',
			'WP_MCP_AI_Tool_Get_Task_Plan',
			'WP_MCP_AI_Tool_Manage_Autonomous_Session',
			'WP_MCP_AI_Tool_Update_Task_Plan',
		);

		foreach ( $orchestration_tools as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$reflection = new ReflectionClass( $class_name );
			$method     = $reflection->getMethod( 'execute' );

			// Verify method exists.
			$this->assertTrue( $method->isPublic(), "$class_name::execute() should be public" );

			// Get method parameters.
			$params = $method->getParameters();

			// Should have 2 parameters.
			$this->assertCount( 2, $params, "$class_name::execute() should have 2 parameters" );

			// First parameter should be array with default value.
			$this->assertEquals( 'arguments', $params[0]->getName(), "$class_name::execute() first parameter should be named 'arguments'" );
			$this->assertTrue( $params[0]->hasType(), "$class_name::execute() first parameter should have type hint" );
			$this->assertEquals( 'array', $params[0]->getType()->getName(), "$class_name::execute() first parameter should be array" );
			$this->assertTrue( $params[0]->isOptional(), "$class_name::execute() first parameter should be optional" );

			// Second parameter should be array with default value.
			$this->assertEquals( 'context', $params[1]->getName(), "$class_name::execute() second parameter should be named 'context'" );
			$this->assertTrue( $params[1]->hasType(), "$class_name::execute() second parameter should have type hint" );
			$this->assertEquals( 'array', $params[1]->getType()->getName(), "$class_name::execute() second parameter should be array" );
			$this->assertTrue( $params[1]->isOptional(), "$class_name::execute() second parameter should be optional" );
		}
	}

	/**
	 * Test that sitekit tool has correct execute signature.
	 */
	public function test_sitekit_tool_execute_signature() {
		$class_name = 'WP_MCP_AI_Tool_SiteKit_Analytics';

		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( "$class_name not available" );
		}

		$reflection = new ReflectionClass( $class_name );
		$method     = $reflection->getMethod( 'execute' );

		// Verify method exists.
		$this->assertTrue( $method->isPublic(), "$class_name::execute() should be public" );

		// Get method parameters.
		$params = $method->getParameters();

		// Should have 2 parameters.
		$this->assertCount( 2, $params, "$class_name::execute() should have 2 parameters" );

		// First parameter should be array with default value.
		$this->assertEquals( 'arguments', $params[0]->getName(), "$class_name::execute() first parameter should be named 'arguments'" );
		$this->assertTrue( $params[0]->hasType(), "$class_name::execute() first parameter should have type hint" );
		$this->assertEquals( 'array', $params[0]->getType()->getName(), "$class_name::execute() first parameter should be array" );
		$this->assertTrue( $params[0]->isOptional(), "$class_name::execute() first parameter should be optional" );

		// Second parameter should be array with default value.
		$this->assertEquals( 'context', $params[1]->getName(), "$class_name::execute() second parameter should be named 'context'" );
		$this->assertTrue( $params[1]->hasType(), "$class_name::execute() second parameter should have type hint" );
		$this->assertEquals( 'array', $params[1]->getType()->getName(), "$class_name::execute() second parameter should be array" );
		$this->assertTrue( $params[1]->isOptional(), "$class_name::execute() second parameter should be optional" );
	}
}
