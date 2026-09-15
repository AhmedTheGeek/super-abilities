<?php
/**
 * Creates and updates user block patterns.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Design;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Design\Block_Markup;
use SuperAbilities\Design\Patterns;
use SuperAbilities\Design\Revisions;
use SuperAbilities\Design\Templates;
use SuperAbilities\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a `wp_block` post: the only kind of pattern that is editable.
 *
 * @since 0.2.0
 */
class Pattern_Write extends Abstract_Ability {

	/**
	 * Ability slug.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug() {
		return 'pattern-write';
	}

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'design';
	}

	/**
	 * Ability label.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Write a block pattern', 'super-abilities' );
	}

	/**
	 * Ability description.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Creates or updates a user block pattern, the wp_block post type. Send an id to update an existing pattern or omit it to create one, in which case a title and content are required. The sync setting decides how the pattern behaves when inserted: synced keeps one shared instance that updates everywhere, unsynced inserts a copy, and it is stored the way core stores it, as the wp_pattern_sync_status meta. The markup must parse as blocks and survive a re-serialize without losing any, and markup containing a script tag or a PHP opening tag is refused. Patterns registered by core, a theme or a plugin live in code and cannot be written here.', 'super-abilities' );
	}

	/**
	 * Ability annotations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, bool>
	 */
	public function annotations() {
		return self::write();
	}

	/**
	 * Required capabilities.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'edit_posts' );
	}

	/**
	 * Plugin version this ability shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * Input schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema() {
		return Schema::object(
			array(
				'id'                   => Schema::id( __( 'Post id of the pattern to update. Omit to create a new one.', 'super-abilities' ) ),
				'title'                => array(
					'type'        => 'string',
					'description' => __( 'Pattern title. Required when creating.', 'super-abilities' ),
				),
				'content'              => array(
					'type'        => 'string',
					'description' => __( 'The complete block markup of the pattern. Required when creating; replaces the markup when updating.', 'super-abilities' ),
				),
				'sync'                 => array(
					'type'        => 'string',
					'enum'        => array( 'synced', 'unsynced' ),
					'description' => __( 'synced keeps one shared instance, unsynced inserts a copy. Omit to keep the current setting, or synced when creating.', 'super-abilities' ),
				),
				'expected_fingerprint' => Schema::fingerprint( __( 'Fingerprint from pattern-read. The write is refused with 409 when the content changed since. Only meaningful with an id.', 'super-abilities' ) ),
				'dry_run'              => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Validate the markup and report what would happen without writing anything.', 'super-abilities' ),
				),
			)
		);
	}

	/**
	 * Output schema.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema() {
		return Schema::object(
			array(
				'id'          => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Post id of the pattern. Null on a dry run that would have created one.', 'super-abilities' ),
				),
				'title'       => array( 'type' => 'string' ),
				'dry_run'     => array( 'type' => 'boolean' ),
				'created'     => array(
					'type'        => 'boolean',
					'description' => __( 'True when this call created a new pattern.', 'super-abilities' ),
				),
				'changed'     => array(
					'type'        => 'boolean',
					'description' => __( 'False when the stored title, markup and sync setting already matched.', 'super-abilities' ),
				),
				'sync'        => array(
					'type'        => 'string',
					'description' => __( 'The sync setting after the write.', 'super-abilities' ),
				),
				'block_count' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'blocks'      => Templates::blocks_schema(),
				'revision_id' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Newest revision of the pattern post, when the post type keeps revisions.', 'super-abilities' ),
				),
				'fingerprint' => Schema::fingerprint( __( 'Fingerprint of the stored content after the write.', 'super-abilities' ) ),
			),
			array( 'id', 'title', 'dry_run', 'created', 'changed', 'sync', 'block_count', 'blocks', 'revision_id', 'fingerprint' )
		);
	}

	/**
	 * Per-object permission check.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool|\WP_Error
	 */
	public function permission( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;

		if ( $id > 0 ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return $this->error(
					'forbidden',
					__( 'You are not allowed to edit that pattern.', 'super-abilities' ),
					403
				);
			}

			return true;
		}

		if ( ! current_user_can( 'publish_posts' ) ) {
			return $this->error(
				'forbidden',
				__( 'You are not allowed to create patterns.', 'super-abilities' ),
				403
			);
		}

		return true;
	}

	/**
	 * Writes the pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$id      = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$dry_run = ! empty( $input['dry_run'] );
		$sync    = '';

		if ( isset( $input['sync'] ) ) {
			$sync = 'unsynced' === $input['sync'] ? 'unsynced' : 'synced';
		}

		if ( $id > 0 ) {
			return $this->update( $id, $input, $sync, $dry_run );
		}

		return $this->create( $input, $sync, $dry_run );
	}

	/**
	 * Creates a new pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param string               $sync    Requested sync setting, or an empty string.
	 * @param bool                 $dry_run Whether to change nothing.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function create( array $input, $sync, $dry_run ) {
		$title   = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';

		if ( '' === $title ) {
			return $this->error(
				'invalid_input',
				__( 'A title is required when creating a pattern.', 'super-abilities' ),
				400
			);
		}

		if ( ! array_key_exists( 'content', $input ) ) {
			return $this->error(
				'invalid_input',
				__( 'Content is required when creating a pattern.', 'super-abilities' ),
				400
			);
		}

		$summary = Block_Markup::check( $content );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$sync = '' === $sync ? 'synced' : $sync;

		$result = array(
			'id'          => null,
			'title'       => $title,
			'dry_run'     => $dry_run,
			'created'     => true,
			'changed'     => true,
			'sync'        => $sync,
			'block_count' => (int) $summary['block_count'],
			'blocks'      => $summary['blocks'],
			'revision_id' => null,
			'fingerprint' => Block_Markup::fingerprint( $content ),
		);

		if ( $dry_run ) {
			return $result;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => Patterns::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		Patterns::set_sync_status( $post_id, $sync );

		$this->note_object( 'post', $post_id );

		$result['id']          = $post_id;
		$result['revision_id'] = Revisions::latest_id( $post_id );

		return $result;
	}

	/**
	 * Updates an existing pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $id      Pattern post id.
	 * @param array<string, mixed> $input   Validated input.
	 * @param string               $sync    Requested sync setting, or an empty string.
	 * @param bool                 $dry_run Whether to change nothing.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function update( $id, array $input, $sync, $dry_run ) {
		$post = Patterns::post( $id );

		if ( null === $post ) {
			return $this->error(
				'not_found',
				__( 'No user pattern with that id exists. Patterns registered in code cannot be edited.', 'super-abilities' ),
				404,
				array( 'id' => $id )
			);
		}

		$stale = $this->guard_fingerprint( $input, Block_Markup::fingerprint( (string) $post->post_content ) );

		if ( null !== $stale ) {
			return $stale;
		}

		$title   = array_key_exists( 'title', $input ) ? trim( (string) $input['title'] ) : (string) $post->post_title;
		$content = array_key_exists( 'content', $input ) ? (string) $input['content'] : (string) $post->post_content;
		$current = Patterns::sync_status( $id );
		$sync    = '' === $sync ? $current : $sync;

		if ( '' === $title ) {
			return $this->error(
				'invalid_input',
				__( 'A pattern title may not be empty.', 'super-abilities' ),
				400
			);
		}

		$summary = Block_Markup::check( $content );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$post_changed = $title !== (string) $post->post_title || $content !== (string) $post->post_content;
		$sync_changed = $sync !== $current;

		$result = array(
			'id'          => $id,
			'title'       => $title,
			'dry_run'     => $dry_run,
			'created'     => false,
			'changed'     => $post_changed || $sync_changed,
			'sync'        => $sync,
			'block_count' => (int) $summary['block_count'],
			'blocks'      => $summary['blocks'],
			'revision_id' => null,
			'fingerprint' => Block_Markup::fingerprint( $content ),
		);

		if ( $dry_run ) {
			return $result;
		}

		if ( $post_changed ) {
			$updated = wp_update_post(
				array(
					'ID'           => $id,
					'post_title'   => $title,
					'post_content' => $content,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( $sync_changed ) {
			Patterns::set_sync_status( $id, $sync );
		}

		$this->note_object( 'post', $id );

		$stored = Patterns::post( $id );

		$result['revision_id'] = Revisions::latest_id( $id );
		$result['fingerprint'] = null === $stored ? $result['fingerprint'] : Block_Markup::fingerprint( (string) $stored->post_content );

		return $result;
	}
}
