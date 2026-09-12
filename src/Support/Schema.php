<?php
/**
 * Reusable JSON Schema fragments.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Draft-04 schema fragments shared by every ability.
 *
 * Note that `sanitize_callback` and `validate_callback` inside ability schemas are
 * ignored by the Abilities API, so abilities must sanitize inside `execute()`.
 *
 * @since 0.1.0
 */
class Schema {

	/**
	 * A positive object id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $description Optional. Field description. Default empty.
	 * @return array<string, mixed>
	 */
	public static function id( $description = '' ) {
		$schema = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		if ( '' !== $description ) {
			$schema['description'] = (string) $description;
		}

		return $schema;
	}

	/**
	 * An ISO 8601 timestamp in UTC.
	 *
	 * @since 0.1.0
	 *
	 * @param string $description Optional. Field description. Default empty.
	 * @return array<string, mixed>
	 */
	public static function iso_datetime( $description = '' ) {
		$schema = array(
			'type'   => 'string',
			'format' => 'date-time',
		);

		if ( '' !== $description ) {
			$schema['description'] = (string) $description;
		}

		return $schema;
	}

	/**
	 * A fingerprint string, e.g. `fp1:0123456789abcdef0123`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $description Optional. Field description. Default empty.
	 * @return array<string, mixed>
	 */
	public static function fingerprint( $description = '' ) {
		$schema = array(
			'type'    => 'string',
			'pattern' => '^fp1:[0-9a-f]{20}$',
		);

		if ( '' !== $description ) {
			$schema['description'] = (string) $description;
		}

		return $schema;
	}

	/**
	 * Property definitions describing a paginated result set.
	 *
	 * Merge the returned array into the `properties` of an output object schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function pagination() {
		return array(
			'page'        => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Current page number.', 'super-abilities' ),
			),
			'per_page'    => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Number of items per page.', 'super-abilities' ),
			),
			'total'       => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Total number of matching items.', 'super-abilities' ),
			),
			'total_pages' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Total number of pages.', 'super-abilities' ),
			),
		);
	}

	/**
	 * A value accepted either as a comma separated string or as an array of strings.
	 *
	 * @since 0.1.0
	 *
	 * @param string $description Optional. Field description. Default empty.
	 * @return array<string, mixed>
	 */
	public static function csv_or_array_of_strings( $description = '' ) {
		$schema = array(
			'type'  => array( 'string', 'array' ),
			'items' => array( 'type' => 'string' ),
		);

		if ( '' !== $description ) {
			$schema['description'] = (string) $description;
		}

		return $schema;
	}

	/**
	 * Splits a `csv_or_array_of_strings()` value into a clean list.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public static function to_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$list = array();

		foreach ( $value as $item ) {
			if ( is_array( $item ) || is_object( $item ) ) {
				continue;
			}

			$item = trim( (string) $item );

			if ( '' !== $item ) {
				$list[] = $item;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Builds an object schema.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $props      Property definitions.
	 * @param array<int, string>                  $required   Optional. Required property names. Default empty array.
	 * @param bool                                $additional Optional. Whether extra properties are allowed. Default false.
	 * @return array<string, mixed>
	 */
	public static function object( array $props, array $required = array(), $additional = false ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $props,
			'additionalProperties' => (bool) $additional,
		);

		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}

		return $schema;
	}
}
