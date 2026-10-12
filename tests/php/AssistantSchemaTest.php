<?php
/**
 * The answer schemas as providers check them strictly, and the answers back in the plugin's shape.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Assistant::strict() and ::loose().
 */
class AssistantSchemaTest extends Cookie_Dialog_TestCase {

	/**
	 * OpenAI's rules for structured outputs: every object closed, all properties required, arrays
	 * with items, no bare union with array.
	 *
	 * @param mixed  $schema A strict schema.
	 * @param string $path   Where in it.
	 */
	private function assertStrict( $schema, $path = '' ) {
		$schema = (array) $schema;
		if ( isset( $schema['anyOf'] ) ) {
			foreach ( $schema['anyOf'] as $i => $option ) {
				$this->assertStrict( $option, $path . '/anyOf' . $i );
			}
			return;
		}
		$types = (array) ( $schema['type'] ?? array() );
		$this->assertNotContains( 'array', count( $types ) > 1 ? $types : array(), $path . ': a union with array needs anyOf' );
		if ( in_array( 'object', $types, true ) ) {
			$properties = (array) $schema['properties'];
			$this->assertFalse( $schema['additionalProperties'], $path . ': open object' );
			$this->assertSame( array_keys( $properties ), $schema['required'], $path . ': not all required' );
			foreach ( $properties as $key => $property ) {
				$this->assertStrict( $property, $path . '/' . $key );
			}
		}
		if ( in_array( 'array', $types, true ) ) {
			$this->assertArrayHasKey( 'items', $schema, $path );
			$this->assertStrict( $schema['items'], $path . '/items' );
		}
	}

	public function test_every_schema_is_strict() {
		$this->configure( array( 'ga4' ) );
		foreach ( array( 'translate', 'shorten', 'classify', 'privacy', 'setup' ) as $job ) {
			$prompt = Cookie_Dialog_Assistant::job_prompt(
				$job,
				array(
					'lang' => 'it',
					'host' => 'example.com',
				)
			);
			$this->assertIsArray( $prompt, $job );
			$this->assertStrict( Cookie_Dialog_Assistant::strict( $prompt['schema'] ), $job );
		}
	}

	public function test_answers_come_back_as_maps_without_nulls() {
		$this->configure( array() );
		$schema = Cookie_Dialog_Assistant::job_prompt( 'translate', array( 'lang' => 'it' ) )['schema'];
		$answer = array(
			'texts'      => array(
				array(
					'key'   => 'title',
					'value' => 'Cookie su {site}',
				),
			),
			'categories' => array(
				array(
					'key'   => 'statistics',
					'value' => array(
						'name'        => 'Statistiche',
						'description' => null,
					),
				),
			),
		);
		$this->assertSame(
			array(
				'texts'      => array( 'title' => 'Cookie su {site}' ),
				'categories' => array( 'statistics' => array( 'name' => 'Statistiche' ) ),
			),
			Cookie_Dialog_Assistant::loose( $answer, $schema )
		);
	}
}
