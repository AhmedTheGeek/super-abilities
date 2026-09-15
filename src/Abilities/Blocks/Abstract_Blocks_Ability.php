<?php
/**
 * Shared base for the block abilities.
 *
 * @package SuperAbilities
 */

namespace SuperAbilities\Abilities\Blocks;

use SuperAbilities\Abilities\Abstract_Ability;
use SuperAbilities\Blocks\Block_Tree;
use SuperAbilities\Blocks\Post_Blocks;
use SuperAbilities\Support\Fingerprint;
use SuperAbilities\Support\Schema;
use WP_Error;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the plumbing every block ability repeats: loading the post, checking the
 * editor lock, saving through `wp_update_post()` and reporting the fingerprints.
 *
 * @since 0.2.0
 */
abstract class Abstract_Blocks_Ability extends Abstract_Ability {

	/**
	 * Owning module.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function module() {
		return 'blocks';
	}

	/**
	 * Plugin version this ability first shipped in.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function since() {
		return '0.2.0';
	}

	/**
	 * Required capabilities.
	 *
	 * Reading and editing a specific post is checked per object in `permission()`;
	 * `edit_posts` is the gate that keeps subscribers out of the whole module.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	public function capability() {
		return array( 'edit_posts' );
	}

	/**
	 * Schema for a block path.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description Field description.
	 * @return array<string, mixed>
	 */
	protected static function path_schema( $description ) {
		return array(
			'type'        => 'string',
			'pattern'     => Block_Tree::PATH_PATTERN,
			'description' => (string) $description,
		);
	}

	/**
	 * Schema for a caller supplied block object.
	 *
	 * `inner_blocks` is deliberately left as an untyped array: a block tree is
	 * recursive and draft-04 without `$ref` cannot describe that, so nested blocks
	 * are validated by {@see Block_Tree::from_input()} instead.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description Field description.
	 * @return array<string, mixed>
	 */
	protected static function block_schema( $description ) {
		return array(
			'type'                 => 'object',
			'description'          => (string) $description,
			'additionalProperties' => false,
			'required'             => array( 'name' ),
			'properties'           => array(
				'name'         => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Block name, for example core/paragraph. Use core/freeform for a classic HTML fragment.', 'super-abilities' ),
				),
				'attrs'        => array(
					'type'        => 'object',
					'description' => __( 'Block attributes, as they appear in the JSON of the block comment.', 'super-abilities' ),
				),
				'inner_html'   => array(
					'type'        => 'string',
					'description' => __( 'The block markup. For a block with children this is the wrapper, for example <div class="wp-block-group"></div>.', 'super-abilities' ),
				),
				'inner_blocks' => array(
					'type'        => 'array',
					'description' => __( 'Child blocks, each with the same shape as this object.', 'super-abilities' ),
				),
			),
		);
	}

	/**
	 * Schema for one entry of a flat block listing.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	protected static function flat_schema() {
		return Schema::object(
			array(
				'path'              => self::path_schema( __( 'Dotted, zero based path of the block.', 'super-abilities' ) ),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'Block name, core/freeform for a classic HTML fragment.', 'super-abilities' ),
				),
				'attrs'             => array(
					'type'        => 'object',
					'description' => __( 'The scalar block attributes, with long strings cut short.', 'super-abilities' ),
				),
				'inner_html_length' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Number of characters of markup this block contributes.', 'super-abilities' ),
				),
				'inner_blocks'      => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many direct children the block has.', 'super-abilities' ),
				),
				'fingerprint'       => Schema::fingerprint( __( 'Fingerprint of this block on its own.', 'super-abilities' ) ),
			),
			array( 'path', 'name', 'attrs', 'inner_html_length', 'inner_blocks', 'fingerprint' )
		);
	}

	/**
	 * Input properties every block write accepts.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function write_input_props() {
		return array(
			'post_id'              => Schema::id( __( 'Id of the post to edit.', 'super-abilities' ) ),
			'expected_fingerprint' => Schema::fingerprint( __( 'Tree fingerprint you last read. The write is refused with 409 when the content changed since.', 'super-abilities' ) ),
			'dry_run'              => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Report what would change and write nothing. Default false.', 'super-abilities' ),
			),
			'force'                => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Write even while another user holds the editor lock on the post. Default false.', 'super-abilities' ),
			),
		);
	}

	/**
	 * Output properties every block write reports.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function write_output_props() {
		return array(
			'post_id'            => Schema::id( __( 'Id of the post that was edited.', 'super-abilities' ) ),
			'fingerprint_before' => Schema::fingerprint( __( 'Tree fingerprint before the write.', 'super-abilities' ) ),
			'fingerprint_after'  => Schema::fingerprint( __( 'Tree fingerprint after the write, or the one a real write would produce during a dry run.', 'super-abilities' ) ),
			'changed'            => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the post content would differ from what it was.', 'super-abilities' ),
			),
			'dry_run'            => array(
				'type'        => 'boolean',
				'description' => __( 'Whether this call only reported the change.', 'super-abilities' ),
			),
			'revision_id'        => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Id of the revision the write created, 0 for a dry run or a write that changed nothing.', 'super-abilities' ),
			),
		);
	}

	/**
	 * The names of the output properties every block write reports.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, string>
	 */
	protected static function write_output_required() {
		return array( 'post_id', 'fingerprint_before', 'fingerprint_after', 'changed', 'dry_run', 'revision_id' );
	}

	/**
	 * Denies the call unless the current user may read the post.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	protected function can_read_post( array $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( $post_id < 1 ) {
			return $this->error( 'invalid_input', __( 'A post id is required.', 'super-abilities' ), 400 );
		}

		if ( null === Post_Blocks::find( $post_id ) ) {
			return $this->error(
				'not_found',
				__( 'No post with that id exists.', 'super-abilities' ),
				404,
				array( 'post_id' => $post_id )
			);
		}

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return $this->error(
				'forbidden',
				__( 'You are not allowed to read that post.', 'super-abilities' ),
				403,
				array( 'post_id' => $post_id )
			);
		}

		return true;
	}

	/**
	 * Denies the call unless the current user may edit the post.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|WP_Error
	 */
	protected function can_edit_post( array $input ) {
		$allowed = $this->can_read_post( $input );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$post_id = (int) $input['post_id'];

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error(
				'forbidden',
				__( 'You are not allowed to edit that post.', 'super-abilities' ),
				403,
				array( 'post_id' => $post_id )
			);
		}

		return true;
	}

	/**
	 * Loads the post an ability was called for.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return WP_Post|WP_Error
	 */
	protected function open( array $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = Post_Blocks::find( $post_id );

		if ( null === $post ) {
			return $this->error(
				'not_found',
				__( 'No post with that id exists.', 'super-abilities' ),
				404,
				array( 'post_id' => $post_id )
			);
		}

		return $post;
	}

	/**
	 * Refuses a write while another user has the post open in an editor.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post              $post  Post object.
	 * @param array<string, mixed> $input Validated input.
	 * @return WP_Error|null Null when the write may proceed.
	 */
	protected function lock_guard( WP_Post $post, array $input ) {
		if ( ! empty( $input['force'] ) ) {
			return null;
		}

		$locked_by = Post_Blocks::locked_by( (int) $post->ID );

		if ( $locked_by < 1 ) {
			return null;
		}

		$user  = get_userdata( $locked_by );
		$login = $user instanceof WP_User ? $user->user_login : (string) $locked_by;

		return $this->error(
			'locked',
			sprintf(
				/* translators: %s: User login of the user editing the post. */
				__( 'The user "%s" has this post open in an editor. Pass force to write anyway and overwrite whatever they have not saved yet.', 'super-abilities' ),
				$login
			),
			423,
			array(
				'post_id'   => (int) $post->ID,
				'locked_by' => $locked_by,
			)
		);
	}

	/**
	 * Refuses a write whose `expected_fingerprint` no longer matches the tree.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @param Block_Tree           $tree  The tree as it is now.
	 * @return WP_Error|null Null when the write may proceed.
	 */
	protected function tree_guard( array $input, Block_Tree $tree ) {
		return $this->guard_fingerprint( $input, $tree->fingerprint() );
	}

	/**
	 * Saves a rewritten tree and builds the shared part of a write response.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post              $post           Post object.
	 * @param Block_Tree           $tree           The rewritten tree.
	 * @param string               $content_before The post content before the edit.
	 * @param array<string, mixed> $input          Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function commit( WP_Post $post, Block_Tree $tree, $content_before, array $input ) {
		$content_before = (string) $content_before;
		$content_after  = $tree->serialize();
		$dry_run        = ! empty( $input['dry_run'] );

		$this->note_object( 'post', (int) $post->ID );

		$result = array(
			'post_id'            => (int) $post->ID,
			'fingerprint_before' => Fingerprint::of_string( $content_before ),
			'fingerprint_after'  => Fingerprint::of_string( $content_after ),
			'changed'            => $content_after !== $content_before,
			'dry_run'            => $dry_run,
			'revision_id'        => 0,
		);

		if ( $dry_run || ! $result['changed'] ) {
			return $result;
		}

		$saved = Post_Blocks::save( $post, $content_after );

		if ( is_wp_error( $saved ) ) {
			return $this->error(
				'write_failed',
				$saved->get_error_message(),
				500,
				array( 'post_id' => (int) $post->ID )
			);
		}

		// Report the fingerprint of what WordPress actually stored: a caller without
		// `unfiltered_html` has their markup filtered on the way in, and the next write
		// has to be able to match the fingerprint this one hands back.
		$stored = Post_Blocks::find( (int) $post->ID );

		if ( $stored instanceof WP_Post ) {
			$result['fingerprint_after'] = Fingerprint::of_string( (string) $stored->post_content );
		}

		$result['revision_id'] = Post_Blocks::latest_revision( (int) $post->ID );

		return $result;
	}

	/**
	 * A normalized, validated path from the input, or an empty string for the root.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @param string               $key   Optional. Input key holding the path. Default `path`.
	 * @return string|WP_Error
	 */
	protected function read_path( array $input, $key = 'path' ) {
		$path = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';

		if ( '' === $path ) {
			return '';
		}

		if ( ! Block_Tree::is_path( $path ) ) {
			return $this->error(
				'invalid_input',
				sprintf(
					/* translators: %s: Input field name. */
					__( 'The "%s" value is not a block path. A path is zero based indexes joined with dots, for example 2.1.', 'super-abilities' ),
					(string) $key
				),
				400
			);
		}

		return $path;
	}
}
